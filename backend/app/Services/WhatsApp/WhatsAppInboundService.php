<?php

namespace App\Services\WhatsApp;

use App\Jobs\NotifyStaffOfHandoff;
use App\Models\Appointment;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Quote;
use App\Models\WhatsAppMessage;
use App\Services\AI\Contracts\SpeechToText;
use App\Services\AppointmentService;
use App\Services\BookingDeposits;
use App\Services\ChatMenu;
use App\Services\CheckoutFlow;
use App\Services\ConversationService;
use App\Services\OrderConfirmation;
use App\Services\RatingFlow;
use App\Services\OrderService;
use App\Services\QuoteService;
use App\Services\SystemAlertService;
use App\Support\BusinessKind;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationStatus;
use App\Support\Conversation\RecommendedAction;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\Money;
use App\Support\OrderMessage;
use App\Support\Quantity;
use App\Support\ShopSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles inbound WhatsApp webhook payloads: idempotency, tenant binding, AI reply.
 */
class WhatsAppInboundService
{
    public function __construct(
        protected ConversationService $conversations,
        protected WhatsAppService $whatsapp,
        protected CurrentTenant $tenant,
        protected DriverBotService $drivers,
        protected ChatMenu $chatMenu,
        protected CheckoutFlow $checkoutFlow,
        protected SpeechToText $speech,
        protected StaffMessenger $staff,
    ) {}

    /** WhatsApp profile names in the payload being handled, by number (digits only). */
    protected array $profileNames = [];

    /** Customers send these to ask about something (a product, a document, "do you have this?"). */
    public const PHOTO_TYPES = ['image', 'video', 'document'];

    /** Message types the assistant can't read: answered with a polite note and the menu. */
    public const UNREADABLE_TYPES = ['image', 'video', 'sticker', 'document', 'contacts', 'unsupported'];

    /**
     * @param  array<string,mixed>  $body  Raw Meta webhook JSON
     */
    public function handle(array $body): void
    {
        // Meta may batch several entries/changes (and several merchants' numbers) into one POST.
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? null;
                if (is_array($value)) {
                    $this->handleChange($value);
                }
            }
        }
    }

    /**
     * Meta accepts a message and tells us only afterwards, in a status report, that it could not be
     * delivered (the customer's 24 hours were over, a blocked number, a template problem). Each failure
     * becomes an owner alert, so a shop never believes a customer was told when they were not.
     *
     * @param  array<string,mixed>  $value
     */
    protected function reportFailedDeliveries(array $value): void
    {
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $merchant = $phoneNumberId !== '' ? MerchantWhatsAppCredentials::findMerchantByPhoneId($phoneNumberId) : null;
        $sender = $merchant ? $merchant->name : 'NeoTalab\'s own number';

        foreach ($value['statuses'] as $status) {
            if (! is_array($status) || ($status['status'] ?? null) !== 'failed') {
                continue;
            }
            $error = is_array($status['errors'][0] ?? null) ? $status['errors'][0] : [];
            $to = preg_replace('/\D+/', '', (string) ($status['recipient_id'] ?? ''));
            $reason = trim(strip_tags((string) ($error['title'] ?? $error['message'] ?? 'unknown reason')));

            app(SystemAlertService::class)->record('warning', sprintf(
                'WhatsApp could not deliver a message from %s%s: %s%s.',
                $sender,
                $to !== '' ? " to +{$to}" : '',
                mb_substr($reason, 0, 160),
                isset($error['code']) ? ' (Meta code '.(int) $error['code'].')' : '',
            ), ['merchant_id' => $merchant?->id]);
        }
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function handleChange(array $value): void
    {
        // A customer called the shop number (only sent when calling is on for the number): nobody
        // answers calls here, so they are told to order by message instead of being left waiting.
        if (is_array($value['calls'] ?? null)) {
            $this->answerCalls($value);

            return;
        }

        if (is_array($value['statuses'] ?? null)) {
            $this->reportFailedDeliveries($value);
        }

        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
        // Coexistence: messages the merchant typed in the WhatsApp Business app on the shop number.
        $echoes = is_array($value['message_echoes'] ?? null) ? $value['message_echoes'] : [];
        if ($messages === [] && $echoes === []) {
            return;
        }

        $this->profileNames = [];
        foreach ((array) ($value['contacts'] ?? []) as $contact) {
            $name = is_array($contact) ? trim(strip_tags((string) ($contact['profile']['name'] ?? ''))) : '';
            if ($name !== '' && ($id = preg_replace('/\D+/', '', (string) ($contact['wa_id'] ?? '')))) {
                $this->profileNames[$id] = mb_substr($name, 0, 120);
            }
        }

        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $merchant = MerchantWhatsAppCredentials::findMerchantByPhoneId($phoneNumberId);

        if (! $merchant) {
            // NeoTalab's own number: shop owners answering a welcome or reminder. They are read in
            // the WhatsApp Business app on that number; nothing for the bot to do.
            if ($phoneNumberId !== '' && $phoneNumberId === (string) ((PlatformSetting::singleton()->settings ?? [])['wa_phone_id'] ?? '')) {
                return;
            }
            // A suspended shop (unpaid trial) keeps its number: its messages are ignored on purpose,
            // so this is information, not a warning the owner has to act on.
            if ($phoneNumberId !== '' && Merchant::query()->where('status', '!=', 'active')->where('settings->bot->phone_id', $phoneNumberId)->exists()) {
                Log::info('WhatsApp inbound: message for a shop that is not active, ignored on purpose', ['phone_number_id' => $phoneNumberId]);

                return;
            }
            Log::warning('WhatsApp inbound: unknown phone_number_id', ['phone_number_id' => $phoneNumberId]);

            return;
        }

        $this->tenant->set($merchant);

        try {
            foreach ($echoes as $echo) {
                if (is_array($echo)) {
                    $this->processMerchantEcho($merchant, $echo);
                }
            }
            foreach ($messages as $msg) {
                if (is_array($msg)) {
                    $this->processMessage($merchant, $msg, $phoneNumberId);
                }
            }
        } finally {
            $this->tenant->forget();
        }
    }

    /**
     * @param  array<string,mixed>  $msg
     */
    protected function processMessage(Merchant $merchant, array $msg, string $phoneNumberId): void
    {
        $waMessageId = (string) ($msg['id'] ?? '');
        $fromPhone = '+'.ltrim((string) ($msg['from'] ?? ''), '+');

        if ($waMessageId === '' || $fromPhone === '+') {
            return;
        }
        // NeoTalab's own number writing to the shop (the nightly report can go to the shop's number): not a customer.
        $platformNumber = MerchantWhatsAppCredentials::platformNumberDigits();
        if ($platformNumber !== '' && ltrim($fromPhone, '+') === $platformNumber) {
            return;
        }

        // The unique (merchant_id, wa_message_id) index is the idempotency guard; Meta retries
        // deliveries, and two workers may race on the same message.
        try {
            $record = WhatsAppMessage::create([
                'merchant_id' => $merchant->id,
                'wa_message_id' => $waMessageId,
                'direction' => 'inbound',
                'from_number' => $fromPhone,
                'to_number' => $phoneNumberId,
                'message_type' => $msg['type'] ?? 'text',
                'payload' => $msg,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->answerInterruptedMessage($merchant, $waMessageId, $fromPhone);

            return;
        }

        // One message per chat at a time: with several queue workers, two quick messages from the
        // same customer ("2 burgers", "and a coke") would otherwise update the same cart at once.
        $lock = Cache::lock("wa-chat:{$merchant->id}:{$fromPhone}", 120);
        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            Log::warning('WhatsApp inbound: chat still busy, handling the message anyway', ['merchant_id' => $merchant->id]);
            $lock = null;
        }

        try {
            $this->handleMessage($merchant, $msg, $phoneNumberId, $fromPhone, $record);
            $record->update(['payload' => array_merge($record->payload ?? $msg, ['handled_at' => now()->toIso8601String()])]);
        } finally {
            $lock?->release();
        }
    }

    /**
     * True when this number sent the shop more messages than any real customer would; those get
     * no AI reply, so a spammer cannot run up the AI bill. Logged once per number and day.
     */
    protected function isFlooding(Merchant $merchant, string $fromPhone): bool
    {
        $key = "wa-flood:{$merchant->id}:{$fromPhone}";
        $burst = RateLimiter::attempt($key.':10m', (int) config('whatsapp.customer_limit_per_10_minutes'), fn () => true, 600);
        $daily = RateLimiter::attempt($key.':day', (int) config('whatsapp.customer_limit_per_day'), fn () => true, 86400);

        if ($burst && $daily) {
            return false;
        }

        if (Cache::add($key.':logged', true, 86400)) {
            Log::warning('WhatsApp inbound: a number is flooding a shop, its messages get no reply for now', ['merchant_id' => $merchant->id]);
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $msg
     */
    protected function handleMessage(Merchant $merchant, array $msg, string $phoneNumberId, string $fromPhone, WhatsAppMessage $record): void
    {
        $waMessageId = $record->wa_message_id;

        if ($driver = DriverBotService::findDriver($fromPhone)) {
            $this->drivers->handle($merchant, $driver, $msg);

            return;
        }

        // A shop with no staff is run by its owner: their number manages bookings here (and only bookings).
        if ($owner = $this->drivers->ownerForBookings($merchant, $fromPhone, $msg)) {
            $this->drivers->handle($merchant, $owner, $msg);

            return;
        }

        $customer = Customer::query()->where('phone', $fromPhone)->first();
        if ($customer?->is_blocked) {
            return;
        }

        // The merchant is handling this chat from the WhatsApp Business app, the bot stays quiet,
        // but the customer still gets a short note (at most one every 10 minutes per chat).
        if ($customer?->bot_paused_until?->isFuture()) {
            if (Cache::add("wa-hold:{$customer->id}", true, 600)) {
                $this->reply($merchant, $fromPhone, $this->text('team_answering', $customer->locale ?? $merchant->locale ?? 'en', $merchant->name));
            }

            return;
        }

        $locale = $customer?->locale ?? $merchant->locale ?? 'en';
        $mode = ($merchant->settings ?? [])['mode'] ?? 'auto';

        // Bot switched off: the merchant answers every chat personally.
        if ($mode === 'manual') {
            return;
        }

        if ($this->isFlooding($merchant, $fromPhone)) {
            return;
        }

        $type = (string) ($msg['type'] ?? '');
        $text = $this->extractText($msg);
        $location = $this->extractLocation($msg);
        $isVoice = $type === 'audio';
        $unreadable = in_array($type, self::UNREADABLE_TYPES, true);

        if ($customer && $text !== null && $this->handleRating($merchant, $customer, $fromPhone, $text, $locale)) {
            return;
        }

        // Reactions, edits and other system events need no answer.
        if (($text === null || trim($text) === '') && ! $location && ! $isVoice && ! $unreadable) {
            return;
        }

        if ($mode === 'closed') {
            $this->reply($merchant, $fromPhone, $this->text('closed', $locale, $merchant->name));

            return;
        }

        // A photo or file is usually a question the bot can't read: hand the chat to the staff
        // (the owner sees the photo in the WhatsApp Business app) when someone can be alerted.
        if (in_array($type, self::PHOTO_TYPES, true) && $this->staff->anyReachable($merchant, ShopTemplateService::STAFF_ALERT)) {
            $customer ??= Customer::query()->firstOrCreate(['phone' => $fromPhone], ['locale' => $locale]);
            $customer->forceFill(['bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins())])->save();
            NotifyStaffOfHandoff::dispatch($customer->id, 'photo');
            $this->reply($merchant, $fromPhone, $this->text('photo_received', $locale, $merchant->name));

            return;
        }

        // Stickers, and photos when nobody can be alerted: never leave the customer without an answer.
        if ($unreadable) {
            $this->replyWithMenu($merchant, $fromPhone, $locale, $this->text('media_unsupported', $locale, $merchant->name));

            return;
        }

        // Voice notes are transcribed and then handled exactly like a typed message.
        if ($isVoice) {
            $text = $this->transcribeVoice($merchant, $msg);
            if ($text === null) {
                $this->replyWithMenu($merchant, $fromPhone, $locale, $this->text('voice_failed', $locale, $merchant->name));

                return;
            }
            $record->update(['payload' => array_merge($msg, ['transcript' => $text])]);
        }

        // The message is already recorded, so a job retry would skip it, failures are handled
        // here: log, and tell the customer instead of leaving them without an answer.
        try {
            $conversation = $this->conversations->startOrResume([
                'phone' => $fromPhone,
                'locale' => $locale,
                'name' => $this->profileNames[ltrim($fromPhone, '+')] ?? null,
            ], 'whatsapp');

            $buttonId = $this->extractButtonId($msg);

            // Great / Okay / Bad under a rating question.
            if ($buttonId !== null && preg_match('/^rt:([^:]+):(order|service|delivery):([135])$/', $buttonId, $rm)) {
                $this->answerRating($merchant, $fromPhone, $rm[1], $rm[2], (int) $rm[3], $locale);

                return;
            }

            // Yes / No under "cancel order #N?".
            if ($buttonId !== null && str_starts_with($buttonId, 'cx:')) {
                [, $answer, $orderId] = array_pad(explode(':', $buttonId, 3), 3, '');
                $text = $this->conversations->resolveCancelButton($conversation, $orderId, $answer === 'yes');
                if ($text !== '') {
                    $this->reply($merchant, $fromPhone, $text);
                }

                return;
            }

            // "I paid" under the Whish payment instructions: the shop checks its Whish account next.
            if ($buttonId !== null && preg_match('/^wh:paid:(.+)$/', $buttonId, $wm)) {
                $order = Order::query()->where('merchant_id', $merchant->id)
                    ->where('customer_id', $conversation->customer_id)->whereKey($wm[1])->first();
                if ($order) {
                    app(OrderConfirmation::class)->customerPaid($merchant, $order);
                }

                return;
            }

            // "I paid" under a booking's deposit instructions: the shop checks its Whish account next.
            if ($buttonId !== null && preg_match('/^wh:dpaid:(.+)$/', $buttonId, $dm)) {
                $appointment = Appointment::query()->where('merchant_id', $merchant->id)
                    ->where('customer_id', $conversation->customer_id)->whereKey($dm[1])->first();
                if ($appointment && $appointment->status === 'pending_deposit') {
                    app(BookingDeposits::class)->customerPaid($merchant, $appointment);
                } elseif ($appointment && $appointment->status !== 'deposit_check') {
                    $this->reply($merchant, $fromPhone, __('whatsapp.appointment.deposit_already', [], $locale));
                }

                return;
            }

            // Accept / No thanks under a repair quote.
            if ($buttonId !== null && preg_match('/^qt:(yes|no):(.+)$/', $buttonId, $qm)) {
                [$text, $order] = app(QuoteService::class)->answer($merchant, $conversation->customer, $qm[2], $qm[1] === 'yes');
                $this->reply($merchant, $fromPhone, $text);

                return;
            }

            // "I need a quote" (repair shops): the request goes to the staff, the customer waits for the price.
            if ($buttonId === null && $text !== null && $this->wantsQuote($merchant, $text)) {
                $this->reply($merchant, $fromPhone, app(QuoteService::class)->request($merchant, $conversation, $text));

                return;
            }

            // The menu inside the chat (lists and buttons) and "Where's my order?".
            $step = $buttonId === 'co:start:'
                ? $this->checkoutFlow->start($merchant, $conversation, $locale)
                : ($buttonId !== null ? $this->chatMenu->handle($merchant, $conversation, $locale, $buttonId) : null);

            // "2" typed when the menu asks "how many?" counts like tapping 2.
            $step ??= $buttonId === null ? $this->chatMenu->typedQuantity($merchant, $conversation, $locale, $text) : null;

            // Checkout runs on buttons, without the AI: Delivery/Pickup → address → payment → receipt.
            $step ??= $this->checkoutFlow->handle($merchant, $conversation, $locale, $buttonId, $text, $location);

            if ($step !== null) {
                $this->send($merchant, $fromPhone, $step);

                return;
            }

            // A cancel request about an open order gets the Yes / No question directly, without the assistant.
            if ($text !== null && ($order = $this->conversations->openOrderToCancel($conversation, $text))) {
                $this->send($merchant, $fromPhone, $this->cancelQuestion($merchant, $order->id, __('whatsapp.checkout.cancel_confirm', ['number' => $order->number], $locale), $locale));

                return;
            }

            $result = $this->conversations->processTurn($conversation, (string) $text, $location);
            $reply = trim($result['reply']);
            // The assistant has just recognised the customer's language: the buttons that follow use it too.
            $locale = $result['conversation']->customer->locale ?? $locale;

            // "Cancel order #N?" with Yes / No buttons: the customer's tap decides, not the assistant.
            if ($result['cancel_confirm'] !== null) {
                $this->send($merchant, $fromPhone, $this->cancelQuestion($merchant, $result['cancel_confirm'], $reply, $locale));

                return;
            }

            // A booking that holds its time until the deposit is paid: the instructions come with the "I paid" button.
            if ($result['deposit_appointment'] !== null) {
                $this->send($merchant, $fromPhone, ['type' => 'interactive', 'interactive' => [
                    'type' => 'button', 'body' => ['text' => mb_substr($reply, 0, 1024)],
                    'action' => ['buttons' => [['type' => 'reply', 'reply' => [
                        'id' => "wh:dpaid:{$result['deposit_appointment']}",
                        'title' => mb_substr(__('whatsapp.checkout.btn_whish_paid', [], $locale), 0, 20),
                    ]]]],
                ]]);

                return;
            }

            // Typed "checkout" without a delivery or pickup choice: the same buttons as the menu checkout.
            if ($result['fulfillment_choice']) {
                $this->send($merchant, $fromPhone, $this->checkoutFlow->start($merchant, $result['conversation'], $locale, $result['output']->checkout));

                return;
            }

            // The assistant may say "added" for an item the cart refused (a size still to choose, sold
            // out): show that item's next step from the menu, or say it is unavailable, instead.
            if (($refused = $this->refusedItem($merchant, $result, $locale)) !== null) {
                $this->send($merchant, $fromPhone, $refused);

                return;
            }

            // The assistant says the customer is done choosing: hand over to the button checkout.
            if ($result['output']->recommendedAction === RecommendedAction::Checkout) {
                if ($reply !== '') {
                    $this->reply($merchant, $fromPhone, $reply);
                }
                $this->send($merchant, $fromPhone, $this->checkoutFlow->start($merchant, $result['conversation'], $locale, $result['output']->checkout));

                return;
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp inbound: AI turn failed', [
                'merchant_id' => $merchant->id,
                'wa_message_id' => $waMessageId,
                'error' => $e->getMessage(),
            ]);
            $this->reply($merchant, $fromPhone, $this->text('error', $locale, $merchant->name));

            return;
        }

        if ($reply !== '' && ($result['services_link'] ?? false) && ($block = $this->servicesBlock($merchant, $locale)) !== null) {
            $reply .= "\n\n".$block;
        }

        if ($reply !== '' && ($result['menu_link'] ?? false)) {
            // The greeting (or "show me the menu") comes with the menu list right in the chat.
            $this->send($merchant, $fromPhone, $this->chatMenu->categories($merchant, $locale, 1, $reply));
        } elseif ($reply !== '') {
            $this->reply($merchant, $fromPhone, $reply);
        } elseif ($result['output']->recommendedAction !== RecommendedAction::CancelOrder) {
            // The assistant had nothing to say (cancelling is answered by the status message).
            $this->replyWithMenu($merchant, $fromPhone, $locale, $this->text('not_understood', $locale, $merchant->name));
        }
    }

    /**
     * The first item the customer asked for that the cart refused, as the message to send.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    protected function refusedItem(Merchant $merchant, array $result, string $locale): ?array
    {
        $productId = collect($result['cart_result']['rejected'] ?? [])
            ->first(fn ($r) => ($r['action'] ?? null) === 'add' && ! empty($r['product_id']))['product_id'] ?? null;
        $product = $productId ? Product::query()->find($productId) : null;
        if (! $product) {
            return null;
        }

        if ($product->isOrderable() && ! $product->duration_mins) {
            return $this->chatMenu->handle($merchant, $result['conversation'], $locale, "mn:item:{$product->id}");
        }

        return ['type' => 'text', 'text' => __('whatsapp.checkout.unavailable', ['item' => $product->name], $locale)];
    }

    /** The shop's services with minutes and prices, and how to book; null when there are none. */
    protected function servicesBlock(Merchant $merchant, string $locale): ?string
    {
        $services = app(AppointmentService::class)->services();
        if ($services->isEmpty()) {
            return null;
        }

        $lines = $services->map(fn (Product $p) => '• '.$p->name.' — '.$p->duration_mins.' min — '.Money::format((float) $p->price, $merchant->currency))->implode("\n");

        return __('whatsapp.appointment.services_intro', [], $locale)."\n".$lines."\n\n".__('whatsapp.appointment.services_book', [], $locale);
    }

    /** "Cancel order #N?" with the two buttons, the buttons carry the order id. */
    protected function cancelQuestion(Merchant $merchant, string $orderId, string $body, string $locale): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => "cx:yes:{$orderId}", 'title' => mb_substr(__('whatsapp.checkout.btn_cancel_yes', [], $locale), 0, 20)]],
                ['type' => 'reply', 'reply' => ['id' => "cx:no:{$orderId}", 'title' => mb_substr(__('whatsapp.checkout.btn_cancel_no', [], $locale), 0, 20)]],
            ]],
        ]];
    }

    public const PAUSE_COMMANDS = ['⏸', '⏸️', '#pause', '#stop'];

    public const RESUME_COMMANDS = ['▶', '▶️', '🤖', '#bot', '#resume'];

    /** Typed by the shop in a customer's chat: accept / refuse the order waiting for the shop. */
    public const ACCEPT_COMMANDS = ['✅', '✔', '✔️', '☑', '☑️', '👍'];

    public const REFUSE_COMMANDS = ['❌', '✖', '✖️', '👎'];

    /**
     * The merchant typed in a customer's chat from the WhatsApp Business app (coexistence echo).
     * Normal replies leave the bot running; "⏸" pauses it in this chat and "▶️" hands the chat
     * back to the bot immediately. A forgotten pause expires after `bot_pause_mins`.
     *
     * @param  array<string, mixed>  $echo
     */
    protected function processMerchantEcho(Merchant $merchant, array $echo): void
    {
        $to = '+'.ltrim((string) ($echo['to'] ?? ''), '+');
        $id = (string) ($echo['id'] ?? '');
        if ($to === '+' || $id === '') {
            return;
        }

        try {
            WhatsAppMessage::create([
                'merchant_id' => $merchant->id,
                'wa_message_id' => $id,
                'direction' => 'outbound',
                'from_number' => (string) ($echo['from'] ?? ''),
                'to_number' => $to,
                'message_type' => $echo['type'] ?? 'text',
                'payload' => $echo + ['source' => 'business_app'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $command = trim((string) ($echo['text']['body'] ?? ''));

        if (in_array($command, self::PAUSE_COMMANDS, true)) {
            $customer = Customer::query()->firstOrCreate(['phone' => $to], ['locale' => $merchant->locale ?? 'en']);
            $customer->forceFill(['bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins())])->save();
        } elseif (in_array($command, self::RESUME_COMMANDS, true)) {
            Customer::query()->where('phone', $to)->update(['bot_paused_until' => null]);
        } elseif (in_array($command, self::ACCEPT_COMMANDS, true) || in_array($command, self::REFUSE_COMMANDS, true)) {
            $this->answerFromShopPhone($merchant, $to, in_array($command, self::ACCEPT_COMMANDS, true));
        } elseif (($price = QuoteService::parse($command, false)) !== null) {
            $this->quoteFromShopPhone($merchant, $to, $price);
        }
    }

    /** "quote 45 Screen replacement" typed in a customer's chat from the shop phone: the price for their open request. */
    protected function quoteFromShopPhone(Merchant $merchant, string $customerPhone, array $price): void
    {
        $customer = Customer::query()->where('phone', $customerPhone)->first();
        $quote = $customer ? Quote::query()->where('customer_id', $customer->id)->whereIn('status', ['requested', 'sent'])->latest()->first() : null;
        if ($quote && ! app(QuoteService::class)->send($merchant, $quote, $price['price'], $price['title'])) {
            Log::info('Shop-phone quote not sent (price out of range)', ['quote_id' => $quote->id]);
        }
    }

    /**
     * A lone 1–5 shortly after we asked for a rating. Returns true when the message was a rating.
     */
    /**
     * Whoever holds the shop phone answers the order waiting in this customer's chat: ✅ accepts it
     * (a Whish order then gets its payment link), ❌ refuses it. Only an order placed in the last
     * few hours counts, so a ✅ sent in an ordinary conversation changes nothing.
     */
    protected function answerFromShopPhone(Merchant $merchant, string $customerPhone, bool $accept): void
    {
        $order = Order::query()
            ->where('status', 'placed')
            ->where('created_at', '>=', now()->subHours(6))
            ->whereHas('customer', fn ($q) => $q->where('phone', $customerPhone))
            ->latest()
            ->first();

        if (! $order) {
            // No order waiting: ✅ / ❌ answers a check the customer is waiting for.
            $customer = Customer::query()->where('phone', $customerPhone)->first();
            $productId = $customer?->hasPendingCheck();
            $product = $productId ? Product::query()->find($productId) : null;
            if ($product) {
                app(DriverBotService::class)->answerCheck($merchant, $customer, $product, $accept, $merchant->locale ?? 'en');
            }

            return;
        }

        try {
            if ($accept) {
                app(OrderConfirmation::class)->accept($merchant, $order);
            } else {
                app(OrderService::class)->transition($merchant, $order->id, 'reject');
            }
        } catch (\Throwable $e) {
            Log::warning('Shop answer to an order failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    protected function handleRating(Merchant $merchant, Customer $customer, string $fromPhone, string $text, string $locale): bool
    {
        $digit = strtr(trim($text), ['١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5']);
        if (! preg_match('/^[1-5]$/', $digit)) {
            return false;
        }

        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->whereNull('rating')
            ->where('rating_requested_at', '>=', now()->subDay())
            ->latest('rating_requested_at')
            ->first();

        if (! $order) {
            return false;
        }

        // Started a new order since the rating question ("2" answering "how many?"): not a rating.
        $busy = Conversation::query()->where('customer_id', $customer->id)->where('status', ConversationStatus::Active)->get()
            ->contains(fn (Conversation $c) => isset(($c->state ?? [])['menu_pick']) || isset(($c->state ?? [])['checkout'])
                || $c->created_at->gte($order->rating_requested_at) || $c->updated_at->gte($order->rating_requested_at));
        if ($busy) {
            return false;
        }

        $order->forceFill(['rating' => (int) $digit, 'rated_at' => now()])->save();
        // A low rating promises a follow-up only when someone at the shop was actually told.
        $key = (int) $digit > 3 ? 'thanks' : ($this->alertLowRating($merchant, $order, $fromPhone) ? 'sorry' : 'sorry_noted');
        $this->reply($merchant, $fromPhone, __("whatsapp.rating.{$key}", ['shop' => $merchant->name], $locale));

        return true;
    }

    /**
     * A tap on Great / Okay / Bad. The answer is kept once (a second tap on the same question changes nothing), a Bad
     * one tells the shop, and the next question follows until the customer has answered them all.
     */
    protected function answerRating(Merchant $merchant, string $fromPhone, string $orderId, string $question, int $value, string $locale): void
    {
        $order = Order::query()->whereKey($orderId)->whereHas('customer', fn ($q) => $q->where('phone', $fromPhone))->first();
        $flow = app(RatingFlow::class);
        if (! $order || ! in_array($question, $flow->questions($order), true)) {
            return;
        }

        if ($order->{RatingFlow::COLUMNS[$question]} === null) {
            $flow->save($order, $question, $value);
            if ($value === RatingFlow::BAD && $this->alertBadRating($merchant, $order, $question, $fromPhone)) {
                Cache::put("rating-alerted:{$order->id}", true, now()->addDay());
            }
            $order->refresh();
        }

        if ($next = $flow->next($order)) {
            $this->send($merchant, $fromPhone, $flow->message($order, $next, $locale));

            return;
        }

        $bad = collect([$order->rating, $order->rating_service, $order->rating_delivery])->contains(RatingFlow::BAD);
        $key = ! $bad ? 'thanks' : (Cache::get("rating-alerted:{$order->id}") ? 'sorry' : 'sorry_noted');
        $this->reply($merchant, $fromPhone, __("whatsapp.rating.{$key}", ['shop' => $merchant->name], $locale));
    }

    /** Tells the shop that one of the three was rated Bad, and which. Returns whether anyone got it. */
    protected function alertBadRating(Merchant $merchant, Order $order, string $question, string $customerPhone): bool
    {
        $shopLocale = $merchant->locale ?? 'en';
        $body = __('whatsapp.staff.bad_rating', [
            'number' => $order->number,
            'what' => __("whatsapp.rating.what_{$question}", ['driver' => $order->driver?->name ?? ''], $shopLocale),
            'phone' => OrderMessage::phone($customerPhone),
        ], $shopLocale);

        return $this->tellTheShop($merchant, $body, [(string) $order->number, $merchant->name, (string) RatingFlow::BAD, $customerPhone]);
    }

    /**
     * Tells the shop about a 1–3 rating typed as a number (the old way, after a message sent by template): every
     * staff member inside the 24-hour window, else the report number if it wrote to the shop number in the last 24
     * hours (there is no template for this, and plain text outside the window is dropped). Returns whether anyone got it.
     */
    protected function alertLowRating(Merchant $merchant, Order $order, string $customerPhone): bool
    {
        $body = __('whatsapp.staff.low_rating', [
            'number' => $order->number, 'rating' => $order->rating, 'phone' => OrderMessage::phone($customerPhone),
        ], $merchant->locale ?? 'en');

        return $this->tellTheShop($merchant, $body, [(string) $order->number, $merchant->name, (string) $order->rating, $customerPhone]);
    }

    /**
     * @param  list<string>  $templateParams  number, shop, rating, customer phone (the approved low-rating template)
     */
    protected function tellTheShop(Merchant $merchant, string $body, array $templateParams): bool
    {
        $told = false;
        foreach ($this->staff->staff($merchant) as $member) {
            $told = $this->staff->send($merchant, $member, $body, [], ShopTemplateService::LOW_RATING, $templateParams) || $told;
        }

        $report = ShopSettings::for($merchant)->reportWhatsapp();
        if (! $told && $report !== '' && $this->staff->numberInWindow($merchant, $report)) {
            try {
                $this->whatsapp->sendToCustomer($merchant, $report, ['type' => 'text', 'text' => $body]);
                $told = true;
            } catch (\Throwable $e) {
                Log::info('Low rating alert not delivered', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            }
        }

        return $told;
    }

    /**
     * @param  array<string,mixed>  $msg
     */
    protected function transcribeVoice(Merchant $merchant, array $msg): ?string
    {
        try {
            $file = $this->whatsapp->downloadMedia($merchant, (string) ($msg['audio']['id'] ?? ''), (int) config('ai.speech.max_bytes'));
            if (! $file) {
                return null;
            }

            // Shop and item names help the transcriber spell what the customer orders.
            $names = Product::query()->where('is_active', true)->orderBy('name')->limit(60)->pluck('name')->implode(', ');
            $hint = mb_substr(trim($merchant->name.'. '.$names), 0, 800);

            $text = $this->speech->transcribe($file['body'], $file['mime_type'], $hint, $merchant->id);

            return $text !== null && trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('Voice note could not be handled', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $value
     */
    protected function answerCalls(array $value): void
    {
        $merchant = MerchantWhatsAppCredentials::findMerchantByPhoneId((string) ($value['metadata']['phone_number_id'] ?? ''));
        if (! $merchant) {
            return;
        }

        $this->tenant->set($merchant);
        try {
            foreach ($value['calls'] as $call) {
                if (! is_array($call) || ($call['direction'] ?? 'USER_INITIATED') !== 'USER_INITIATED') {
                    continue;
                }
                $from = '+'.ltrim((string) ($call['from'] ?? ''), '+');
                if ($from === '+') {
                    continue;
                }
                $customer = Customer::query()->where('phone', $from)->first();
                if ($customer?->is_blocked || ! Cache::add("wa-call:{$merchant->id}:{$from}", true, 600)) {
                    continue;
                }
                $locale = $customer?->locale ?? $merchant->locale ?? 'en';
                $this->replyWithMenu($merchant, $from, $locale, $this->text('call_note', $locale, $merchant->name));
            }
        } finally {
            $this->tenant->forget();
        }
    }

    /** A short note followed by the shop's menu list, so the customer always has a next step. */
    /**
     * A message arrives a second time (a queue retry, or Meta delivering again) and the first attempt never
     * finished: its worker died after the message was recorded. The first attempt may already have changed the
     * cart, so it is not run again; the customer is told to resend instead of being left without an answer.
     * "Never finished" means no handled_at after longer than a job may run, so a slow live attempt is left alone.
     */
    protected function answerInterruptedMessage(Merchant $merchant, string $waMessageId, string $fromPhone): void
    {
        $first = WhatsAppMessage::query()->where('merchant_id', $merchant->id)->where('wa_message_id', $waMessageId)->where('direction', 'inbound')->first();
        if (! $first || isset(($first->payload ?? [])['handled_at']) || $first->created_at->gt(now()->subSeconds(90))) {
            return;
        }
        if (! Cache::add("wa-interrupted:{$merchant->id}:{$waMessageId}", 1, 3600)) {
            return;
        }

        $first->update(['payload' => array_merge($first->payload ?? [], ['handled_at' => now()->toIso8601String(), 'interrupted' => true])]);
        // The worker may have replied and died just before marking the message handled: the customer already has an answer.
        if (WhatsAppMessage::query()->where('merchant_id', $merchant->id)->where('direction', 'outbound')
            ->where('to_number', $fromPhone)->where('created_at', '>=', $first->created_at)->exists()) {
            return;
        }
        $customer = Customer::query()->where('phone', $fromPhone)->first();
        $locale = $customer?->locale ?: ($merchant->locale ?: 'en');
        Log::warning('WhatsApp inbound: a message was interrupted before it was answered; the customer is asked to resend', ['merchant_id' => $merchant->id]);
        // The first attempt may have added the item before it died: show the cart so it is not asked for twice.
        $note = $this->text('interrupted', $locale, $merchant->name);
        $items = Cart::query()->where('status', CartStatus::Open)
            ->whereHas('conversation', fn ($q) => $q->where('customer_id', $customer?->id))
            ->latest('updated_at')->first()?->items()->with('product')->get() ?? collect();
        if ($items->isNotEmpty()) {
            $note .= "\n".__('whatsapp.cart.your_cart', [], $locale);
            foreach ($items as $item) {
                $note .= "\n• ".Quantity::line($item->quantity, $item->product?->unit, (string) ($item->product?->name ?? '')).' — '.Money::format((float) $item->line_total, $merchant->currency);
            }
        }
        $this->replyWithMenu($merchant, $fromPhone, $locale, $note);
    }

    protected function replyWithMenu(Merchant $merchant, string $toPhone, string $locale, string $note): void
    {
        try {
            $menu = $this->chatMenu->categories($merchant, $locale, 1, $note);
            $hasRows = ! empty($menu['interactive']['action']['sections'][0]['rows'] ?? []);
        } catch (\Throwable) {
            $hasRows = false;
        }

        $hasRows ? $this->send($merchant, $toPhone, $menu) : $this->reply($merchant, $toPhone, $note);
    }

    /**
     * @param  array<string,mixed>  $msg
     * @return array{lat: float, lng: float, name: string, address: string}|null
     */
    protected function extractLocation(array $msg): ?array
    {
        $loc = ($msg['type'] ?? '') === 'location' ? ($msg['location'] ?? null) : null;
        if (! is_array($loc) || ! is_numeric($loc['latitude'] ?? null) || ! is_numeric($loc['longitude'] ?? null)) {
            return null;
        }

        return [
            'lat' => (float) $loc['latitude'],
            'lng' => (float) $loc['longitude'],
            'name' => (string) ($loc['name'] ?? ''),
            'address' => trim(implode(', ', array_filter([(string) ($loc['name'] ?? ''), (string) ($loc['address'] ?? '')]))),
        ];
    }

    /**
     * A customer asks for a price ("quote", "devis", "سعر") in a shop that takes repair or custom work.
     * Other shops answer the same words as before (their menu).
     */
    protected function wantsQuote(Merchant $merchant, string $text): bool
    {
        // "quote", "devis", "عرض سعر" mean a price request wherever they stand ("Bonjour, je voudrais un devis pour…");
        // "price" and "how much" only when the message starts with them, because they are everyday words.
        return BusinessKind::quotes($merchant)
            && mb_strlen(trim($text)) >= 12
            && (bool) (preg_match('/\b(quote|quotation|estimate|devis|estimation|تسعيرة|عرض سعر|تقدير)\b/iu', $text)
                || preg_match('/^\s*(price|how much to (fix|repair|make)|combien pour|سعر)\b/iu', $text));
    }

    protected function reply(Merchant $merchant, string $toPhone, string $text): void
    {
        try {
            $this->whatsapp->sendToCustomer($merchant, $toPhone, ['type' => 'text', 'text' => $text]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp inbound: reply send failed', [
                'merchant_id' => $merchant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function text(string $key, string $locale, string $shop): string
    {
        return __("whatsapp.{$key}", ['shop' => $shop], $locale);
    }

    /**
     * The id of a tapped reply button or list row (our own ids, e.g. "co:pay:cash").
     *
     * @param  array<string, mixed>  $msg
     */
    protected function extractButtonId(array $msg): ?string
    {
        $id = $msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @param array<string, mixed> $message */
    protected function send(Merchant $merchant, string $toPhone, array $message): void
    {
        try {
            $this->whatsapp->sendToCustomer($merchant, $toPhone, $message);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp checkout message failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            $body = $message['text'] ?? ($message['interactive']['body']['text'] ?? '');
            if ($body !== '' && ($message['type'] ?? '') !== 'text') {
                $this->reply($merchant, $toPhone, $body);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    protected function extractText(array $msg): ?string
    {
        return match ($msg['type'] ?? '') {
            'text' => $msg['text']['body'] ?? null,
            'interactive' => $msg['interactive']['button_reply']['title']
                ?? $msg['interactive']['list_reply']['title']
                ?? null,
            'button' => $msg['button']['text'] ?? $msg['button']['payload'] ?? null,
            default => null,
        };
    }
}

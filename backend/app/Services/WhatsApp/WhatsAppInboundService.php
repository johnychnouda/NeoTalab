<?php

namespace App\Services\WhatsApp;

use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\WhatsAppMessage;
use App\Services\AI\AiMemoryService;
use App\Services\AI\Contracts\SpeechToText;
use App\Services\ChatMenu;
use App\Services\CheckoutFlow;
use App\Services\ConversationService;
use App\Services\OrderConfirmation;
use App\Services\RatingFlow;
use App\Services\ShopAlertService;
use App\Services\ShopCommands;
use App\Services\SystemAlertService;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationStatus;
use App\Support\Conversation\RecommendedAction;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\Money;
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
            Log::warning('WhatsApp inbound: a number is flooding a shop, its messages get no reply for now', [
                'merchant_id' => $merchant->id,
                'phone' => $fromPhone,
            ]);
            app(SystemAlertService::class)->record('warning', sprintf(
                'WhatsApp flood on %s from +%s — replies paused for that number today',
                $merchant->name,
                ltrim($fromPhone, '+'),
            ), ['merchant_id' => $merchant->id, 'phone' => $fromPhone]);
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

        // Shop owner taps Accept / Ready / Out / Done on a ticket sent to report_whatsapp.
        $buttonId = $this->extractButtonId($msg);
        if ($buttonId !== null && str_starts_with($buttonId, 'stf:')) {
            if ($this->isShopOpsPhone($merchant, $fromPhone)
                && app(ShopCommands::class)->handleButton($merchant, $buttonId)) {
                return;
            }
            // Customer (or unknown) tapped a shop button: ignore — only the shop may use it.

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

        // A photo or file is usually a question the bot can't read: the shop sees it in its WhatsApp Business app
        // and answers personally, so the bot steps back in this chat.
        if (in_array($type, self::PHOTO_TYPES, true)) {
            $customer ??= Customer::query()->firstOrCreate(['phone' => $fromPhone], ['locale' => $locale]);
            $customer->forceFill(['bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins())])->save();
            app(ShopAlertService::class)->photo($merchant, $customer);
            $this->reply($merchant, $fromPhone, $this->text('photo_received', $locale, $merchant->name));

            return;
        }

        // Stickers: never leave the customer without an answer.
        if ($unreadable) {
            $this->reply($merchant, $fromPhone, $this->text('media_unsupported', $locale, $merchant->name));

            return;
        }

        // Voice notes are transcribed and then handled exactly like a typed message.
        if ($isVoice) {
            $text = $this->transcribeVoice($merchant, $msg);
            if ($text === null) {
                $this->reply($merchant, $fromPhone, $this->text('voice_failed', $locale, $merchant->name));

                return;
            }
            $record->update(['payload' => array_merge($msg, ['transcript' => $text])]);
        }

        // What the customer wrote or said after an Okay or Bad rating goes to the shop.
        if ($customer && $text !== null && $this->handleRatingIssue($merchant, $customer, $fromPhone, $text, $locale)) {
            return;
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

            // "Where's my order?" under a status message.
            $step = $buttonId !== null ? $this->chatMenu->handle($merchant, $conversation, $locale, $buttonId) : null;

            // Conversational cancel: customer replied yes/no after we asked.
            if ($text !== null && ($pending = ($conversation->state ?? [])['cancel_pending'] ?? null)) {
                if (($answer = $this->typedCancelAnswer($text)) !== null) {
                    $this->clearCancelPending($conversation);
                    $reply = $this->conversations->resolveCancelButton($conversation, (string) $pending, $answer);
                    if ($reply !== '') {
                        $this->reply($merchant, $fromPhone, $reply);
                    }

                    return;
                }
            }

            // Checkout in plain chat (or legacy buttons): Delivery/Pickup → address → payment → confirm.
            $step ??= $this->checkoutFlow->handle($merchant, $conversation, $locale, $buttonId, $text, $location);

            if ($step !== null) {
                $this->send($merchant, $fromPhone, $step);

                return;
            }

            // A cancel request about an open order gets a yes/no confirm directly, without the assistant.
            if ($text !== null && ($order = $this->conversations->openOrderToCancel($conversation, $text))) {
                $this->rememberCancelPending($conversation, $order->id);
                $this->send($merchant, $fromPhone, $this->cancelQuestion($merchant, $order->id, __('whatsapp.checkout.cancel_confirm', ['number' => $order->number], $locale), $locale));

                return;
            }

            // "كمل" / "done" / 👍 with items in cart: true-chat keeps the AI; legacy opens CheckoutFlow.
            if ($text !== null && ! $this->checkoutFlow->active($conversation)
                && CheckoutFlow::looksLikeContinueCheckout($text)
                && $conversation->cart?->items()->exists()) {
                if (config('ai.true_chat', true)) {
                    $text = $text.' (customer wants to checkout / finish the order)';
                } else {
                    $this->sendUnlessRepeated($merchant, $fromPhone, $this->checkoutFlow->start($merchant, $conversation, $locale));

                    return;
                }
            }

            $result = $this->conversations->processTurn($conversation, (string) $text, $location);
            $reply = trim($result['reply']);
            // The assistant has just recognised the customer's language: checkout that follows uses it too.
            $locale = $result['conversation']->customer->locale ?? $locale;

            // "Cancel order #N?" — customer confirms with yes/no (words or legacy buttons).
            if ($result['cancel_confirm'] !== null) {
                $this->rememberCancelPending($result['conversation'], $result['cancel_confirm']);
                $this->send($merchant, $fromPhone, $this->cancelQuestion($merchant, $result['cancel_confirm'], $reply, $locale));

                return;
            }

            // True-chat: AI asked for a pin — send WhatsApp's location request with the AI's words.
            if (! empty($result['need_location'])) {
                $this->send($merchant, $fromPhone, [
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'location_request_message',
                        'body' => ['text' => mb_substr($reply !== '' ? $reply : 'Share your location 📍', 0, 1024)],
                        'action' => ['name' => 'send_location'],
                    ],
                ]);

                return;
            }

            // Legacy: typed checkout without fulfillment / recommended checkout → CheckoutFlow.
            if (! empty($result['start_checkout_flow']) || $result['fulfillment_choice']) {
                if ($reply !== '' && ! empty($result['start_checkout_flow'])) {
                    $this->reply($merchant, $fromPhone, $reply);
                }
                $this->sendUnlessRepeated($merchant, $fromPhone, $this->checkoutFlow->start(
                    $merchant,
                    $result['conversation'],
                    $locale,
                    $result['output']->checkout,
                ));

                return;
            }

            // True-chat checkout / place_order: AI already wrote the reply (and may have placed).
            if (config('ai.true_chat', true)
                && in_array($result['output']->recommendedAction, [RecommendedAction::Checkout, RecommendedAction::PlaceOrder], true)) {
                if ($reply !== '') {
                    $this->reply($merchant, $fromPhone, $reply);
                }

                return;
            }

            // Safety net (legacy): AI promised checkout in prose without the action.
            if ($reply !== ''
                && ! config('ai.true_chat', true)
                && ! $this->checkoutFlow->active($result['conversation'])
                && $result['conversation']->cart?->items()->exists()
                && $this->aiPromisedCheckoutWithoutAction($reply)) {
                $this->reply($merchant, $fromPhone, $reply);
                $this->sendUnlessRepeated($merchant, $fromPhone, $this->checkoutFlow->start($merchant, $result['conversation'], $locale));

                return;
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp inbound: AI turn failed', [
                'merchant_id' => $merchant->id,
                'wa_message_id' => $waMessageId,
                'error' => $e->getMessage(),
            ]);
            // No menu to fall back on: the customer is told a person will answer; the message is on the shop phone.
            $this->reply($merchant, $fromPhone, $this->text('ai_down', $locale, $merchant->name));

            return;
        }

        if ($reply !== '' && ($result['menu_link'] ?? false)) {
            // "Show me the menu" attaches the written menu; a customer who named a section gets that section's items.
            $section = $text !== null ? $this->chatMenu->categoryNamed($text) : null;
            $this->send($merchant, $fromPhone, $section
                ? $this->chatMenu->categoryMenu($merchant, $section, $locale, $reply)
                : $this->chatMenu->textMenu($merchant, $locale, $reply));
        } elseif ($reply !== '') {
            $this->reply($merchant, $fromPhone, $reply);
        } elseif ($result['output']->recommendedAction !== RecommendedAction::CancelOrder && empty($result['order'])) {
            // The assistant had nothing to say (cancelling is answered by the status message).
            $this->reply($merchant, $fromPhone, $this->text('not_understood', $locale, $merchant->name));
        }
    }

    /** "Cancel order #N?" — words by default; optional Yes/No buttons when conversational is off. */
    protected function cancelQuestion(Merchant $merchant, string $orderId, string $body, string $locale): array
    {
        if (config('whatsapp.conversational', true)) {
            return ['type' => 'text', 'text' => mb_substr($body, 0, 4096)];
        }

        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => "cx:yes:{$orderId}", 'title' => mb_substr(__('whatsapp.checkout.btn_cancel_yes', [], $locale), 0, 20)]],
                ['type' => 'reply', 'reply' => ['id' => "cx:no:{$orderId}", 'title' => mb_substr(__('whatsapp.checkout.btn_cancel_no', [], $locale), 0, 20)]],
            ]],
        ]];
    }

    /** Remember which order a yes/no cancel reply is about (conversational path). */
    protected function rememberCancelPending(Conversation $conversation, string $orderId): void
    {
        $state = $conversation->state ?? [];
        $state['cancel_pending'] = $orderId;
        $conversation->forceFill(['state' => $state])->save();
    }

    protected function clearCancelPending(Conversation $conversation): void
    {
        $state = $conversation->state ?? [];
        unset($state['cancel_pending']);
        $conversation->forceFill(['state' => $state])->save();
    }

    /** Typed yes / no / oui / لا under a pending cancel question. */
    protected function typedCancelAnswer(string $text): ?bool
    {
        $t = mb_strtolower(trim($text));
        if (preg_match('/^(yes|yep|yeah|y|oui|ok|okay|confirm|اي|أي|اه|آه|نعم|أجل|👍|✅)$/u', $t)) {
            return true;
        }
        if (preg_match('/^(no|nope|n|non|لا|لأ|كلا|keep|❌)$/u', $t)) {
            return false;
        }

        return null;
    }

    public const PAUSE_COMMANDS = ['⏸', '⏸️', '#pause', '#stop'];

    public const RESUME_COMMANDS = ['▶', '▶️', '🤖', '#bot', '#resume'];

    /** Typed by the shop in a customer's chat: accept / refuse the order waiting for the shop. */
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
        $customer = Customer::query()->where('phone', $to)->first()
            ?? Customer::query()->firstOrCreate(['phone' => $to], ['locale' => $merchant->locale ?? 'en']);

        // Owner teaches the student AI: "remember: we close deliveries after 11"
        if ($command !== '' && app(AiMemoryService::class)->learnFromOwnerText($command, $customer)) {
            return;
        }

        if (in_array($command, self::PAUSE_COMMANDS, true)) {
            $customer->forceFill(['bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins())])->save();
        } elseif (in_array($command, self::RESUME_COMMANDS, true)) {
            $customer->forceFill(['bot_paused_until' => null])->save();
        } else {
            app(ShopCommands::class)->handle($merchant, $to, $command);
        }
    }

    /**
     * A lone 1–5 or great/okay/bad shortly after we asked for a rating. Returns true when it was a rating.
     */
    protected function handleRating(Merchant $merchant, Customer $customer, string $fromPhone, string $text, string $locale): bool
    {
        $digit = strtr(trim($text), ['١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5']);
        $score = preg_match('/^[1-5]$/', $digit) ? (int) $digit : RatingFlow::typedScore($text);
        if ($score === null) {
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

        $question = app(RatingFlow::class)->next($order) ?? 'order';
        $this->answerRating($merchant, $fromPhone, $order->id, $question, $score, $locale);

        return true;
    }

    /**
     * A tap on Great / Okay / Bad. The answer is kept once (a second tap changes nothing). Great is thanked. Okay or Bad
     * is told to the shop and the customer is asked what went wrong, typed or as a voice note (see handleRatingIssue).
     */
    protected function answerRating(Merchant $merchant, string $fromPhone, string $orderId, string $question, int $value, string $locale): void
    {
        $order = Order::query()->whereKey($orderId)->whereHas('customer', fn ($q) => $q->where('phone', $fromPhone))->first();
        $flow = app(RatingFlow::class);
        if (! $order || ! in_array($question, $flow->questions($order), true) || $order->{RatingFlow::COLUMNS[$question]} !== null) {
            return;
        }

        $flow->save($order, $question, $value);
        if ($value === RatingFlow::GREAT) {
            $this->reply($merchant, $fromPhone, __('whatsapp.rating.thanks', ['shop' => $merchant->name], $locale));

            return;
        }

        // The customer's next message (or voice note) is about the problem, for two hours.
        Cache::put("rating-issue:{$merchant->id}:{$fromPhone}", $order->id, now()->addHours(2));
        $this->reply($merchant, $fromPhone, __('whatsapp.rating.ask_issue', ['shop' => $merchant->name], $locale));
    }

    /**
     * The customer's message after an Okay or Bad rating is kept on the order. The shop reads it in its own chat (the
     * customer wrote it there) and the bot stays out of this chat so the shop can answer personally.
     */
    protected function handleRatingIssue(Merchant $merchant, Customer $customer, string $fromPhone, string $text, string $locale): bool
    {
        $orderId = Cache::pull("rating-issue:{$merchant->id}:{$fromPhone}");
        $order = $orderId ? Order::query()->whereKey($orderId)->where('customer_id', $customer->id)->first() : null;
        if (! $order || trim($text) === '') {
            return false;
        }

        $order->forceFill(['rating_issue' => mb_substr(trim($text), 0, 2000)])->save();
        $customer->forceFill(['bot_paused_until' => now()->addMinutes(ShopSettings::for($merchant)->botPauseMins())])->save();
        app(AiMemoryService::class)->learnFromRatingIssue($order->loadMissing('customer'), $text);
        app(ShopAlertService::class)->handoff($merchant, $customer, 'rating');

        $this->reply($merchant, $fromPhone, __('whatsapp.rating.issue_received', ['shop' => $merchant->name], $locale));

        return true;
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
                $this->reply($merchant, $from, $this->text('call_note', $locale, $merchant->name));
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
        $this->reply($merchant, $fromPhone, $note);
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

    /** Owner's report WhatsApp (ops tickets), digit-matched against the inbound from number. */
    protected function isShopOpsPhone(Merchant $merchant, string $fromPhone): bool
    {
        $report = ShopSettings::for($merchant)->reportWhatsapp();
        if ($report === '') {
            return false;
        }
        $a = preg_replace('/\D+/', '', $report) ?? '';
        $b = preg_replace('/\D+/', '', $fromPhone) ?? '';

        return $a !== '' && $a === $b;
    }

    /**
     * The model wrote that checkout/pickup/delivery is continuing, without returning recommended_action checkout.
     * Used so the customer still gets the button flow instead of a dead-end sentence.
     */
    protected function aiPromisedCheckoutWithoutAction(string $reply): bool
    {
        $t = mb_strtolower(trim($reply));

        return (bool) preg_match(
            '/(منكمل|نكمّل|نكمل|كمّل|كمل|نتابع|تابع).{0,60}(طلب|استلام|توصيل|checkout)|'
            .'(continu(e|ing)|proceed).{0,40}(checkout|pickup|delivery|order)|'
            .'(تمام[،,]?\s*)?(منكمل|نكمل).{0,40}(استلام|توصيل|طلب)|'
            .'(on\s+proceed(?:s|ing)?\s+with|we(\'ll| will) (continue|proceed)).{0,40}(pickup|delivery|checkout|order)/iu',
            $t
        );
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

    /**
     * The checkout question is not sent twice in a row: when the customer's last message was only "nothing else"
     * and the question is already on their screen, the same buttons would just repeat it.
     *
     * @param  array<string, mixed>  $message
     */
    protected function sendUnlessRepeated(Merchant $merchant, string $toPhone, array $message): void
    {
        $body = $message['interactive']['body']['text'] ?? null;
        if ($body !== null) {
            $last = WhatsAppMessage::query()->where('merchant_id', $merchant->id)->where('direction', 'outbound')
                ->where('to_number', $toPhone)->where('message_type', 'interactive')->latest('created_at')->latest('id')->first();
            if (($last?->payload['interactive']['body']['text'] ?? null) === $body) {
                return;
            }
        }

        $this->send($merchant, $toPhone, $message);
    }

    /** @param array<string, mixed> $message */
    protected function send(Merchant $merchant, string $toPhone, array $message): void
    {
        if (($message['type'] ?? '') === 'none') {
            return;
        }

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

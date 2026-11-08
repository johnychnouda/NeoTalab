<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Services\AppointmentService;
use App\Services\BookingDeposits;
use App\Services\DriverDispatcher;
use App\Services\JobStageService;
use App\Services\OrderConfirmation;
use App\Services\OrderService;
use App\Services\OrderTiming;
use App\Services\QuoteService;
use App\Support\MerchantProfileSync;
use App\Support\Money;
use App\Support\OrderMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Messages from a registered driver's number to the shop's number:
 * "start" / "stop" shift commands and the Picked up / Delivered buttons on each delivery.
 */
class DriverBotService
{
    public function __construct(
        protected OrderService $orders,
        protected DriverDispatcher $dispatcher,
        protected WhatsAppService $whatsapp,
    ) {}

    public static function findDriver(string $phone): ?Driver
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        return Driver::query()
            ->where('is_active', true)
            ->get(['id', 'merchant_id', 'name', 'role', 'locale', 'phone', 'whatsapp_number', 'status', 'active_order_id', 'shift_started_at'])
            ->first(fn (Driver $d) => in_array($digits, [
                preg_replace('/\D+/', '', (string) $d->whatsapp_number),
                preg_replace('/\D+/', '', (string) $d->phone),
            ], true));
    }

    /**
     * @param  array<string, mixed>  $msg  raw Meta message
     */
    public function handle(Merchant $merchant, Driver $driver, array $msg): void
    {
        $locale = $driver->languageFor($merchant);
        $to = $driver->whatsapp_number ?: $driver->phone;

        if ($driver->role === 'staff') {
            if ($this->manageBookings($merchant, $to, $msg, $locale) || $this->manageJobs($merchant, $to, $msg, $locale)) {
                return;
            }
            $this->reply($merchant, $to, $this->handleStaff($merchant, $msg, $locale));

            return;
        }

        $buttonId = $msg['interactive']['button_reply']['id'] ?? $msg['button']['payload'] ?? null;
        if (is_string($buttonId) && preg_match('/^drv:(picked_up|outside|delivered|failed):(.+)$/', $buttonId, $m)) {
            $this->reply($merchant, $to, $this->orderAction($merchant, $driver, $m[1], $m[2], $locale));

            return;
        }

        $text = mb_strtolower(trim((string) ($msg['text']['body'] ?? $msg['button']['text'] ?? '')));

        if (in_array($text, ['start', 'بدء', 'ابدأ', 'debut', 'début', 'commencer'], true)) {
            $started = $driver->status !== 'on_duty';
            $this->reply($merchant, $to, $this->startShift($driver, $locale));

            // After the greeting, hand over any orders that were waiting for a driver.
            if ($started) {
                $this->dispatcher->dispatchWaiting($merchant);
            }

            return;
        }

        $reply = match (true) {
            in_array($text, ['stop', 'end', 'إنهاء', 'وقف', 'fin', 'terminer'], true) => $this->endShift($merchant, $driver, $locale),
            default => __('whatsapp.driver.help', [], $locale),
        };

        $this->reply($merchant, $to, $reply);
    }

    /**
     * A shop with no staff is run by its owner: their number (the one they gave at sign-up) may use the booking
     * buttons and "today" / "tomorrow", and nothing else (any other message from it is an ordinary customer's).
     *
     * @param  array<string, mixed>  $msg
     */
    public function ownerForBookings(Merchant $merchant, string $fromPhone, array $msg): ?Driver
    {
        $owner = MerchantProfileSync::whatsapp($merchant);
        if (! $owner || preg_replace('/\D+/', '', $owner) !== preg_replace('/\D+/', '', $fromPhone)
            || ! $this->isBookingMessage($msg) || app(StaffMessenger::class)->staff($merchant)->isNotEmpty()) {
            return null;
        }

        return StaffMessenger::ownerAsStaff($merchant, $owner);
    }

    /** @param  array<string, mixed>  $msg */
    protected function isBookingMessage(array $msg): bool
    {
        $id = (string) ($msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? $msg['button']['payload'] ?? '');

        // A lone owner may also move a job on and mark an order ready: those buttons are only ever sent to them.
        return preg_match('/^stf:(appt_|dp_|job_|ready:|picked_up:|delivered:)/', $id) === 1
            || $this->bookingDay((string) ($msg['text']['body'] ?? '')) !== null;
    }

    /** "today" / "tomorrow" (or "bookings") in the shop's three languages: which day's bookings are asked for. */
    protected function bookingDay(string $text): ?string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if (in_array($text, ['today', 'bookings', 'schedule', 'اليوم', 'حجوزات', 'حجوزات اليوم', "aujourd'hui", 'aujourd’hui', 'réservations', 'reservations'], true)) {
            return 'today';
        }

        return in_array($text, ['tomorrow', 'غدا', 'غداً', 'حجوزات الغد', 'demain'], true) ? 'tomorrow' : null;
    }

    /**
     * Bookings from WhatsApp for the team (and a lone owner): the day's list, and Change → Move it / Cancel it /
     * Keep it on the ticket the new booking sent. The customer is told of a cancellation or a move.
     *
     * @param  array<string, mixed>  $msg
     * @return bool whether the message was a booking one (and has been answered)
     */
    protected function manageBookings(Merchant $merchant, string $to, array $msg, string $locale): bool
    {
        $id = (string) ($msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? $msg['button']['payload'] ?? '');

        if ($day = $this->bookingDay((string) ($msg['text']['body'] ?? ''))) {
            $this->reply($merchant, $to, $this->daySchedule($merchant, $day === 'tomorrow', $locale));

            return true;
        }
        // The shop checked its Whish account for a booking's deposit: Received confirms the booking, Not received cancels it.
        if (preg_match('/^stf:dp_(ok|no):([^:]+)$/', $id, $d)) {
            return $this->checkDeposit($merchant, $to, $d[1] === 'ok', $d[2], $locale);
        }
        if (! preg_match('/^stf:appt_(change|move|cancel|keep|to):([^:]+)(?::(\d{12}))?$/', $id, $m)) {
            return false;
        }

        $appointment = Appointment::query()->with(['customer', 'provider'])->whereKey($m[2])->first();
        if (! $appointment) {
            $this->reply($merchant, $to, __('whatsapp.driver.not_yours', [], $locale));

            return true;
        }
        if ($appointment->status !== 'booked') {
            $this->reply($merchant, $to, __('whatsapp.appointment.staff_closed', ['service' => $appointment->service_name], $locale));

            return true;
        }

        $tz = app(AppointmentService::class)->tz($merchant);
        $when = fn (string $lang) => [
            'date' => $appointment->starts_at->copy()->setTimezone($tz)->locale($lang)->isoFormat('ddd D MMM'),
            'time' => $appointment->starts_at->copy()->setTimezone($tz)->format('H:i'),
        ];
        $params = ['service' => $appointment->service_name, 'phone' => OrderMessage::phone($appointment->customer?->phone)] + $when($locale);

        switch ($m[1]) {
            case 'change':
                $this->send($merchant, $to, ['type' => 'interactive', 'interactive' => [
                    'type' => 'button', 'body' => ['text' => mb_substr(__('whatsapp.appointment.change_prompt', $params, $locale), 0, 1024)],
                    'action' => ['buttons' => [
                        ['type' => 'reply', 'reply' => ['id' => "stf:appt_move:{$appointment->id}", 'title' => mb_substr(__('whatsapp.appointment.btn_move', [], $locale), 0, 20)]],
                        ['type' => 'reply', 'reply' => ['id' => "stf:appt_cancel:{$appointment->id}", 'title' => mb_substr(__('whatsapp.appointment.btn_cancel_it', [], $locale), 0, 20)]],
                        ['type' => 'reply', 'reply' => ['id' => "stf:appt_keep:{$appointment->id}", 'title' => mb_substr(__('whatsapp.appointment.btn_keep', [], $locale), 0, 20)]],
                    ]],
                ]]);
                break;
            case 'keep':
                $this->reply($merchant, $to, __('whatsapp.appointment.kept', $params, $locale));
                break;
            case 'cancel':
                if (! app(AppointmentService::class)->cancelByShop($appointment)) {
                    $this->reply($merchant, $to, __('whatsapp.appointment.staff_closed', ['service' => $appointment->service_name], $locale));
                    break;
                }
                $this->tellCustomer($merchant, $appointment, CustomerTemplates::BOOKING_CANCELLED_SHOP, 'shop_cancelled', $when);
                $this->reply($merchant, $to, __('whatsapp.appointment.staff_cancelled_ok', $params, $locale)
                    .($appointment->deposit_paid_at ? "\n".__('whatsapp.appointment.deposit_note', ['amount' => Money::format((float) $appointment->deposit_amount, $merchant->currency)], $locale) : ''));
                break;
            case 'move':
                $this->offerNewTimes($merchant, $to, $appointment, $params, $locale, false);
                break;
            case 'to':
                $start = isset($m[3]) ? Carbon::createFromFormat('YmdHi', $m[3], $tz) : null;
                if (! $start || ! app(AppointmentService::class)->move($merchant, $appointment, $start)) {
                    $this->offerNewTimes($merchant, $to, $appointment, $params, $locale, true);
                    break;
                }
                $appointment->refresh();
                $this->tellCustomer($merchant, $appointment, CustomerTemplates::BOOKING_MOVED, 'shop_moved', $when);
                $this->reply($merchant, $to, __('whatsapp.appointment.staff_moved_ok', ['phone' => $params['phone']] + $when($locale), $locale));
                break;
        }

        return true;
    }

    protected function checkDeposit(Merchant $merchant, string $to, bool $received, string $appointmentId, string $locale): bool
    {
        $appointment = Appointment::query()->with(['customer', 'provider'])->whereKey($appointmentId)->first();
        if (! $appointment) {
            $this->reply($merchant, $to, __('whatsapp.driver.not_yours', [], $locale));

            return true;
        }

        $deposits = app(BookingDeposits::class);
        $done = $received ? $deposits->confirm($merchant, $appointment) : $deposits->refuse($merchant, $appointment);
        $p = $deposits->params($merchant, $appointment, $locale);
        $this->reply($merchant, $to, $done
            ? __($received ? 'whatsapp.appointment.deposit_ok' : 'whatsapp.appointment.deposit_no', $p, $locale)
            : __('whatsapp.appointment.deposit_already', [], $locale));

        return true;
    }

    /**
     * Job stages from WhatsApp: Next stage, the list of all stages, and a pick from it. The customer is told at each step.
     *
     * @param  array<string, mixed>  $msg
     * @return bool whether the message was a job one (and has been answered)
     */
    protected function manageJobs(Merchant $merchant, string $to, array $msg, string $locale): bool
    {
        $id = (string) ($msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? $msg['button']['payload'] ?? '');
        if (! preg_match('/^stf:job_(next|pick|set):([^:]+)(?::(\d+))?$/', $id, $m)) {
            return false;
        }

        $jobs = app(JobStageService::class);
        $order = Order::query()->with('customer')->whereKey($m[2])->first();
        if (! $order || ! $jobs->enabled($merchant)) {
            $this->reply($merchant, $to, __('whatsapp.driver.not_yours', [], $locale));

            return true;
        }
        if (in_array($order->status, ['placed', 'pending_payment', 'delivered', 'cancelled', 'rejected'], true)) {
            $this->reply($merchant, $to, __('whatsapp.staff.job_closed', ['number' => $order->number], $locale));

            return true;
        }

        if ($m[1] === 'pick') {
            $stages = $jobs->stages($merchant);
            $rows = [];
            foreach ($stages as $i => $name) {
                $rows[] = ['id' => "stf:job_set:{$order->id}:{$i}", 'title' => mb_substr(($order->job_stage === $i ? '✓ ' : '').$name, 0, 24)];
            }
            $button = mb_substr(__('whatsapp.staff.job_pick_button', [], $locale), 0, 20);
            $this->send($merchant, $to, ['type' => 'interactive', 'interactive' => [
                'type' => 'list',
                'body' => ['text' => mb_substr(__('whatsapp.staff.job_pick_prompt', ['number' => $order->number, 'stage' => $jobs->current($merchant, $order) ?? __('whatsapp.staff.job_not_started', [], $locale)], $locale), 0, 1024)],
                'action' => ['button' => $button, 'sections' => [['title' => mb_substr($button, 0, 24), 'rows' => $rows]]],
            ]]);

            return true;
        }

        $target = $m[1] === 'set' ? (int) ($m[3] ?? -1) : null;
        if (! $jobs->move($merchant, $order, $target)) {
            $this->reply($merchant, $to, __('whatsapp.staff.job_last', ['number' => $order->number], $locale));

            return true;
        }

        $order->refresh();
        $this->reply($merchant, $to, __('whatsapp.staff.job_moved', ['number' => $order->number, 'stage' => $jobs->current($merchant, $order)], $locale));
        $card = $jobs->card($merchant, $order, $locale);
        $this->send($merchant, $to, ['type' => 'interactive', 'interactive' => [
            'type' => 'button', 'body' => ['text' => mb_substr($card['text'], 0, 1024)],
            'action' => ['buttons' => array_map(fn ($b) => ['type' => 'reply', 'reply' => ['id' => $b[0], 'title' => mb_substr($b[1], 0, 20)]], $card['buttons'])],
        ]]);

        return true;
    }

    /**
     * The customer is told, in their language: the normal message inside WhatsApp's 24 hours, the template after.
     *
     * @param  \Closure(string): array{date: string, time: string}  $when
     */
    protected function tellCustomer(Merchant $merchant, Appointment $appointment, string $event, string $key, \Closure $when): void
    {
        $locale = $appointment->customer?->locale ?? $merchant->locale ?? 'en';
        $at = $when($locale);
        $params = ['shop' => $merchant->name, 'service' => $appointment->service_name] + $at;

        try {
            app(CustomerMessenger::class)->send(
                $merchant, (string) $appointment->customer?->phone,
                ['type' => 'text', 'text' => __("whatsapp.appointment.{$key}", $params, $locale)],
                $event, [$merchant->name, $appointment->service_name, $at['date'], $at['time']], $locale,
            );
        } catch (\Throwable $e) {
            Log::warning('Booking change not told to the customer', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A list of the next free times (this booking's day first, then the following days) to move the booking to.
     *
     * @param  array<string, string>  $params
     */
    protected function offerNewTimes(Merchant $merchant, string $to, Appointment $appointment, array $params, string $locale, bool $taken): void
    {
        $service = Product::query()->find($appointment->product_id);
        $appointments = app(AppointmentService::class);
        $tz = $appointments->tz($merchant);
        $current = $appointment->starts_at->copy()->setTimezone($tz);
        $rows = [];

        for ($d = 0; $d < 7 && count($rows) < 10 && $service; $d++) {
            $day = $current->copy()->startOfDay()->addDays($d);
            foreach ($appointments->availableSlots($merchant, $service, $day, $appointment->provider) as $slot) {
                $start = $day->copy()->setTimeFromTimeString($slot);
                if ($start->eq($current)) {
                    continue;
                }
                $rows[] = ['id' => 'stf:appt_to:'.$appointment->id.':'.$start->format('YmdHi'), 'title' => $start->copy()->locale($locale)->isoFormat('ddd D MMM').' '.$slot];
                if (count($rows) >= 10) {
                    break;
                }
            }
        }

        if ($rows === []) {
            $this->reply($merchant, $to, __('whatsapp.appointment.move_none', ['service' => $appointment->service_name], $locale));

            return;
        }

        $text = ($taken ? __('whatsapp.appointment.move_taken', [], $locale) : __('whatsapp.appointment.move_prompt', $params, $locale));
        $button = mb_substr(__('whatsapp.appointment.move_button', [], $locale), 0, 20);
        $this->send($merchant, $to, ['type' => 'interactive', 'interactive' => [
            'type' => 'list', 'body' => ['text' => mb_substr($text, 0, 1024)],
            'action' => ['button' => $button, 'sections' => [['title' => mb_substr($button, 0, 24), 'rows' => $rows]]],
        ]]);
    }

    /** One day's bookings, in time order, for the team (and a lone owner). */
    protected function daySchedule(Merchant $merchant, bool $tomorrow, string $locale): string
    {
        $tz = app(AppointmentService::class)->tz($merchant);
        $day = now()->setTimezone($tz)->startOfDay()->addDays($tomorrow ? 1 : 0);
        $rows = Appointment::query()->with('customer')->where('status', 'booked')
            ->whereBetween('starts_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])->orderBy('starts_at')->get();

        $date = $day->copy()->locale($locale)->isoFormat('ddd D MMM');
        if ($rows->isEmpty()) {
            return __('whatsapp.appointment.day_none', ['when' => __($tomorrow ? 'whatsapp.appointment.when_tomorrow' : 'whatsapp.appointment.when_today', [], $locale)], $locale);
        }

        return __($tomorrow ? 'whatsapp.appointment.day_tomorrow' : 'whatsapp.appointment.day_today', ['date' => $date], $locale)."\n"
            .$rows->map(fn (Appointment $a) => $a->starts_at->copy()->setTimezone($tz)->format('H:i').' · '.$a->service_name.' · '.OrderMessage::phone($a->customer?->phone))->implode("\n");
    }

    /** @param  array<string, mixed>  $message */
    protected function send(Merchant $merchant, string $to, array $message): void
    {
        try {
            $this->whatsapp->sendToCustomer($merchant, $to, $message);
        } catch (\Throwable $e) {
            Log::warning('Booking message failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Staff (owner's personal phone, employees): open/close the shop, and the order and
     * hand-back buttons sent by NotifyStaffOfOrder / NotifyStaffOfHandoff.
     *
     * @param  array<string, mixed>  $msg
     */
    protected function handleStaff(Merchant $merchant, array $msg, string $locale): string
    {
        // Buttons on a template message (sent outside the 24-hour window) come back as a payload.
        $buttonId = (string) ($msg['interactive']['button_reply']['id'] ?? $msg['button']['payload'] ?? '');

        // "refunded 128": the shop has sent the money back to a customer whose cancelled Whish order was paid.
        $typed = trim((string) ($msg['text']['body'] ?? ''));

        // "quote 12 45 Screen replacement" (or the shop's ticket "quote 45 …"): the price for a customer's request.
        if ($buttonId === '' && ($quote = QuoteService::parse($typed, true)) !== null) {
            return $this->sendQuote($merchant, $quote, $locale);
        }
        if (preg_match('/^(?:refunded|refund done|مسترد|remboursé|rembourse)\s*#?\s*(\d+)$/iu', $typed, $m)) {
            $order = Order::query()->where('number', (int) $m[1])->where('payment_status', 'refund_due')->first();
            if (! $order) {
                return __('whatsapp.staff.refund_not_found', ['number' => (int) $m[1]], $locale);
            }
            $order->forceFill(['payment_status' => 'refunded'])->save();

            return __('whatsapp.staff.refund_done', ['number' => $order->number], $locale);
        }

        if (preg_match('/^stf:(approve|refuse):([^:]+):(.+)$/', $buttonId, $m)) {
            $customer = Customer::query()->whereKey($m[2])->first();
            $product = Product::query()->find($m[3]);
            if (! $customer || ! $product) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }

            return $this->answerCheck($merchant, $customer, $product, $m[1] === 'approve', $locale);
        }

        if (preg_match('/^stf:resume:(.+)$/', $buttonId, $m)) {
            $customer = Customer::query()->whereKey($m[1])->first();
            $customer?->forceFill(['bot_paused_until' => null])->save();

            return __('whatsapp.staff.resumed', ['phone' => OrderMessage::phone($customer?->phone)], $locale);
        }

        if (preg_match('/^stf:appt_(completed|no_show):(.+)$/', $buttonId, $m)) {
            $appointment = Appointment::query()->whereKey($m[2])->first();
            if (! $appointment) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }
            // Only a booking still on the calendar can be marked done or missed (not one the customer cancelled).
            if ($appointment->status !== 'booked') {
                return __('whatsapp.appointment.staff_closed', ['service' => $appointment->service_name], $locale);
            }
            $appointment->forceFill(['status' => $m[1]])->save();

            return __('whatsapp.appointment.staff_updated', ['service' => $appointment->service_name], $locale);
        }

        // Whish: the shop checked its Whish account and found the payment (ok) or did not (no).
        if (preg_match('/^stf:wh_(ok|no):(.+)$/', $buttonId, $m)) {
            $order = Order::query()->where('merchant_id', $merchant->id)->whereKey($m[2])->first();
            if (! $order) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }

            $confirmation = app(OrderConfirmation::class);
            if ($m[1] === 'ok') {
                return $confirmation->shopConfirms($merchant, $order)
                    ? __('whatsapp.staff.whish_confirmed', ['number' => $order->number], $locale)
                    : __('whatsapp.staff.whish_already', ['number' => $order->number], $locale);
            }

            return $confirmation->shopRefuses($merchant, $order)
                ? __('whatsapp.staff.whish_refused', ['number' => $order->number], $locale)
                : __('whatsapp.staff.whish_already', ['number' => $order->number], $locale);
        }

        if (preg_match('/^stf:(accept|reject|delay):(.+)$/', $buttonId, $m)) {
            $order = Order::query()->whereKey($m[2])->first();
            if (! $order) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }
            if ($order->status === 'cancelled') {
                return __('whatsapp.staff.cancelled', ['number' => $order->number], $locale);
            }

            try {
                if ($m[1] === 'accept') {
                    $order = app(OrderConfirmation::class)->accept($merchant, $order);

                    // Whish: the customer pays now; the next ticket comes once the payment is in.
                    // Otherwise the next ticket (Ready / Out for delivery) confirms it to the staff.
                    return $order->status === 'pending_payment'
                        ? __('whatsapp.staff.accepted_awaiting_payment', ['number' => $order->number], $locale)
                        : '';
                }
                if ($m[1] === 'reject') {
                    $this->orders->transition($merchant, $order->id, 'reject');

                    return __('whatsapp.staff.refused', ['number' => $order->number], $locale);
                }
                if (in_array($order->status, ['delivered', 'cancelled', 'rejected'], true)) {
                    return __('whatsapp.driver.not_yours', [], $locale);
                }
                $order = app(OrderConfirmation::class)->delay($merchant, $order);

                return __('whatsapp.staff.delayed', ['number' => $order->number, 'date' => app(OrderTiming::class)->date($merchant, $order->promised_at, $locale)], $locale);
            } catch (ValidationException $e) {
                return collect($e->errors())->flatten()->first() ?? __('whatsapp.error', [], $locale);
            }
        }

        if (preg_match('/^stf:(ready|picked_up|delivered):(.+)$/', $buttonId, $m)) {
            $order = Order::query()->whereKey($m[2])->first();
            if (! $order) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }

            try {
                $this->orders->transition($merchant, $order->id, $m[1]);
            } catch (ValidationException $e) {
                return collect($e->errors())->flatten()->first() ?? __('whatsapp.error', [], $locale);
            }

            return __('whatsapp.staff.done', ['number' => $order->number], $locale);
        }

        $text = mb_strtolower(trim((string) ($msg['text']['body'] ?? '')));
        $mode = match (true) {
            in_array($text, ['open', 'فتح', 'افتح', 'ouvrir', 'ouvert'], true) => 'auto',
            in_array($text, ['close', 'closed', 'سكر', 'إغلاق', 'اغلاق', 'fermer', 'fermé'], true) => 'closed',
            default => null,
        };

        if ($mode === null) {
            return __('whatsapp.staff.help', [], $locale);
        }

        $settings = $merchant->settings ?? [];
        $settings['mode'] = $mode;
        $merchant->forceFill(['settings' => $settings])->save();

        return __($mode === 'auto' ? 'whatsapp.staff.opened' : 'whatsapp.staff.closed', ['shop' => $merchant->name], $locale);
    }

    /**
     * The team approved or refused an item for a customer: the customer is told
     * and, when approved, is told to ask for it (the approval lasts a day, for one order).
     */
    public function answerCheck(Merchant $merchant, Customer $customer, Product $product, bool $approve, string $locale): string
    {
        $customerLocale = $customer->locale ?? 'en';
        if ($approve) {
            $customer->markCheck($product->id, true);
        } else {
            $customer->useApproval($product->id);
        }
        $customer->forceFill(['bot_paused_until' => null])->save();

        $text = __($approve ? 'whatsapp.check.approved' : 'whatsapp.check.refused', ['item' => $product->name, 'shop' => $merchant->name], $customerLocale);
        $message = ['type' => 'text', 'text' => $text];
        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $customer->phone, $message);
        } catch (\Throwable $e) {
            Log::warning('Check answer not delivered to the customer', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }

        return __($approve ? 'whatsapp.check.staff_approved' : 'whatsapp.check.staff_refused', ['item' => $product->name, 'phone' => OrderMessage::phone($customer->phone)], $locale);
    }

    /** The shop's price for the customer's latest open request; the reply is for the staff member. */
    protected function sendQuote(Merchant $merchant, array $quote, string $locale): string
    {
        $request = $quote['number'] !== null
            ? Quote::query()->where('number', $quote['number'])->first()
            : Quote::query()->where('status', 'requested')->latest()->first();
        if (! $request || ! in_array($request->status, ['requested', 'sent'], true)) {
            return __('whatsapp.quote.staff_none', [], $locale);
        }
        if (! QuoteService::priceAllowed($merchant, $quote['price'])) {
            return __('whatsapp.quote.staff_bad_price', [], $locale);
        }
        $sent = app(QuoteService::class)->send($merchant, $request, $quote['price'], $quote['title']);

        return $sent
            ? __('whatsapp.quote.staff_sent', ['number' => $sent->number, 'price' => Money::format((float) $sent->price, $merchant->currency)], $locale)
            : __('whatsapp.quote.staff_none', [], $locale);
    }

    protected function startShift(Driver $driver, string $locale): string
    {
        if ($driver->status === 'on_duty') {
            return __('whatsapp.driver.already_on_shift', [], $locale);
        }

        $driver->forceFill(['status' => 'on_duty', 'shift_started_at' => now()])->save();

        return __('whatsapp.driver.shift_started', ['name' => $driver->name], $locale);
    }

    protected function endShift(Merchant $merchant, Driver $driver, string $locale): string
    {
        if ($driver->active_order_id) {
            $number = Order::query()->whereKey($driver->active_order_id)->value('number');

            return __('whatsapp.driver.shift_busy', ['number' => $number], $locale);
        }

        $since = $driver->shift_started_at ?? now()->startOfDay();
        $delivered = Order::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'delivered')
            ->where('delivered_at', '>=', $since)
            ->get(['total', 'total_lbp', 'cash_main', 'payment_method', 'payment_currency']);

        $driver->forceFill(['status' => 'off_duty', 'shift_started_at' => null])->save();

        return __('whatsapp.driver.shift_ended', [
            'name' => $driver->name,
            'count' => $delivered->count(),
            'cash' => $this->cashSummary($merchant, $delivered, $locale),
        ], $locale);
    }

    /**
     * Cash collected per currency, e.g. "36.00 USD + 1,074,000 LBP".
     */
    protected function cashSummary(Merchant $merchant, $orders, string $locale): string
    {
        $cash = $orders->where('payment_method', 'cash');
        $lbp = $cash->whereIn('payment_currency', ['LBP', 'MIXED'])->sum(fn ($o) => (float) $o->total_lbp);
        $main = $cash->sum(fn ($o) => match ($o->payment_currency) {
            'LBP' => 0.0,
            'MIXED' => (float) $o->cash_main,
            default => (float) $o->total,
        });

        $parts = [];
        if ($main > 0) {
            $parts[] = Money::format($main, $merchant->currency);
        }
        if ($lbp > 0) {
            $parts[] = Money::lbp($lbp);
        }

        return $parts ? implode(' + ', $parts) : __('whatsapp.driver.nothing', [], $locale);
    }

    protected function orderAction(Merchant $merchant, Driver $driver, string $action, string $orderId, string $locale): string
    {
        $order = Order::query()->whereKey($orderId)->first();
        if (! $order || $order->driver_id !== $driver->id) {
            return __('whatsapp.driver.not_yours', [], $locale);
        }

        // "I'm outside": the customer is told the driver is at the door (no status change).
        if ($action === 'outside') {
            $order->loadMissing('customer');
            $customerLocale = $order->customer?->locale ?? $locale;
            try {
                $driverPhone = (string) ($driver->whatsapp_number ?: $driver->phone);
                app(CustomerMessenger::class)->send(
                    $merchant, (string) $order->customer?->phone,
                    ['type' => 'text', 'text' => __('whatsapp.status.outside', [
                        'number' => $order->number, 'driver' => $driver->name, 'phone' => OrderMessage::phone($driverPhone),
                    ], $customerLocale)],
                    CustomerTemplates::OUTSIDE, [$driver->name, (string) $order->number, $driverPhone], $customerLocale,
                );
            } catch (\Throwable $e) {
                Log::warning('Driver outside message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }

            return __('whatsapp.driver.customer_told', [], $locale);
        }

        try {
            $this->orders->transition($merchant, $order->id, $action);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? __('whatsapp.error', [], $locale);
        }

        if ($action === 'picked_up') {
            // The next card: "I'm outside" when arriving, then "Delivered".
            $this->sendOnTheWayCard($merchant, $driver, $order->fresh(), $locale);

            return '';
        }

        if ($action === 'failed') {
            return __('whatsapp.driver.failed_closed', ['number' => $order->number], $locale);
        }

        return __('whatsapp.driver.delivered', ['number' => $order->number], $locale);
    }

    protected function sendOnTheWayCard(Merchant $merchant, Driver $driver, Order $order, string $locale): void
    {
        $body = __('whatsapp.driver.on_the_way', OrderMessage::forDriver($order, $merchant, $locale), $locale);
        try {
            $this->whatsapp->sendToCustomer($merchant, $driver->whatsapp_number ?: $driver->phone, ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => "drv:outside:{$order->id}", 'title' => mb_substr(__('whatsapp.driver.button_outside', [], $locale), 0, 20)]],
                    ['type' => 'reply', 'reply' => ['id' => "drv:delivered:{$order->id}", 'title' => mb_substr(__('whatsapp.driver.button_delivered', [], $locale), 0, 20)]],
                    ['type' => 'reply', 'reply' => ['id' => "drv:failed:{$order->id}", 'title' => mb_substr(__('whatsapp.driver.button_failed', [], $locale), 0, 20)]],
                ]],
            ]]);
        } catch (\Throwable $e) {
            Log::warning('Driver on-the-way card failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    protected function reply(Merchant $merchant, string $to, string $text): void
    {
        if ($text === '') {
            return;
        }

        try {
            $this->whatsapp->sendToCustomer($merchant, $to, ['type' => 'text', 'text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('Driver reply failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }
    }
}

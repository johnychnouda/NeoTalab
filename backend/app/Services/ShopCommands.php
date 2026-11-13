<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The shop answers from the shop phone, in the customer's own chat, by typing: ✅ accepts (or confirms the Whish money),
 * ❌ refuses, "ready", "out", "done" and "delay" move the order on, "refunded" closes a refund. Nothing to tap and
 * nothing for the customer to see: the messages the customer already gets are the cue.
 */
class ShopCommands
{
    public const ACCEPT = ['✅', '✔', '✔️', '☑', '☑️', '👍'];

    public const REFUSE = ['❌', '✖', '✖️', '👎'];

    private const READY = ['ready', 'جاهز', 'prêt', 'pret'];

    private const OUT = ['out', 'on the way', 'out for delivery', 'في الطريق', 'طالع', 'en route', 'parti'];

    private const DONE = ['done', 'delivered', 'collected', 'تم', 'تم التسليم', 'livré', 'livre', 'récupéré', 'recupere'];

    private const DELAY = ['delay', 'delay 1 day', 'تأجيل', 'reporter'];

    private const OPEN = ['confirmed', 'preparing', 'ready', 'assigned', 'picked_up'];

    public function __construct(
        protected OrderConfirmation $confirmation,
        protected OrderService $orders,
        protected DriverDispatcher $dispatcher,
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * Button tap on a shop ticket (report WhatsApp): stf:accept:ORDER_ID / ready / picked_up / delivered / …
     * Same outcomes as typing ✅ / ready / out / done in the customer chat.
     */
    public function handleButton(Merchant $merchant, string $buttonId): bool
    {
        if (! preg_match('/^stf:(accept|reject|wh_ok|wh_no|ready|picked_up|delivered|delay):([0-9a-f-]{36})$/i', $buttonId, $m)) {
            return false;
        }
        $action = strtolower($m[1]);
        $order = Order::query()->where('merchant_id', $merchant->id)->whereKey($m[2])->first();
        if (! $order) {
            return false;
        }

        $this->run(function () use ($merchant, $order, $action) {
            match ($action) {
                'accept' => $this->confirmation->accept($merchant, $order),
                'reject' => $this->orders->transition($merchant, $order->id, 'reject'),
                'wh_ok' => $this->confirmation->shopConfirms($merchant, $order),
                'wh_no' => $this->confirmation->shopRefuses($merchant, $order),
                'delay' => $this->confirmation->delay($merchant, $order),
                default => $this->orders->transition($merchant, $order->id, $action),
            };
        }, $order);

        return true;
    }

    /** Whether the typed text was a shop command for this customer's chat (and has been carried out). */
    public function handle(Merchant $merchant, string $customerPhone, string $typed): bool
    {
        $text = mb_strtolower(trim(preg_replace('/[.!]+$/u', '', $typed) ?? ''));
        if ($text === '') {
            return false;
        }
        $customer = Customer::query()->where('phone', $customerPhone)->first();
        if (! $customer) {
            return false;
        }

        if (preg_match('/^(?:refunded|refund done|مسترد|remboursé|rembourse)\s*#?\s*(\d+)?$/iu', $text, $m)) {
            return $this->refunded($customer, isset($m[1]) ? (int) $m[1] : null);
        }
        if (preg_match('/^(?:retry|reassign|إعادة|اعادة|relancer)\s*#?\s*(\d+)?$/iu', $text, $m)) {
            return $this->retryFailed($merchant, $customer, isset($m[1]) ? (int) $m[1] : null);
        }
        if (in_array($text, self::ACCEPT, true) || in_array($text, self::REFUSE, true)) {
            return $this->answer($merchant, $customer, in_array($text, self::ACCEPT, true));
        }

        $action = match (true) {
            in_array($text, self::READY, true) => 'ready',
            in_array($text, self::OUT, true) => 'picked_up',
            in_array($text, self::DONE, true) => 'delivered',
            in_array($text, self::DELAY, true) => 'delay',
            default => null,
        };

        return $action !== null && $this->move($merchant, $customer, $action);
    }

    /** ✅ / ❌: the order waiting for the shop, else the Whish payment waiting to be checked, else an item waiting for the team's check. */
    protected function answer(Merchant $merchant, Customer $customer, bool $yes): bool
    {
        $mine = Order::query()->where('customer_id', $customer->id);

        $waiting = (clone $mine)->where('status', 'placed')->where('created_at', '>=', now()->subHours(6))->latest()->first();
        if ($waiting) {
            $this->run(fn () => $yes
                ? $this->confirmation->accept($merchant, $waiting)
                : $this->orders->transition($merchant, $waiting->id, 'reject'), $waiting);

            return true;
        }

        $paying = (clone $mine)->where('payment_status', 'awaiting_check')->latest()->first();
        if ($paying) {
            $this->run(fn () => $yes
                ? $this->confirmation->shopConfirms($merchant, $paying)
                : $this->confirmation->shopRefuses($merchant, $paying), $paying);

            return true;
        }

        $productId = $customer->hasPendingCheck();
        $product = $productId ? Product::query()->find($productId) : null;
        if ($product) {
            $this->answerCheck($merchant, $customer, $product, $yes);

            return true;
        }

        return false;
    }

    /** ready / out / done / delay on the customer's open order. */
    protected function move(Merchant $merchant, Customer $customer, string $action): bool
    {
        $order = Order::query()->where('customer_id', $customer->id)->whereIn('status', self::OPEN)->latest()->first();
        if (! $order) {
            return false;
        }

        $this->run(fn () => $action === 'delay'
            ? $this->confirmation->delay($merchant, $order)
            : $this->orders->transition($merchant, $order->id, $action), $order);

        return true;
    }

    protected function refunded(Customer $customer, ?int $number): bool
    {
        $order = Order::query()->where('customer_id', $customer->id)->where('payment_status', 'refund_due')
            ->when($number !== null, fn ($q) => $q->where('number', $number))->latest()->first();
        if (! $order) {
            return false;
        }
        $order->forceFill(['payment_status' => 'refunded'])->save();

        return true;
    }

    /** After a failed delivery: put the order back to ready so another driver can take it. */
    protected function retryFailed(Merchant $merchant, Customer $customer, ?int $number): bool
    {
        $order = Order::query()->where('customer_id', $customer->id)->where('status', 'failed')
            ->when($number !== null, fn ($q) => $q->where('number', $number))->latest()->first();
        if (! $order) {
            $this->note($merchant, $customer, __('whatsapp.shop.retry_none', [], $merchant->locale ?? 'en'));

            return true;
        }

        $order->forceFill([
            'status' => 'ready',
            // A paid Whish order was flagged for refund when the delivery failed: it is going out again, so nothing is owed.
            'payment_status' => $order->payment_status === 'refund_due' ? 'paid' : $order->payment_status,
            'driver_id' => null,
            'picked_up_at' => null,
            'cancel_reason' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
        ])->save();

        $this->dispatcher->dispatchWaiting($merchant);

        $this->note($merchant, $customer, __('whatsapp.shop.retry_ok', ['number' => $order->number], $merchant->locale ?? 'en'));

        return true;
    }

    /** A short answer to a shop command, in the chat where the shop typed it. */
    protected function note(Merchant $merchant, Customer $customer, string $text): void
    {
        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $customer->phone, ['type' => 'text', 'text' => $text]);
        } catch (\Throwable $e) {
            Log::info('Shop command answer not delivered', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
        }
    }

    /** The shop approved or refused an item for a customer: the customer is told (an approval lasts a day, for one order). */
    public function answerCheck(Merchant $merchant, Customer $customer, Product $product, bool $approve): void
    {
        if ($approve) {
            $customer->markCheck($product->id, true);
        } else {
            $customer->useApproval($product->id);
        }
        $customer->forceFill(['bot_paused_until' => null])->save();

        $text = __($approve ? 'whatsapp.check.approved' : 'whatsapp.check.refused', ['item' => $product->name, 'shop' => $merchant->name], $customer->locale ?? 'en');
        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $customer->phone, ['type' => 'text', 'text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('Check answer not delivered to the customer', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }
    }

    private function run(\Closure $do, Order $order): void
    {
        try {
            $do();
        } catch (ValidationException $e) {
            Log::info('Shop command refused', ['order_id' => $order->id, 'error' => collect($e->errors())->flatten()->first()]);
        } catch (\Throwable $e) {
            Log::warning('Shop command failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}

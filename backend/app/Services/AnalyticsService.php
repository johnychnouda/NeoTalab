<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Quantity;

class AnalyticsService
{
    /**
     * @return array<string,mixed>
     */
    public function dashboard(Merchant $merchant, int $days = 30): array
    {
        $since = now()->subDays($days);

        $orders = Order::query()
            ->where('merchant_id', $merchant->id)
            ->where('created_at', '>=', $since)
            ->get();

        $delivered = $orders->where('status', 'delivered');

        $summary = [
            'completed_orders' => $delivered->count(),
            'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
            'rejected_orders' => $orders->where('status', 'rejected')->count(),
            'gross_revenue' => (float) $delivered->sum('total'),
            'avg_order_value' => $delivered->count() ? (float) ($delivered->sum('total') / $delivered->count()) : 0,
            'avg_response_mins' => 0,
        ];

        $trend = $delivered
            ->groupBy(fn ($o) => $o->created_at->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'orders' => $group->count(),
                'revenue' => (float) $group->sum('total'),
            ])
            ->values()
            ->all();

        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.merchant_id', $merchant->id)
            ->where('orders.status', 'delivered')
            ->where('orders.created_at', '>=', $since)
            ->selectRaw('order_items.product_name, SUM(order_items.quantity) as qty, SUM(order_items.item_total) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'product_name' => $r->product_name,
                'qty' => Quantity::from($r->qty),
                'revenue' => (float) $r->revenue,
            ])
            ->all();

        $topCustomers = Customer::query()
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('total_spent')
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'name' => $c->name,
                'phone' => $c->phone,
                'orders' => $c->total_orders,
                'total_spent' => (float) $c->total_spent,
            ])
            ->all();

        $driverStats = Driver::query()
            ->where('merchant_id', $merchant->id)
            ->where('total_deliveries', '>', 0)
            ->orderByDesc('total_deliveries')
            ->get()
            ->map(fn ($d) => [
                'name' => $d->name,
                'deliveries' => $d->total_deliveries,
                'avg_delivery_mins' => 0,
            ])
            ->all();

        return [
            'period' => "{$days} days",
            'summary' => $summary,
            'trend' => $trend,
            'topProducts' => $topProducts,
            'topCustomers' => $topCustomers,
            'paymentBreakdown' => [],
            'driverStats' => $driverStats,
            'ratings' => $this->ratings($since),
        ];
    }

    /**
     * Average of every answer (order, service, delivery person), the average of each, and the latest orders with a
     * bad answer (1 to 2 out of 5) so the merchant can follow up.
     *
     * @return array{average: ?float, count: int, by: array<string, ?float>, low: list<array<string, mixed>>}
     */
    protected function ratings($since): array
    {
        $rated = Order::query()->with('customer')->where('rated_at', '>=', $since)
            ->where(fn ($q) => $q->whereNotNull('rating')->orWhereNotNull('rating_service')->orWhereNotNull('rating_delivery'))->get();

        $avg = fn (iterable $values) => ($v = collect($values)->filter(fn ($x) => $x !== null)->values())->isEmpty() ? null : round((float) $v->avg(), 1);
        $answers = fn (Order $o) => array_filter(['order' => $o->rating, 'service' => $o->rating_service, 'delivery' => $o->rating_delivery], fn ($x) => $x !== null);

        return [
            'average' => $avg($rated->flatMap(fn (Order $o) => array_values($answers($o)))),
            'count' => $rated->count(),
            'by' => ['order' => $avg($rated->pluck('rating')), 'service' => $avg($rated->pluck('rating_service')), 'delivery' => $avg($rated->pluck('rating_delivery'))],
            'low' => $rated->filter(fn (Order $o) => $answers($o) !== [] && min($answers($o)) <= 2)->sortByDesc('rated_at')->take(20)->map(function (Order $o) use ($answers) {
                $worst = $answers($o);
                asort($worst);

                return [
                    'number' => $o->number,
                    'rating' => (int) reset($worst),
                    'what' => (string) array_key_first($worst),
                    'customer_name' => $o->customer?->name,
                    'customer_phone' => $o->customer?->phone,
                    'rated_at' => $o->rated_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;

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
                'qty' => (int) $r->qty,
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
        ];
    }
}

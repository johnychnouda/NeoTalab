<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nightly summary sent from the shop's own WhatsApp number to the owner's personal number
 * (`report_whatsapp`). Uses the shop's approved `neotalab_daily_report` template so it arrives
 * outside Meta's 24h window. Until that is approved it goes as plain text only when the owner
 * messaged the shop number in the last 24h; otherwise Meta would accept it and drop it silently, so
 * the report is skipped with a warning (Owner → Inbox → System).
 */
class DailyReportService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected CurrentTenant $tenant,
        protected ShopTemplateService $templates,
        protected StaffMessenger $staff,
    ) {}

    /**
     * Send every report that is due now. Returns how many were sent.
     */
    public function sendDue(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        foreach (Merchant::query()->where('status', 'active')->get() as $merchant) {
            $shop = ShopSettings::for($merchant);
            if ($shop->reportWhatsapp() === '' || ! MerchantWhatsAppCredentials::resolve($merchant)) {
                continue;
            }

            $local = $now->copy()->setTimezone($merchant->timezone ?: config('app.timezone'));
            $settings = $merchant->settings ?? [];
            if ($local->hour !== $shop->reportHour() || ($settings['report_last_sent'] ?? null) === $local->toDateString()) {
                continue;
            }

            if (! $this->templates->isApproved($merchant, ShopTemplateService::DAILY_REPORT)
                && ! $this->staff->numberInWindow($merchant, $shop->reportWhatsapp())) {
                Log::warning('Daily report not sent: its WhatsApp template is not approved yet and the report number has not messaged the shop number in the last 24 hours', [
                    'merchant_id' => $merchant->id, 'shop' => $merchant->name,
                ]);

                continue;
            }

            try {
                $this->send($merchant, $local);
                $settings['report_last_sent'] = $local->toDateString();
                $merchant->forceFill(['settings' => $settings])->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Daily report failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * @return array<string, string>
     */
    public function figures(Merchant $merchant, Carbon $localDay): array
    {
        $from = $localDay->copy()->startOfDay()->utc();
        $to = $localDay->copy()->endOfDay()->utc();
        $locale = $merchant->locale ?? 'en';

        $this->tenant->set($merchant);
        try {
            $delivered = Order::query()->where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->get();
            $late = Order::query()->whereBetween('late_notified_at', [$from, $to])->count();
            $avg = Order::query()->whereBetween('rated_at', [$from, $to])->avg('rating');
            $top = OrderItem::query()
                ->whereIn('order_id', $delivered->pluck('id'))
                ->selectRaw('product_name, SUM(quantity) as qty')
                ->groupBy('product_name')
                ->orderByDesc('qty')
                ->limit(3)
                ->get()
                ->map(fn ($r) => "{$r->product_name} ×{$r->qty}")
                ->implode(', ');
        } finally {
            $this->tenant->forget();
        }

        $sales = Money::format((float) $delivered->sum('total'), $merchant->currency);
        $lbp = (float) $delivered->where('payment_currency', 'LBP')->sum(fn ($o) => (float) $o->total_lbp);
        if ($lbp > 0) {
            $sales .= ' (incl. '.Money::lbp($lbp).' cash)';
        }

        return [
            'shop' => $merchant->name,
            'date' => $localDay->toDateString(),
            'orders' => (string) $delivered->count(),
            'sales' => $sales,
            'rating' => $avg !== null ? number_format((float) $avg, 1).' / 5' : __('whatsapp.report.none', [], $locale),
            'late' => (string) $late,
            'top' => $top !== '' ? $top : __('whatsapp.report.none', [], $locale),
        ];
    }

    protected function send(Merchant $merchant, Carbon $localDay): void
    {
        $figures = $this->figures($merchant, $localDay);
        $to = ShopSettings::for($merchant)->reportWhatsapp();

        $message = $this->templates->isApproved($merchant, ShopTemplateService::DAILY_REPORT)
            ? ShopTemplateService::message(ShopTemplateService::DAILY_REPORT, array_values($figures))
            : ['type' => 'text', 'text' => __('whatsapp.report.daily', $figures, $merchant->locale ?? 'en')];

        $this->tenant->set($merchant);
        try {
            $this->whatsapp->sendToCustomer($merchant, $to, $message);
        } finally {
            $this->tenant->forget();
        }
    }
}

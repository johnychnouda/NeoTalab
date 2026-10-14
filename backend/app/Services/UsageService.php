<?php

namespace App\Services;

use App\Models\Merchant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-shop monthly usage for the owner portal: what the AI cost NeoTalab, and how many
 * WhatsApp messages each shop number sent (billed by Meta to the shop).
 */
class UsageService
{
    /**
     * @param  array<string, mixed>|null  $usage  OpenAI "usage" object
     */
    public function recordAi(?string $merchantId, string $model, ?array $usage): void
    {
        if (! $merchantId || ! $usage) {
            return;
        }

        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $cached = min($input, (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0));
        $output = (int) ($usage['completion_tokens'] ?? 0);
        $cost = $this->aiCost($model, $input, $cached, $output);
        $month = now()->format('Y-m');

        DB::table('ai_usage')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'merchant_id' => $merchantId, 'month' => $month,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_usage')->where('merchant_id', $merchantId)->where('month', $month)->update([
            'requests' => DB::raw('requests + 1'),
            'input_tokens' => DB::raw('input_tokens + '.$input),
            'cached_tokens' => DB::raw('cached_tokens + '.$cached),
            'output_tokens' => DB::raw('output_tokens + '.$output),
            'cost_usd' => DB::raw('cost_usd + '.number_format($cost, 6, '.', '')),
            'updated_at' => now(),
        ]);
    }

    public function aiCost(string $model, int $input, int $cached, int $output): float
    {
        $prices = $this->modelPrices($model);

        return (($input - $cached) * $prices['input'] + $cached * $prices['cached'] + $output * $prices['output']) / 1_000_000;
    }

    /**
     * @return array{month: string, rows: list<array<string, mixed>>, totals: array<string, float|int>}
     */
    public function report(string $month): array
    {
        $from = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
        $to = $from->copy()->addMonth();

        $ai = DB::table('ai_usage')->where('month', $month)->get()->keyBy('merchant_id');
        $wa = DB::table('whatsapp_messages')->where('direction', 'outbound')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("merchant_id, sum(case when message_type = 'template' then 1 else 0 end) as templates, count(*) as total")
            ->groupBy('merchant_id')->get()->keyBy('merchant_id');
        $orders = DB::table('orders')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->selectRaw('merchant_id, count(*) as n')->groupBy('merchant_id')->pluck('n', 'merchant_id');

        $cfg = config('usage.whatsapp');
        $rows = [];
        foreach (Merchant::query()->orderBy('name')->get(['id', 'name']) as $merchant) {
            $a = $ai->get($merchant->id);
            $w = $wa->get($merchant->id);
            $templates = (int) ($w->templates ?? 0);
            $service = (int) ($w->total ?? 0) - $templates;
            $paidService = max(0, $service - $cfg['free_service_messages']);
            $orderCount = (int) ($orders[$merchant->id] ?? 0);
            $aiCost = round((float) ($a->cost_usd ?? 0), 4);

            $rows[] = [
                'merchant_id' => $merchant->id,
                'shop' => $merchant->name,
                'orders' => $orderCount,
                'ai_requests' => (int) ($a->requests ?? 0),
                'ai_tokens' => (int) ($a->input_tokens ?? 0) + (int) ($a->output_tokens ?? 0),
                'ai_cached_share' => ($a->input_tokens ?? 0) > 0 ? round($a->cached_tokens / $a->input_tokens, 2) : 0,
                'ai_cost' => $aiCost,
                'ai_cost_per_order' => $orderCount > 0 ? round($aiCost / $orderCount, 4) : null,
                'wa_messages' => $service,
                'wa_templates' => $templates,
                'wa_free_left' => max(0, $cfg['free_service_messages'] - $service),
                'meta_cost' => round($paidService * $cfg['service_rate'] + $templates * $cfg['template_rate'], 4),
            ];
        }

        return [
            'month' => $month,
            'rows' => $rows,
            'totals' => [
                'orders' => array_sum(array_column($rows, 'orders')),
                'ai_requests' => array_sum(array_column($rows, 'ai_requests')),
                'ai_cost' => round(array_sum(array_column($rows, 'ai_cost')), 4),
                'wa_messages' => array_sum(array_column($rows, 'wa_messages')) + array_sum(array_column($rows, 'wa_templates')),
                'meta_cost' => round(array_sum(array_column($rows, 'meta_cost')), 4),
            ],
        ];
    }

    /**
     * @return array{input: float, cached: float, output: float}
     */
    private function modelPrices(string $model): array
    {
        $models = config('usage.ai_models');
        if (isset($models[$model])) {
            return $models[$model];
        }
        foreach ($models as $name => $prices) {
            if (str_starts_with($model, $name.'-')) { // dated snapshots
                return $prices;
            }
        }

        return $models['gpt-4.1-mini'];
    }
}

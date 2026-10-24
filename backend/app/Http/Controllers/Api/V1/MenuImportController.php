<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MenuImportService;
use App\Support\CurrentTenant;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuImportController extends Controller
{
    public function preview(Request $request, MenuImportService $imports): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $items = $imports->preview($request->file('file'));
        $rate = $this->lbpRate();

        // A shop that takes lira gets its lira prices converted. Each row says whether its price is in lira; the merchant can switch any row (or all) in the preview.
        return response()->json([
            'items' => $rate === null ? array_map(fn ($i) => ['lbp' => false] + $i, $items) : $items,
            'lbp_rate' => $rate,
            'prices_in_lbp' => $rate !== null && $imports->pricesInLbp,
        ]);
    }

    public function store(Request $request, MenuImportService $imports): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.MenuImportService::MAX_ROWS],
            'items.*.name' => ['required', 'string', 'max:190'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.category' => ['nullable', 'string', 'max:120'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.lbp' => ['sometimes', 'boolean'],
            'items.*.duration_mins' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'prices_in_lbp' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['result' => $imports->import($data['items'], $this->lbpRate(), (bool) ($data['prices_in_lbp'] ?? false))]);
    }

    /** LBP per 1 unit of the shop currency, when the shop prices in another currency and takes lira. */
    private function lbpRate(): ?float
    {
        $shop = app(CurrentTenant::class)->get();

        return $shop && strtoupper((string) ($shop->currency ?: 'USD')) !== 'LBP' ? ShopSettings::for($shop)->lbpRate() : null;
    }
}

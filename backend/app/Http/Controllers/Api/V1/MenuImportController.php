<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MenuImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuImportController extends Controller
{
    public function preview(Request $request, MenuImportService $imports): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        return response()->json(['items' => $imports->preview($request->file('file'))]);
    }

    public function store(Request $request, MenuImportService $imports): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.MenuImportService::MAX_ROWS],
            'items.*.name' => ['required', 'string', 'max:190'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.category' => ['nullable', 'string', 'max:120'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['result' => $imports->import($data['items'])]);
    }
}

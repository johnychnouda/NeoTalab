<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreVariantRequest;
use App\Http\Requests\Catalog\UpdateVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class ProductVariantController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function store(StoreVariantRequest $request, string $product): JsonResponse
    {
        // Tenant-scoped parent lookup: injecting another merchant's product id 404s here.
        $parent = Product::query()->findOrFail($product);
        $variant = $this->catalog->addVariant($parent, $request->validated());

        return (new ProductVariantResource($variant))->response()->setStatusCode(201);
    }

    public function update(UpdateVariantRequest $request, string $variant): ProductVariantResource
    {
        $model = ProductVariant::query()->findOrFail($variant);

        return new ProductVariantResource($this->catalog->updateVariant($model, $request->validated()));
    }

    public function destroy(string $variant): JsonResponse
    {
        ProductVariant::query()->findOrFail($variant)->delete();

        return response()->json(['message' => 'Variant deleted.']);
    }
}

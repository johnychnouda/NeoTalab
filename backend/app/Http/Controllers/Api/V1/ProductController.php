<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->catalog->listProducts(
            $request->only(['q', 'category_id', 'is_active', 'is_available', 'per_page'])
        ));
    }

    /** Save the order the items were dragged into (inside their category). */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['string']]);
        $this->catalog->reorderProducts($data['ids']);

        return response()->json(['message' => 'Order saved.']);
    }

    public function show(string $product): ProductResource
    {
        return new ProductResource(
            Product::with(['category', 'variants', 'modifierGroups.modifiers', 'optionStocks'])->findOrFail($product)
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->catalog->createProduct($request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function options(Request $request, string $product): ProductResource
    {
        $data = $request->validate([
            'variants' => ['present', 'array', 'max:20'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.name' => ['required', 'string', 'max:60'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0.01'], // empty: same as the item's price
            'groups' => ['present', 'array', 'max:10'],
            'groups.*.id' => ['nullable', 'string'],
            'groups.*.name' => ['required', 'string', 'max:60'],
            'groups.*.required' => ['sometimes', 'boolean'],
            'groups.*.max_select' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'groups.*.modifiers' => ['required', 'array', 'min:1', 'max:20'],
            'groups.*.modifiers.*.id' => ['nullable', 'string'],
            'groups.*.modifiers.*.name' => ['required', 'string', 'max:60'],
            'groups.*.modifiers.*.price_delta' => ['nullable', 'numeric', 'min:0'],
        ]);

        return new ProductResource($this->catalog->syncOptions(Product::query()->findOrFail($product), $data));
    }

    /**
     * Stock, set up when goods arrive: the item's count, each size's, and size × option (M in Red).
     * Orders lower it and cancellations give it back; an empty number stops counting that one.
     */
    public function stock(Request $request, string $product): ProductResource
    {
        $model = Product::query()->with(['variants', 'modifierGroups.modifiers'])->findOrFail($product);
        $variantIds = $model->variants->pluck('id')->all();
        $modifierIds = $model->modifierGroups->flatMap->modifiers->pluck('id')->all();
        $data = $request->validate([
            'track_inventory' => ['required', 'boolean'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'required_if:track_inventory,true'],
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['required', 'string', Rule::in($variantIds)],
            'variants.*.stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'options' => ['sometimes', 'array', 'max:400'],
            'options.*.variant_id' => ['nullable', 'string', Rule::in($variantIds)],
            'options.*.modifier_id' => ['required', 'string', Rule::in($modifierIds)],
            'options.*.stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        DB::transaction(function () use ($model, $data) {
            $model->update(['track_inventory' => $data['track_inventory'], 'stock_quantity' => $data['track_inventory'] ? $data['stock_quantity'] : null]);
            foreach ($data['variants'] ?? [] as $v) {
                ProductVariant::query()->whereKey($v['id'])->update(['stock_quantity' => $v['stock_quantity'] ?? null]);
            }
            foreach ($data['options'] ?? [] as $o) {
                $key = ['product_id' => $model->id, 'variant_id' => $o['variant_id'] ?? null, 'modifier_id' => $o['modifier_id']];
                if (($o['stock_quantity'] ?? null) === null) {
                    ProductStock::query()->where($key)->delete();
                } else {
                    ProductStock::query()->updateOrCreate($key, ['stock_quantity' => $o['stock_quantity']]);
                }
            }
        });

        return new ProductResource($model->fresh(['variants', 'modifierGroups.modifiers', 'optionStocks']));
    }

    public function update(UpdateProductRequest $request, string $product): ProductResource
    {
        $model = Product::query()->findOrFail($product);

        return new ProductResource($this->catalog->updateProduct($model, $request->validated()));
    }

    public function destroy(string $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);
        $this->catalog->deleteProduct($model);

        return response()->json(['message' => 'Product deleted.']);
    }
}

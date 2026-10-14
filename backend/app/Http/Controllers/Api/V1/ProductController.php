<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->catalog->listProducts(
            $request->only(['q', 'category_id', 'is_active', 'is_available', 'per_page'])
        ));
    }

    public function show(string $product): ProductResource
    {
        return new ProductResource(
            Product::with(['category', 'variants', 'modifierGroups.modifiers'])->findOrFail($product)
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
            'variants.*.price' => ['required', 'numeric', 'min:0'],
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

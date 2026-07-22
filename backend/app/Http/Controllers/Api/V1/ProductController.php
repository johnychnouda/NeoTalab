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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tenant-scoped: all lookups run through the global TenantScope (bound by the `tenant`
 * middleware), so a cross-tenant id yields 404. Same pattern for the rest of the catalog.
 */
class CategoryController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->catalog->listCategories());
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->catalog->createCategory($request->validated());

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, string $category): CategoryResource
    {
        $model = Category::query()->findOrFail($category);

        return new CategoryResource($this->catalog->updateCategory($model, $request->validated()));
    }

    public function destroy(string $category): JsonResponse
    {
        $model = Category::query()->findOrFail($category);
        $this->catalog->deleteCategory($model);

        return response()->json(['message' => 'Category deleted.']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreModifierGroupRequest;
use App\Http\Requests\Catalog\UpdateModifierGroupRequest;
use App\Http\Resources\ModifierGroupResource;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class ModifierGroupController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function store(StoreModifierGroupRequest $request, string $product): JsonResponse
    {
        $parent = Product::query()->findOrFail($product);
        $group = $this->catalog->addModifierGroup($parent, $request->validated());

        return (new ModifierGroupResource($group))->response()->setStatusCode(201);
    }

    public function update(UpdateModifierGroupRequest $request, string $group): ModifierGroupResource
    {
        $model = ModifierGroup::query()->findOrFail($group);

        return new ModifierGroupResource($this->catalog->updateModifierGroup($model, $request->validated()));
    }

    public function destroy(string $group): JsonResponse
    {
        $model = ModifierGroup::query()->findOrFail($group);
        $this->catalog->deleteModifierGroup($model);

        return response()->json(['message' => 'Modifier group deleted.']);
    }
}

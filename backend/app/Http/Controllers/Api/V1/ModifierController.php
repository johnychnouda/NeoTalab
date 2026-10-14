<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreModifierRequest;
use App\Http\Requests\Catalog\UpdateModifierRequest;
use App\Http\Resources\ModifierResource;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class ModifierController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function store(StoreModifierRequest $request, string $group): JsonResponse
    {
        $parent = ModifierGroup::query()->findOrFail($group);
        $modifier = $this->catalog->addModifier($parent, $request->validated());

        return (new ModifierResource($modifier))->response()->setStatusCode(201);
    }

    public function update(UpdateModifierRequest $request, string $modifier): ModifierResource
    {
        $model = Modifier::query()->findOrFail($modifier);

        return new ModifierResource($this->catalog->updateModifier($model, $request->validated()));
    }

    public function destroy(string $modifier): JsonResponse
    {
        Modifier::query()->findOrFail($modifier)->delete();

        return response()->json(['message' => 'Modifier deleted.']);
    }
}

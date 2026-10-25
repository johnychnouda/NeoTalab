<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The shop's team for bookings (stylists, barbers): set up once in the dashboard. */
class ServiceProviderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['providers' => ServiceProvider::query()->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $provider = ServiceProvider::create($this->validated($request) + ['sort_order' => ServiceProvider::query()->count()]);

        return response()->json(['provider' => $provider], 201);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $model = ServiceProvider::query()->findOrFail($provider);
        $model->update($this->validated($request, true));

        return response()->json(['provider' => $model->fresh()]);
    }

    public function destroy(string $provider): JsonResponse
    {
        ServiceProvider::query()->findOrFail($provider)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $services = Product::query()->whereNotNull('duration_mins')->pluck('id')->all();

        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'service_ids' => ['nullable', 'array'],
            // Only this shop's own services: another shop's id is refused.
            'service_ids.*' => ['string', Rule::in($services)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}

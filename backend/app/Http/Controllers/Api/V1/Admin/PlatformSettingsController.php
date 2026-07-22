<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Resources\PlatformSettingsResource;
use App\Services\PlatformSettingsService;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    public function __construct(protected PlatformSettingsService $settings) {}

    public function show(Request $request): PlatformSettingsResource
    {
        return new PlatformSettingsResource(
            $this->settings->get(),
            passwordChangedAt: $request->user()->password_changed_at?->toIso8601String(),
        );
    }

    public function update(UpdatePlatformSettingsRequest $request): PlatformSettingsResource
    {
        $setting = $this->settings->update($request->validated());

        return new PlatformSettingsResource(
            $setting,
            passwordChangedAt: $request->user()->password_changed_at?->toIso8601String(),
        );
    }
}

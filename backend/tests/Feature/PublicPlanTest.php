<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_gets_the_plan_set_by_the_owner(): void
    {
        $this->getJson('/api/v1/plan')->assertOk()->assertJson(['trial_days' => 3]);

        PlatformSetting::singleton()->update([
            'trial_days' => 21,
            'subscription_price' => 25,
            'subscription_yearly_price' => 250,
        ]);

        $this->getJson('/api/v1/plan')->assertOk()->assertExactJson([
            'trial_days' => 21,
            'currency' => 'USD',
            'monthly_price' => 25,
            'yearly_price' => 250,
        ]);
    }
}

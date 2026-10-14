<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_page_gets_the_trial_length_set_by_the_owner(): void
    {
        $this->getJson('/api/v1/plan')->assertOk()->assertJson(['trial_days' => 3]);

        PlatformSetting::singleton()->update(['trial_days' => 21]);

        $this->getJson('/api/v1/plan')->assertOk()->assertExactJson(['trial_days' => 21]);
    }
}

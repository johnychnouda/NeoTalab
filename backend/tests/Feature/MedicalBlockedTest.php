<?php

namespace Tests\Feature;

use App\Support\BusinessKind;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Medical businesses (clinics, dentists, doctors, pharmacies) are refused everywhere a shop can be set up. */
class MedicalBlockedTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Http::fake();
    }

    public function test_public_sign_up_refuses_medical_types(): void
    {
        foreach (['Clinic / Dentist', 'Pharmacy', 'clinic', 'Doctor'] as $type) {
            $this->postJson('/api/v1/onboarding/apply', [
                'shopName' => 'Medical Shop', 'contactName' => 'Ahmad', 'businessType' => $type,
                'whatsapp' => '+96178887777', 'city' => 'Beirut', 'region' => 'Beirut', 'street' => 'Hamra Street',
                'country' => 'LB', 'acceptedTerms' => true,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('onboarding_requests', 0);
    }

    public function test_an_owner_cannot_change_their_shop_to_a_medical_type(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $this->withToken($this->tokenFor($owner))->patchJson('/api/v1/merchant', ['business_type' => 'Pharmacy'])->assertUnprocessable();
        $this->assertNotSame('Pharmacy', $merchant->fresh()->business_type);
    }

    public function test_the_platform_owner_cannot_create_a_medical_shop(): void
    {
        $this->withToken($this->tokenFor($this->platformAdmin()))->postJson('/api/v1/admin/merchants/provision', [
            'business_name' => 'Dental Two', 'business_type' => 'Clinic / Dentist',
            'owner_name' => 'Owner', 'owner_email' => 'dental@example.com', 'owner_password' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_medical_types_are_not_offered_as_kinds_or_sign_up_types(): void
    {
        $this->assertTrue(BusinessKind::blocked('Pharmacy'));
        $this->assertTrue(BusinessKind::blocked('clinic / dentist'));
        $this->assertFalse(BusinessKind::blocked('Supermarket / Grocery'));
        $this->assertArrayNotHasKey('pharmacy', BusinessKind::KINDS);
        $this->assertArrayNotHasKey('Pharmacy', BusinessKind::TYPES);
        $this->assertArrayNotHasKey('Clinic / Dentist', BusinessKind::TYPES);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Each team member reads WhatsApp in a language of their own; empty means the shop's language. */
class TeamLanguageTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_member_is_added_with_a_language(): void
    {
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['locale' => 'en']);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/drivers', ['name' => 'Karim', 'phone' => '70 123 456', 'role' => 'driver', 'locale' => 'ar'])
            ->assertCreated()
            ->assertJsonPath('driver.locale', 'ar');

        $this->assertSame('ar', Driver::query()->withoutGlobalScopes()->sole()->locale);
    }

    public function test_the_language_can_be_changed_and_cleared(): void
    {
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['locale' => 'en']);
        $token = $this->tokenFor($owner);
        $id = $this->withToken($token)->postJson('/api/v1/drivers', ['name' => 'Karim', 'phone' => '70 123 456', 'role' => 'driver'])
            ->assertCreated()->json('driver.id');

        $this->withToken($token)->patchJson("/api/v1/drivers/{$id}", ['locale' => 'fr'])->assertOk()->assertJsonPath('driver.locale', 'fr');
        $this->withToken($token)->patchJson("/api/v1/drivers/{$id}", ['name' => 'Karim S.'])->assertOk()->assertJsonPath('driver.locale', 'fr');
        $this->withToken($token)->patchJson("/api/v1/drivers/{$id}", ['locale' => null])->assertOk()->assertJsonPath('driver.locale', null);
    }

    public function test_only_english_arabic_and_french_are_accepted(): void
    {
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/drivers', ['name' => 'Karim', 'phone' => '70 123 456', 'locale' => 'de'])
            ->assertUnprocessable();
    }

    public function test_without_a_language_a_member_reads_the_shops_language(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['locale' => 'ar']);

        $this->assertSame('ar', (new Driver)->languageFor($merchant));
        $this->assertSame('fr', (new Driver(['locale' => 'fr']))->languageFor($merchant));
        $this->assertSame('ar', (new Driver(['locale' => 'xx']))->languageFor($merchant));
    }
}

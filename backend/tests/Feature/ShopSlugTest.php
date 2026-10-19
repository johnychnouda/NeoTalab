<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use App\Services\MerchantWelcomeService;
use App\Services\TenantProvisioningService;
use App\Support\Roles;
use App\Support\ShopSlug;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ShopSlugTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_arabic_shop_names_keep_their_vowel_letters(): void
    {
        $this->assertSame('frn-almlka-walamyr', ShopSlug::from('فرن الملكة والأمير'));
        $this->assertSame('almlka', ShopSlug::from('الملكة'));
    }

    public function test_harakat_do_not_split_the_word(): void
    {
        $this->assertSame(ShopSlug::from('الملكة'), ShopSlug::from('الْمَلِكَة'));
    }

    public function test_latin_names_are_unchanged(): void
    {
        $this->assertSame('foren-al-hara', ShopSlug::from('Foren Al Hara'));
    }

    public function test_new_shop_gets_a_readable_slug_from_an_arabic_name(): void
    {
        ['merchant' => $merchant] = app(TenantProvisioningService::class)->provision(
            ['name' => 'فرن الملكة والأمير'],
            ['name' => 'Owner', 'email' => 'owner-slug@example.com', 'password' => 'a-long-test-password'],
        );

        $this->assertSame('frn-almlka-walamyr', $merchant->slug);
    }

    public function test_new_shop_login_email_uses_the_readable_slug(): void
    {
        $this->assertSame('frn-almlka-walamyr@merchant.neotalab', MerchantWelcomeService::loginEmailFromShopName('فرن الملكة والأمير'));
    }

    public function test_owner_login_email_can_be_changed_and_taken_ones_are_refused(): void
    {
        $shop = Merchant::factory()->create(['slug' => 'frn-almlk-oalamyr']);
        $owner = User::factory()->forMerchant($shop)->create(['email' => 'frnalmlkoalamyr@merchant.neotalab']);
        $owner->assignRole(Roles::MERCHANT_OWNER);
        User::factory()->create(['email' => 'taken@merchant.neotalab']);

        $this->artisan('shop:set-login-email', ['slug' => 'frn-almlk-oalamyr', 'email' => 'al-malika-wal-amir@merchant.neotalab'])->assertSuccessful();
        $this->assertSame('al-malika-wal-amir@merchant.neotalab', $owner->fresh()->email);

        $this->artisan('shop:set-login-email', ['slug' => 'al-malika-wal-amir', 'email' => 'taken@merchant.neotalab'])->assertFailed();
        $this->assertSame('al-malika-wal-amir@merchant.neotalab', $owner->fresh()->email);
    }

    public function test_rename_changes_the_slug_and_rejects_bad_or_taken_ones(): void
    {
        $shop = Merchant::factory()->create(['slug' => 'frn-almlk-oalamyr']);
        Merchant::factory()->create(['slug' => 'taken-shop']);

        $this->artisan('shop:rename-slug', ['current' => 'frn-almlk-oalamyr', 'new' => 'al-malika-wal-amir'])->assertSuccessful();
        $this->assertSame('al-malika-wal-amir', $shop->fresh()->slug);

        $this->artisan('shop:rename-slug', ['current' => 'al-malika-wal-amir', 'new' => 'taken-shop'])->assertFailed();
        $this->artisan('shop:rename-slug', ['current' => 'al-malika-wal-amir', 'new' => 'Bad Slug!'])->assertFailed();
        $this->artisan('shop:rename-slug', ['current' => 'no-such-shop', 'new' => 'anything'])->assertFailed();

        $this->assertSame('al-malika-wal-amir', $shop->fresh()->slug);
    }
}

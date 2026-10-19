<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\TenantProvisioningService;
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

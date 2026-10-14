<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Services\AI\CatalogResolver;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        app(CurrentTenant::class)->set($this->merchant);
        config(['ai.catalog.full_max' => 20, 'ai.catalog.max_items' => 10, 'ai.catalog.min_items' => 5]);
    }

    /** @return list<string> */
    private function namesFor(string $text, array $keep = []): array
    {
        return app(CatalogResolver::class)->buildCatalogSnapshot($text, $keep)->pluck('name')->all();
    }

    private function bigShop(): void
    {
        $drinks = Category::factory()->forMerchant($this->merchant)->create(['name' => 'Drinks']);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma Chicken']);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Pepsi', 'category_id' => $drinks->id]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Panadol Extra']);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'منقوشة زعتر']);
        foreach (range(1, 40) as $i) {
            Product::factory()->forMerchant($this->merchant)->create(['name' => "Item number {$i}"]);
        }
    }

    public function test_a_small_menu_is_sent_whole(): void
    {
        Product::factory()->forMerchant($this->merchant)->count(6)->create();

        $this->assertCount(6, $this->namesFor('baddi shawarma'));
    }

    public function test_a_big_menu_sends_only_matching_items_even_with_typos(): void
    {
        $this->bigShop();

        $names = $this->namesFor('baddi 2 chwarma w pepsy');
        $this->assertContains('Shawarma Chicken', $names);
        $this->assertContains('Pepsi', $names);
        $this->assertLessThanOrEqual(10, count($names));
        $this->assertGreaterThanOrEqual(5, count($names)); // topped up with other items
    }

    public function test_arabic_and_category_words_match(): void
    {
        $this->bigShop();

        $this->assertContains('منقوشة زعتر', $this->namesFor('بدي المنقوشه'));
        $this->assertContains('Pepsi', $this->namesFor('what drinks do you have'));
    }

    public function test_cart_items_are_always_kept(): void
    {
        $this->bigShop();
        $panadol = Product::query()->where('name', 'Panadol Extra')->value('id');

        $this->assertContains('Panadol Extra', $this->namesFor('and a pepsi', [$panadol]));
    }
}

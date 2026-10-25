<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MenuImportTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $this->token = $this->tokenFor($owner);
    }

    private function csv(string $content, string $name = 'menu.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function preview(UploadedFile $file)
    {
        return $this->withToken($this->token)->post('/api/v1/menu/import/preview', ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_csv_with_english_headers_and_messy_prices(): void
    {
        $file = $this->csv("Category,Name,Price,Description\nSandwiches,Shawarma,\$5,Chicken wrap\nSandwiches,Falafel,\"5,50\",\nDrinks,Laban Ayran,\"25,000 LBP\",\n,Mystery,,\n");

        $items = $this->preview($file)->assertOk()->json('items');

        $this->assertCount(3, $items, 'rows without a price are skipped');
        $this->assertSame(['Sandwiches', 'Shawarma', 5], [$items[0]['category'], $items[0]['name'], $items[0]['price']]);
        $this->assertEquals(5.5, $items[1]['price']);
        $this->assertEquals(25000, $items[2]['price']);
    }

    public function test_arabic_headers_and_arabic_digits(): void
    {
        $items = $this->preview($this->csv("الفئة,الاسم,السعر\nمشاوي,شيش طاووق,١٢\n"))->assertOk()->json('items');

        $this->assertSame([['category' => 'مشاوي', 'name' => 'شيش طاووق', 'price' => 12]], array_map(
            fn ($i) => ['category' => $i['category'], 'name' => $i['name'], 'price' => $i['price']], $items));
    }

    public function test_file_without_header_uses_name_price_category_order(): void
    {
        $items = $this->preview($this->csv("Shawarma,5,Sandwiches\nPepsi,1.5,Drinks\n"))->assertOk()->json('items');

        $this->assertSame(['Shawarma', 'Pepsi'], array_column($items, 'name'));
        $this->assertSame('Drinks', $items[1]['category']);
    }

    public function test_excel_file_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'menu').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'Price', 'Category']));
        $writer->addRow(Row::fromValues(['Manakish Zaatar', 2, 'Bakery']));
        $writer->addRow(Row::fromValues(['Cheese Croissant', 1.75, 'Bakery']));
        $writer->close();

        $items = $this->preview(new UploadedFile($path, 'menu.xlsx', null, null, true))->assertOk()->json('items');

        $this->assertSame(['Manakish Zaatar', 'Cheese Croissant'], array_column($items, 'name'));
        $this->assertEquals(1.75, $items[1]['price']);
    }

    public function test_menu_photo_needs_the_ai(): void
    {
        config(['ai.driver' => 'fake']);

        $this->preview(UploadedFile::fake()->image('menu.jpg'))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('file');
    }

    public function test_import_creates_categories_and_products_and_updates_prices_on_reimport(): void
    {
        $items = [
            ['category' => 'Sandwiches', 'name' => 'Shawarma', 'price' => 5, 'description' => 'Chicken'],
            ['category' => 'Sandwiches', 'name' => 'Falafel', 'price' => 4],
            ['category' => 'Drinks', 'name' => 'Pepsi', 'price' => 1.5],
        ];

        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => $items])
            ->assertOk()
            ->assertJsonPath('result', ['categories' => 2, 'created' => 3, 'updated' => 0]);

        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [
            ['category' => 'sandwiches', 'name' => 'SHAWARMA', 'price' => 6],
        ]])->assertOk()->assertJsonPath('result', ['categories' => 0, 'created' => 0, 'updated' => 1]);

        $shawarma = Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->where('name', 'Shawarma')->sole();
        $this->assertEquals(6, (float) $shawarma->price);
        $this->assertSame(2, Category::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
    }

    public function test_import_never_touches_another_shop(): void
    {
        [$other] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $theirCategory = Category::query()->forceCreate(['merchant_id' => $other->id, 'name' => 'Sandwiches', 'is_active' => true]);
        $theirs = Product::factory()->forMerchant($other)->create(['name' => 'Shawarma', 'price' => 3, 'category_id' => $theirCategory->id]);

        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [
            ['category' => 'Sandwiches', 'name' => 'Shawarma', 'price' => 9],
        ]])->assertOk()->assertJsonPath('result.created', 1);

        $this->assertEquals(3, (float) $theirs->fresh()->price);
    }

    public function test_lira_prices_with_subcategories_are_spotted_and_saved_in_dollars(): void
    {
        $this->merchant->forceFill(['settings' => array_merge($this->merchant->settings ?? [], ['lbp_rate' => 89500])])->save();
        $this->app['auth']->forgetGuards();

        $res = $this->preview($this->csv("Main Category,Sub Category,Item Name,Price\nDrinks,Cold,Iced Coffee,80000\nPizza,Pepperoni,Small,700000\n,,.,0\n"))->assertOk();
        $res->assertJsonPath('prices_in_lbp', true)->assertJsonPath('lbp_rate', 89500);
        $items = $res->json('items');
        $this->assertSame(['Drinks - Cold', 'Pizza - Pepperoni'], array_column($items, 'category'), 'a "." row is not an item');
        $this->assertSame('Iced Coffee', $items[0]['name']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => $items, 'prices_in_lbp' => true])->assertOk();
        $coffee = Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->where('name', 'Iced Coffee')->sole();
        $this->assertEquals(0.89, (float) $coffee->price);
    }

    public function test_one_lira_row_does_not_turn_the_dollar_rows_into_lira(): void
    {
        $this->merchant->forceFill(['settings' => array_merge($this->merchant->settings ?? [], ['lbp_rate' => 89500])])->save();
        $this->app['auth']->forgetGuards();

        $res = $this->preview($this->csv("Category,Name,Price\nSandwiches,Chicken Shawarma,4.50\nSandwiches,Beef Shawarma,5\nSandwiches,Falafel,3\n"
            ."Plates,Mixed Grill,12\nPlates,Shish Taouk,9.5\nDrinks,Pepsi,1\nDrinks,Ayran,1.25\nDrinks,Fresh Juice,\"250,000 LL\"\n"))->assertOk();

        $res->assertJsonPath('prices_in_lbp', false, 'the file is not all lira');
        $items = $res->json('items');
        $this->assertSame([false, false, false, false, false, false, false, true], array_column($items, 'lbp'));

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => $items])->assertOk()->assertJsonPath('result.created', 8);

        $prices = Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->pluck('price', 'name');
        $this->assertEquals(4.5, (float) $prices['Chicken Shawarma']);
        $this->assertEquals(1.25, (float) $prices['Ayran']);
        $this->assertEquals(2.79, (float) $prices['Fresh Juice'], '250,000 / 89,500');
    }

    public function test_a_price_that_would_become_zero_stops_the_import_and_names_the_rows(): void
    {
        $this->merchant->forceFill(['settings' => array_merge($this->merchant->settings ?? [], ['lbp_rate' => 89500])])->save();
        $this->app['auth']->forgetGuards();

        // Dollar prices sent as lira (the old whole-file switch): 4.50 / 89,500 rounds to 0.00.
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['prices_in_lbp' => true, 'items' => [
            ['category' => 'Sandwiches', 'name' => 'Chicken Shawarma', 'price' => 4.5],
            ['category' => 'Drinks', 'name' => 'Fresh Juice', 'price' => 250000],
            ['category' => 'Drinks', 'name' => 'Pepsi', 'price' => 1],
        ]])->assertStatus(422)
            ->assertJsonValidationErrorFor('items')
            ->assertJsonFragment(['items' => ['Nothing was imported. These items would cost 0.00: row 1 "Chicken Shawarma", row 3 "Pepsi". Fix their price, or mark whether it is in $ or LBP.']]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [['name' => 'Water', 'price' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrorFor('items');

        $this->assertSame(0, Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
    }

    public function test_an_exported_menu_imports_back_as_it_was(): void
    {
        $this->merchant->forceFill(['settings' => array_merge($this->merchant->settings ?? [], ['lbp_rate' => 89500])])->save();
        $this->app['auth']->forgetGuards();

        $res = $this->preview($this->csv("\u{FEFF}Category,Name,Price,Description,LBP\r\nMan2oushe,Zaatar,1.00,\"Fresh zaatar, olive oil\",90000\r\nDrinks,Pepsi,0.75,,68000\r\n"))->assertOk();

        $res->assertJsonPath('prices_in_lbp', false);
        $this->assertSame([['Man2oushe', 'Zaatar', 1, 'Fresh zaatar, olive oil'], ['Drinks', 'Pepsi', 0.75, null]], array_map(
            fn ($i) => [$i['category'], $i['name'], $i['price'], $i['description']], $res->json('items')));
    }

    public function test_a_shop_without_lira_never_gets_its_prices_converted(): void
    {
        $this->preview($this->csv("Name,Price\nSofa,1200\n"))->assertOk()->assertJsonPath('prices_in_lbp', false);
    }

    public function test_reimport_moves_items_out_of_the_default_menu_category(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [['name' => 'Iced Tea', 'price' => 80000]]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [['category' => 'Drinks', 'name' => 'Iced Tea', 'price' => 1]]])
            ->assertJsonPath('result', ['categories' => 1, 'created' => 0, 'updated' => 1]);

        $tea = Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->sole();
        $this->assertSame('Drinks', Category::query()->withoutGlobalScopes()->find($tea->category_id)->name);
        $this->assertEquals(1, (float) $tea->price);
    }

    public function test_a_category_can_go_with_its_items_and_the_menu_can_be_cleared(): void
    {
        [$other] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $theirs = Product::factory()->forMerchant($other)->create();
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [
            ['category' => 'Pizza', 'name' => 'Margherita', 'price' => 8],
            ['category' => 'Drinks', 'name' => 'Pepsi', 'price' => 1],
            ['category' => 'Drinks', 'name' => 'Water', 'price' => 1],
        ]])->assertOk();
        $mine = fn () => Product::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id);
        $pizza = Category::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->where('name', 'Pizza')->sole();
        $drinks = Category::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->where('name', 'Drinks')->sole();

        $this->withToken($this->token)->deleteJson("/api/v1/categories/{$pizza->id}?with_items=1")->assertOk();
        $this->assertSame(['Pepsi', 'Water'], $mine()->orderBy('name')->pluck('name')->all());

        $this->withToken($this->token)->deleteJson("/api/v1/categories/{$drinks->id}")->assertOk();
        $this->assertSame(2, $mine()->whereNull('category_id')->count(), 'kept, without a category');
        $this->withToken($this->token)->deleteJson('/api/v1/menu?only=uncategorized')->assertOk()->assertJsonPath('deleted', 2);

        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [['category' => 'Sweets', 'name' => 'Knefe', 'price' => 3]]])->assertOk();
        $this->withToken($this->token)->deleteJson('/api/v1/menu')->assertOk()->assertJsonPath('deleted', 1);
        $this->assertSame(0, $mine()->count());
        $this->assertSame(0, Category::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
        $this->assertNotNull($theirs->fresh(), 'another shop keeps its menu');
    }

    public function test_categories_keep_the_order_they_are_dragged_into(): void
    {
        [$other] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $theirs = Category::query()->forceCreate(['merchant_id' => $other->id, 'name' => 'Theirs', 'is_active' => true, 'sort_order' => 7]);
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [
            ['category' => 'Pizza', 'name' => 'Margherita', 'price' => 8],
            ['category' => 'Drinks', 'name' => 'Pepsi', 'price' => 1],
            ['category' => 'Sweets', 'name' => 'Knefe', 'price' => 3],
        ]])->assertOk();
        $ids = $this->withToken($this->token)->getJson('/api/v1/categories')->json('data.*.id');

        $this->withToken($this->token)->putJson('/api/v1/categories/order', ['ids' => [$ids[2], $theirs->id, $ids[0], $ids[1]]])->assertOk();

        $this->assertSame(['Sweets', 'Pizza', 'Drinks'], $this->withToken($this->token)->getJson('/api/v1/categories')->json('data.*.name'));
        $this->assertSame(7, $theirs->fresh()->sort_order, 'another shop is never touched');
    }

    public function test_items_inside_a_category_keep_the_order_they_are_dragged_into(): void
    {
        [$other] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $theirs = Product::factory()->create(['merchant_id' => $other->id, 'sort_order' => 7]);
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [
            ['category' => 'Pizza', 'name' => 'Margherita', 'price' => 8],
            ['category' => 'Pizza', 'name' => 'Pepperoni', 'price' => 9],
            ['category' => 'Pizza', 'name' => 'Quattro', 'price' => 10],
        ]])->assertOk();
        $pizza = $this->withToken($this->token)->getJson('/api/v1/categories')->json('data.0.id');
        $ids = $this->withToken($this->token)->getJson("/api/v1/products?category_id={$pizza}")->json('data.*.id');

        $this->withToken($this->token)->putJson('/api/v1/products/order', ['ids' => [$ids[2], $theirs->id, $ids[0], $ids[1]]])->assertOk();

        $this->assertSame(['Quattro', 'Margherita', 'Pepperoni'], $this->withToken($this->token)->getJson("/api/v1/products?category_id={$pizza}")->json('data.*.name'));
        $this->assertSame(7, $theirs->fresh()->sort_order, 'another shop is never touched');
    }

    public function test_staff_cannot_clear_the_menu(): void
    {
        [, $staff] = $this->merchantWithUser(Roles::MERCHANT_STAFF);
        $this->withToken($this->tokenFor($staff))->deleteJson('/api/v1/menu')->assertForbidden();
    }

    public function test_staff_cannot_import(): void
    {
        [, $staff] = $this->merchantWithUser(Roles::MERCHANT_STAFF);

        $this->withToken($this->tokenFor($staff))
            ->postJson('/api/v1/menu/import', ['items' => [['name' => 'X', 'price' => 1]]])
            ->assertForbidden();
    }

    public function test_lira_rows_are_refused_when_the_shop_has_no_lira_rate(): void
    {
        // The test shop has no lira rate (no settings.lbp_rate).
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => [
            ['name' => 'Mint lemonade', 'price' => 250000, 'category' => 'Drinks', 'lbp' => true],
        ]])->assertUnprocessable();

        $this->assertDatabaseMissing('products', ['name' => 'Mint lemonade']);
    }

    public function test_a_service_keeps_the_minutes_it_takes_from_the_file(): void
    {
        $this->merchant->forceFill(['settings' => array_merge($this->merchant->settings ?? [], ['business_mode' => 'appointments'])])->save();
        $csv = "Category,Service,Price,Minutes\nHair,Men's haircut,12,30\nHair,Beard trim,7,15\nHair,Shampoo,3,\n";

        $preview = $this->withToken($this->token)->post('/api/v1/menu/import/preview', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])->assertOk();
        $items = collect($preview->json('items'));
        $this->assertSame(30, $items->firstWhere('name', "Men's haircut")['duration_mins']);

        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => $items->all()])->assertOk();

        $this->assertSame(30, (int) Product::query()->where('name', "Men's haircut")->value('duration_mins'));
        $this->assertNull(Product::query()->where('name', 'Shampoo')->value('duration_mins'), 'no minutes: a plain item');
    }

    public function test_through_the_api_arabic_and_french_names_and_the_check_column_are_kept(): void
    {
        $items = $this->preview($this->csv("Name,Arabic name,French name,Price,Team check\nCigarettes,سجائر,Cigarettes,9,yes\n"))->assertOk()->json('items');
        $this->withToken($this->token)->postJson('/api/v1/menu/import', ['items' => $items])->assertOk();

        $product = Product::withoutGlobalScopes()->where('name', 'Cigarettes')->sole();
        $this->assertSame('سجائر', $product->name_ar);
        $this->assertSame('Cigarettes', $product->name_fr);
        $this->assertTrue($product->staff_check);
    }
}

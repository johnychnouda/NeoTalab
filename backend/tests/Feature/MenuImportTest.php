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
}

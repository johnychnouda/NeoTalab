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

    public function test_staff_cannot_import(): void
    {
        [, $staff] = $this->merchantWithUser(Roles::MERCHANT_STAFF);

        $this->withToken($this->tokenFor($staff))
            ->postJson('/api/v1/menu/import', ['items' => [['name' => 'X', 'price' => 1]]])
            ->assertForbidden();
    }
}

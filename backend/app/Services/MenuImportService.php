<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Bulk menu setup: an Excel/CSV file (or a menu photo, read by the AI) becomes a preview of
 * {category, name, price, description} rows that the merchant checks, then imports in one go.
 * Re-importing updates prices of products that already exist (same name in the same category).
 */
class MenuImportService
{
    public const MAX_ROWS = 1000;

    /** Header names recognised in EN / AR / FR. */
    private const HEADERS = [
        'category' => ['category', 'categories', 'section', 'type', 'الفئة', 'القسم', 'الصنف', 'catégorie', 'categorie', 'rubrique'],
        'name' => ['name', 'product', 'item', 'title', 'الاسم', 'المنتج', 'اسم', 'nom', 'produit', 'article'],
        'price' => ['price', 'cost', 'السعر', 'سعر', 'prix', 'tarif'],
        'description' => ['description', 'details', 'desc', 'الوصف', 'وصف', 'détails'],
        'name_ar' => ['name_ar', 'arabic', 'arabic name', 'الاسم بالعربي', 'بالعربي'],
        'name_fr' => ['name_fr', 'french', 'french name', 'nom fr'],
    ];

    /**
     * @return list<array{category: string, name: string, price: float, description: ?string, name_ar: ?string, name_fr: ?string}>
     */
    public function preview(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        $rows = match (true) {
            in_array($ext, ['csv', 'txt'], true) => $this->readSheet(new CsvReader, $file->getRealPath()),
            in_array($ext, ['xlsx'], true) => $this->readSheet(new XlsxReader, $file->getRealPath()),
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => $this->readImage($file),
            default => throw ValidationException::withMessages(['file' => ['Upload an Excel (.xlsx), CSV or a photo of the menu (.jpg / .png).']]),
        };

        $items = $this->normalize($rows);
        if ($items === []) {
            throw ValidationException::withMessages(['file' => ['No products found. Each row needs at least a product name and a price.']]);
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items  rows from preview(), possibly edited by the merchant
     * @return array{categories: int, created: int, updated: int}
     */
    public function import(array $items): array
    {
        $items = $this->normalize($items);
        $result = ['categories' => 0, 'created' => 0, 'updated' => 0];

        DB::transaction(function () use ($items, &$result) {
            $categories = Category::query()->get()->keyBy(fn (Category $c) => mb_strtolower(trim($c->name)));
            $sort = (int) Category::query()->max('sort_order');

            foreach ($items as $item) {
                $key = mb_strtolower($item['category']);
                if (! $categories->has($key)) {
                    $categories->put($key, Category::create(['name' => $item['category'], 'is_active' => true, 'sort_order' => ++$sort]));
                    $result['categories']++;
                }
                $category = $categories->get($key);

                $existing = Product::query()
                    ->where('category_id', $category->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($item['name'])])
                    ->first();

                $fields = array_filter([
                    'price' => $item['price'],
                    'description' => $item['description'],
                    'name_ar' => $item['name_ar'],
                    'name_fr' => $item['name_fr'],
                ], fn ($v) => $v !== null);

                if ($existing) {
                    $existing->update($fields);
                    $result['updated']++;
                } else {
                    Product::create($fields + [
                        'category_id' => $category->id,
                        'name' => $item['name'],
                        'is_active' => true,
                        'is_available' => true,
                    ]);
                    $result['created']++;
                }
            }
        });

        return $result;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readSheet(CsvReader|XlsxReader $reader, string $path): array
    {
        $rows = [];
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                    if (count($rows) > self::MAX_ROWS) {
                        break 2;
                    }
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $this->keyByHeader($rows);
    }

    /**
     * Map columns by header names; without a recognisable header assume name, price, category, description.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function keyByHeader(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => collect($r)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()));
        if ($rows === []) {
            return [];
        }

        $map = [];
        foreach ($rows[0] as $i => $cell) {
            $label = mb_strtolower(trim((string) $cell));
            foreach (self::HEADERS as $field => $names) {
                if (! isset($map[$field]) && in_array($label, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }

        if (isset($map['name'], $map['price'])) {
            array_shift($rows);
        } else {
            $map = ['name' => 0, 'price' => 1, 'category' => 2, 'description' => 3];
        }

        return array_map(fn (array $r) => collect($map)->map(fn (int $i) => $r[$i] ?? null)->all(), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readImage(UploadedFile $file): array
    {
        $apiKey = config('ai.openai.api_key');
        if (config('ai.driver') !== 'openai' || empty($apiKey)) {
            throw ValidationException::withMessages(['file' => ['Reading menu photos needs the AI to be switched on (OpenAI key). Upload an Excel or CSV file for now.']]);
        }

        $dataUrl = 'data:'.$file->getMimeType().';base64,'.base64_encode((string) file_get_contents($file->getRealPath()));

        $response = Http::withToken($apiKey)
            ->timeout(90)
            ->post(rtrim(config('ai.openai.base_url'), '/').'/chat/completions', [
                'model' => config('ai.openai.model'),
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Extract every product from this menu or price list. Keep names exactly as written (any language). Use the section headings as categories. Prices as plain numbers.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'menu', 'strict' => true,
                    'schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['items'],
                        'properties' => ['items' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'required' => ['category', 'name', 'price', 'description'],
                            'properties' => [
                                'category' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'price' => ['type' => ['number', 'null']],
                                'description' => ['type' => ['string', 'null']],
                            ],
                        ]]],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages(['file' => ['Could not read the photo right now. Try again or upload an Excel/CSV file.']]);
        }

        return json_decode((string) $response->json('choices.0.message.content'), true)['items'] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{category: string, name: string, price: float, description: ?string, name_ar: ?string, name_fr: ?string}>
     */
    private function normalize(array $rows): array
    {
        $items = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $price = $this->price($row['price'] ?? null);
            if ($name === '' || $price === null) {
                continue;
            }

            $text = fn ($v) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, 500);
            $items[] = [
                'category' => mb_substr(trim((string) ($row['category'] ?? '')) ?: 'Menu', 0, 120),
                'name' => mb_substr($name, 0, 190),
                'price' => $price,
                'description' => $text($row['description'] ?? null),
                'name_ar' => $text($row['name_ar'] ?? null),
                'name_fr' => $text($row['name_fr'] ?? null),
            ];
        }

        return $items;
    }

    /** "$5", "5,000 LBP", "5.50", "٥" → float; null when there is no number. */
    private function price(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? round((float) $value, 2) : null;
        }

        $s = strtr(trim((string) $value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);
        $s = preg_replace('/[^0-9.,]/', '', $s);
        if ($s === '' || $s === null) {
            return null;
        }

        // "5,000" / "1,250.50" → thousands separators; "5,50" → decimal comma.
        $s = preg_match('/,\d{3}(\D|$)/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);

        return is_numeric($s) ? round((float) $s, 2) : null;
    }
}

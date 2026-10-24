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
        'subcategory' => ['subcategory', 'sub category', 'subsection', 'sub section', 'الفئة الفرعية', 'القسم الفرعي', 'sous catégorie', 'sous categorie'],
        'name' => ['name', 'product', 'item', 'title', 'service', 'services', 'الاسم', 'المنتج', 'اسم', 'الخدمة', 'nom', 'produit', 'article', 'prestation'],
        'price' => ['price', 'cost', 'السعر', 'سعر', 'prix', 'tarif'],
        'description' => ['description', 'details', 'desc', 'الوصف', 'وصف', 'détails'],
        'name_ar' => ['name_ar', 'arabic', 'arabic name', 'الاسم بالعربي', 'بالعربي'],
        'name_fr' => ['name_fr', 'french', 'french name', 'nom fr'],
        // Minutes a service takes (salons, clinics): "duration", "minutes", "mins"…
        'duration' => ['duration', 'duration mins', 'duration min', 'duration_mins', 'minutes', 'mins', 'min', 'مدة', 'المدة', 'durée', 'duree'],
    ];

    /** Set by preview(): true when every price in the file is in Lebanese lira (each item also carries its own "lbp" flag). */
    public bool $pricesInLbp = false;

    /** Set while reading a file whose price column is headed "LBP", "Price (L.L)"… */
    private bool $lbpColumn = false;

    /** Header label words, checked in this order when no exact header name matches ("Main Category", "Price (LBP)"…). */
    private const HEADER_WORDS = [
        'subcategory' => ['sub', 'فرعي', 'sous'],
        'category' => ['categ', 'section', 'group', 'قسم', 'فئة', 'صنف'],
        'price' => ['price', 'prix', 'tarif', 'سعر'],
        'description' => ['desc', 'detail', 'وصف'],
        'name_ar' => ['arab', 'عربي'],
        'name_fr' => ['french', 'franç', 'franc'],
        'name' => ['name', 'item', 'product', 'title', 'nom', 'produit', 'article', 'اسم', 'منتج'],
    ];

    /** "LBP", "L.L", "ل.ل", "ليرة"… in a price or its header. */
    private const LBP_PATTERN = '/lbp|\bl\.?\s?l\b|lira|livre|ل\.?\s?ل|ليرة|ليره/iu';

    /** "$", "USD", "dollar" written next to a price: dollars, however big the amount. */
    private const USD_PATTERN = '/\$|usd|dollar|دولار/iu';

    /** No dollar menu has a plain price this high; a lira menu has nothing below it. */
    private const LBP_FROM = 1000;

    /**
     * @return list<array{lbp: bool, category: string, name: string, price: float, description: ?string, name_ar: ?string, name_fr: ?string}>
     */
    public function preview(UploadedFile $file): array
    {
        $this->lbpColumn = false;
        $ext = strtolower($file->getClientOriginalExtension());

        $rows = match (true) {
            in_array($ext, ['csv', 'txt'], true) => $this->readSheet(new CsvReader, $file->getRealPath()),
            in_array($ext, ['xlsx'], true) => $this->readSheet(new XlsxReader, $file->getRealPath()),
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => $this->readImage($file),
            default => throw ValidationException::withMessages(['file' => ['Upload an Excel (.xlsx), CSV or a photo of the menu (.jpg / .png).']]),
        };

        // Lira is decided row by row: one "250,000 LL" row must not turn the dollar prices around it into lira.
        $items = array_values(array_map(fn ($i) => ['lbp' => $i['raw_lbp']] + array_diff_key($i, ['raw_lbp' => true]), $this->normalize($rows)));
        if ($items === []) {
            throw ValidationException::withMessages(['file' => ['No products found. Each row needs at least a product name and a price.']]);
        }

        $this->pricesInLbp = collect($items)->every(fn ($i) => $i['lbp']);

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items  rows from preview(), possibly edited by the merchant; a row with "lbp" true is in lira
     * @param  float|null  $lbpRate  LBP per 1 unit of the shop currency; lira rows are converted with it
     * @param  bool  $allLbp  every row is in lira (the "LBP" switch for the whole file)
     * @return array{categories: int, created: int, updated: int}
     */
    public function import(array $items, ?float $lbpRate = null, bool $allLbp = false): array
    {
        $items = $this->normalize($items);
        // A lira price can only be saved as dollars with the shop's rate. Without one, refuse rather than
        // save 250,000 LL as 250,000$ on the menu.
        if (! $lbpRate && collect($items)->contains(fn ($i) => $allLbp || ! empty($i['lbp']))) {
            throw ValidationException::withMessages(['items' => ['Some prices are in lira, but this shop has no lira rate. Set the rate in Settings, or switch those rows to dollars.']]);
        }
        if ($lbpRate) {
            $items = array_map(fn ($i) => ($allLbp || ! empty($i['lbp'])) ? ['price' => round($i['price'] / $lbpRate, 2)] + $i : $i, $items);
        }

        // Nothing is sold for free by mistake: a price that comes out at 0.00 stops the whole import.
        $free = array_filter($items, fn ($i) => $i['price'] < 0.01);
        if ($free !== []) {
            $rows = implode(', ', array_map(fn ($row, $i) => 'row '.($row + 1).' "'.$i['name'].'"', array_keys($free), $free));
            throw ValidationException::withMessages(['items' => ["Nothing was imported. These items would cost 0.00: {$rows}. Fix their price, or mark whether it is in \$ or LBP."]]);
        }

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

                $named = fn () => Product::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($item['name'])]);
                // Same name in the same category; else one left in "Menu" or without a category by an
                // earlier import, which moves into its right category.
                $existing = $named()->where('category_id', $category->id)->first()
                    ?? $named()->where(fn ($q) => $q->whereNull('category_id')
                        ->orWhere('category_id', $categories->get('menu')?->id))->first();

                $fields = array_filter([
                    'category_id' => $category->id,
                    'price' => $item['price'],
                    'duration_mins' => $item['duration_mins'] ?? null,
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

        $labels = array_map(fn ($cell) => trim(preg_replace('/\s+/u', ' ', str_replace(['_', '-', '(', ')', ':'], ' ', mb_strtolower((string) $cell)))), $rows[0]);
        $map = [];
        foreach ($labels as $i => $label) {
            foreach (self::HEADERS as $field => $names) {
                if (! isset($map[$field]) && in_array($label, $names, true)) {
                    $map[$field] = $i;

                    continue 2;
                }
            }
        }
        foreach ($labels as $i => $label) {
            if ($label === '' || in_array($i, $map, true)) {
                continue;
            }
            foreach (self::HEADER_WORDS as $field => $words) {
                if (! isset($map[$field]) && collect($words)->contains(fn ($w) => str_contains($label, $w))) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        $this->lbpColumn = isset($map['price']) && preg_match(self::LBP_PATTERN, $labels[$map['price']]);

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
     * @return array<int, array{category: string, name: string, price: float, description: ?string, name_ar: ?string, name_fr: ?string, lbp: bool, raw_lbp: bool}> keyed by the row's position in $rows
     */
    private function normalize(array $rows): array
    {
        $items = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $key => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $price = $this->price($row['price'] ?? null);
            if (! preg_match('/[\p{L}\p{N}]/u', $name) || $price === null) {
                continue; // no real name (empty, ".", "-")
            }

            $text = fn ($v) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, 500);
            // A subcategory joins its category: "Pizza - Small".
            $category = implode(' - ', array_filter([$text($row['category'] ?? null), $text($row['subcategory'] ?? null)]));
            $raw = is_string($row['price'] ?? null) ? $row['price'] : '';
            $items[$key] = [
                'category' => mb_substr($category ?: 'Menu', 0, 120),
                'name' => mb_substr($name, 0, 190),
                'price' => $price,
                'description' => $text($row['description'] ?? null),
                'name_ar' => $text($row['name_ar'] ?? null),
                'name_fr' => $text($row['name_fr'] ?? null),
                // Only a whole number of minutes is used; a service without one stays a plain item.
                'duration_mins' => ($d = (int) preg_replace('/\D/', '', (string) ($row['duration'] ?? $row['duration_mins'] ?? ''))) >= 5 && $d <= 1440 ? $d : null,
                // As chosen in the preview.
                'lbp' => filter_var($row['lbp'] ?? false, FILTER_VALIDATE_BOOL),
                // As read from the file: marked lira (in the cell or the column header), or a plain amount no dollar menu has.
                'raw_lbp' => (bool) preg_match(self::LBP_PATTERN, $raw)
                    || ($this->lbpColumn && ! preg_match(self::USD_PATTERN, $raw))
                    || ($price >= self::LBP_FROM && ! preg_match(self::USD_PATTERN, $raw)),
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

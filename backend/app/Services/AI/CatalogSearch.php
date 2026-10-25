<?php

namespace App\Services\AI;

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Picks the items of a big catalog that match what the customer wrote, so the AI gets a few
 * dozen relevant items instead of the whole shop (a supermarket has thousands).
 * Matching is forgiving: Arabic letter variants, accents, plurals/prefixes and small typos
 * ("shwarma" → "Shawarma"). When few items match, the shop's best sellers fill the list.
 */
class CatalogSearch
{
    private const STOPWORDS = [
        'the', 'and', 'a', 'an', 'of', 'with', 'for', 'please', 'pls', 'want', 'like', 'have', 'you', 'do', 'any', 'some', 'can', 'get', 'me', 'is', 'it', 'to', 'in', 'on', 'my', 'how', 'much',
        'baddi', 'bade', 'badde', 'bado', 'fi', 'w', 'ma', 'shi', 'sho', 'kif', 'eza', 'iza', 'aande', 'aandkon', 'aandak',
        'je', 'veux', 'le', 'la', 'les', 'de', 'des', 'du', 'et', 'un', 'une', 'avec', 'svp', 'pour', 'vous', 'avez',
        'بدي', 'في', 'من', 'و', 'عندك', 'عندكن', 'عندكم', 'شي', 'لو', 'سمحت', 'مع', 'على', 'كيف', 'قديش', 'بكم', 'هل', 'يا',
    ];

    /**
     * @param  list<string>  $keepIds  always included (the items already in the cart)
     * @return list<string> product ids, best match first
     */
    public function pick(string $text, array $keepIds, int $max, int $min): array
    {
        $words = $this->tokens($text);

        $products = Product::query()->where('is_active', true)->get(['id', 'name', 'name_ar', 'name_fr', 'description', 'category_id']);
        $categories = Category::query()->pluck('name', 'id');

        $scores = [];
        if ($words !== []) {
            foreach ($products as $p) {
                $score = $this->score($words, $this->tokens(implode(' ', [$p->name, $p->name_ar, $p->name_fr])), 3)
                    + $this->score($words, $this->tokens((string) ($categories[$p->category_id] ?? '')), 2)
                    + $this->score($words, $this->tokens((string) $p->description), 1);
                if ($score > 0) {
                    $scores[$p->id] = $score;
                }
            }
            arsort($scores);
        }

        $ids = collect($keepIds)->merge(array_keys($scores))->unique()->take($max);

        if ($ids->count() < $min) {
            $ids = $ids->merge($this->bestSellers($products, $min))->unique()->take($min);
        }

        return $ids->values()->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<string>
     */
    private function bestSellers(Collection $products, int $limit): array
    {
        $popular = OrderItem::query()->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as n')->groupBy('product_id')->orderByDesc('n')->limit($limit)
            ->pluck('product_id')->intersect($products->pluck('id'));

        return $popular->merge($products->pluck('id'))->unique()->take($limit)->values()->all();
    }

    /**
     * @param  list<string>  $query
     * @param  list<string>  $item
     */
    private function score(array $query, array $item, int $weight): float
    {
        if ($item === []) {
            return 0;
        }

        $total = 0;
        foreach ($query as $q) {
            $best = 0;
            foreach ($item as $w) {
                $best = max($best, $this->match($q, $w));
                if ($best === 1.0) {
                    break;
                }
            }
            $total += $best;
        }

        return $total * $weight;
    }

    private function match(string $q, string $w): float
    {
        if ($q === $w) {
            return 1.0;
        }

        $short = min(mb_strlen($q), mb_strlen($w));
        if ($short >= 3 && (str_starts_with($w, $q) || str_starts_with($q, $w))) {
            return 0.8;
        }

        // Small typos, for words in Latin letters ("shwarma", "chawarma", "pepsy").
        if ($short >= 4 && preg_match('/^[a-z0-9]+$/', $q.$w)) {
            $allowed = $short >= 7 ? 2 : 1;
            if (levenshtein($q, $w) <= $allowed || levenshtein($this->squash($q), $this->squash($w)) <= $allowed) {
                return 0.7;
            }
        }

        return 0.0;
    }

    /** "chawarma" / "shawarma" / "shwarma": drop vowels and common Arabizi spellings. */
    private function squash(string $w): string
    {
        return preg_replace('/[aeiouy]/', '', strtr($w, ['ch' => 'sh', 'ou' => 'u', 'ee' => 'i', '2' => '', '3' => '', '7' => 'h']));
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text); // Arabic vowel marks, tatweel
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u',
        ]);

        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (preg_match('/^\d+$/', $t) || mb_strlen($t) < 2 || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            // "الشاورما" → "شاورما"
            if (mb_strlen($t) > 4 && str_starts_with($t, 'ال')) {
                $t = mb_substr($t, 2);
            }
            // Simple plurals: "pizzas" → "pizza".
            if (mb_strlen($t) > 4 && preg_match('/^[a-z]+s$/', $t)) {
                $t = substr($t, 0, -1);
            }
            $tokens[] = $t;
        }

        return array_values(array_unique($tokens));
    }
}

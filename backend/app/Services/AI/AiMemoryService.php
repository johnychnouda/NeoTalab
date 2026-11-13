<?php

namespace App\Services\AI;

use App\Models\AiMemory;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shop AI as a student: stores short lessons from ratings, owner notes and chat,
 * and feeds the most useful ones into every turn.
 */
class AiMemoryService
{
    public function enabled(): bool
    {
        return (bool) config('ai.learning.enabled', true);
    }

    /**
     * Memories for this turn: shop-wide + this customer's, newest / most-used first.
     *
     * @return list<string>
     */
    public function forTurn(?Customer $customer): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $limit = max(5, (int) config('ai.learning.max_per_turn', 20));

        $rows = AiMemory::query()
            ->where(function ($q) use ($customer) {
                $q->where('scope', 'shop');
                if ($customer) {
                    $q->orWhere(fn ($q2) => $q2->where('scope', 'customer')->where('customer_id', $customer->id));
                }
            })
            ->orderByDesc('hits')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'content']);

        if ($rows->isNotEmpty()) {
            AiMemory::query()->whereIn('id', $rows->pluck('id'))->update([
                'hits' => DB::raw('hits + 1'),
                'last_used_at' => now(),
            ]);
        }

        return $rows->pluck('content')->map(fn ($c) => trim((string) $c))->filter()->values()->all();
    }

    public function remember(string $content, string $scope = 'shop', string $source = 'system', ?Customer $customer = null): ?AiMemory
    {
        if (! $this->enabled()) {
            return null;
        }

        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
        if (mb_strlen($content) < 8) {
            return null;
        }
        $content = Str::limit($content, 500, '…');

        $existing = AiMemory::query()
            ->when($scope === 'customer' && $customer, fn ($q) => $q->where('customer_id', $customer->id))
            ->when($scope === 'shop', fn ($q) => $q->whereNull('customer_id'))
            ->where('scope', $scope)
            ->where('content', $content)
            ->first();

        if ($existing) {
            $existing->forceFill(['hits' => $existing->hits + 1, 'last_used_at' => now()])->save();

            return $existing;
        }

        return AiMemory::query()->create([
            'customer_id' => $scope === 'customer' ? $customer?->id : null,
            'scope' => $scope,
            'source' => $source,
            'content' => $content,
            'hits' => 1,
            'last_used_at' => now(),
        ]);
    }

    /** After Okay/Bad + typed issue: turn the complaint into a lesson. */
    public function learnFromRatingIssue(Order $order, string $issue): void
    {
        $issue = trim($issue);
        if ($issue === '') {
            return;
        }

        $this->remember(
            "Customer feedback on order #{$order->number}: {$issue}. Be careful about this next time.",
            'shop',
            'rating',
        );

        if ($order->customer) {
            $this->remember(
                "This customer said: {$issue}",
                'customer',
                'rating',
                $order->customer,
            );
        }
    }

    /** Owner typed a lesson in a chat (e.g. after handoff): "remember: …" / "تعلم: …". */
    public function learnFromOwnerText(string $text, ?Customer $customer = null): bool
    {
        $text = trim($text);
        if (! preg_match('/^(remember|lesson|learn|note|تعلم|تذكّر|تذكر|ملاحظة)\s*[:：\-]\s*(.+)$/iu', $text, $m)) {
            return false;
        }

        $this->remember(trim($m[2]), 'shop', 'owner');
        if ($customer) {
            $this->remember(trim($m[2]), 'customer', 'owner', $customer);
        }

        return true;
    }

    /**
     * @return list<AiMemory>
     */
    public function list(?string $scope = null, int $limit = 100): array
    {
        $q = AiMemory::query()->with('customer:id,name,phone')->orderByDesc('updated_at')->limit($limit);
        if ($scope === 'shop' || $scope === 'customer') {
            $q->where('scope', $scope);
        }

        return $q->get()->all();
    }

    public function forget(string $id): bool
    {
        $row = AiMemory::query()->find($id);
        if (! $row) {
            return false;
        }
        $row->delete();

        return true;
    }

    public function forgetAll(?string $scope = null): int
    {
        $q = AiMemory::query();
        if ($scope === 'shop' || $scope === 'customer') {
            $q->where('scope', $scope);
        }

        return $q->delete();
    }
}

<?php

namespace App\Services;

use App\Models\AiMemory;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;

class CustomerService
{
    /**
     * @return list<Customer>
     */
    public function list(Merchant $merchant, ?string $search = null, ?bool $blocked = null, int $limit = 50): array
    {
        $q = Customer::query()->where('merchant_id', $merchant->id);

        if ($blocked !== null) {
            $q->where('is_blocked', $blocked);
        }
        if ($search) {
            $term = '%'.$search.'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('name', 'like', $term)->orWhere('phone', 'like', $term);
            });
        }

        return $q->latest()->limit($limit)->get()->all();
    }

    public function block(Merchant $merchant, string $customerId, ?string $reason = null): Customer
    {
        $customer = Customer::query()->where('merchant_id', $merchant->id)->findOrFail($customerId);
        $customer->forceFill(['is_blocked' => true, 'block_reason' => $reason])->save();

        return $customer;
    }

    public function unblock(Merchant $merchant, string $customerId): Customer
    {
        $customer = Customer::query()->where('merchant_id', $merchant->id)->findOrFail($customerId);
        $customer->forceFill(['is_blocked' => false, 'block_reason' => null])->save();

        return $customer;
    }

    /**
     * Chats where the bot is paused so the shop answers personally.
     *
     * @return list<array<string, mixed>>
     */
    public function paused(Merchant $merchant): array
    {
        return Customer::query()
            ->where('merchant_id', $merchant->id)
            ->whereNotNull('bot_paused_until')
            ->where('bot_paused_until', '>', now())
            ->orderBy('bot_paused_until')
            ->limit(50)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'bot_paused_until' => $c->bot_paused_until?->toIso8601String(),
            ])
            ->all();
    }

    public function resume(Merchant $merchant, string $customerId): Customer
    {
        $customer = Customer::query()->where('merchant_id', $merchant->id)->findOrFail($customerId);
        $customer->forceFill(['bot_paused_until' => null])->save();

        return $customer;
    }

    /**
     * Anonymize a customer for privacy requests (orders kept for shop records, personal fields scrubbed).
     */
    public function anonymize(Merchant $merchant, string $customerId): Customer
    {
        $customer = Customer::query()->where('merchant_id', $merchant->id)->findOrFail($customerId);
        $token = 'deleted-'.substr(hash('sha256', $customer->id), 0, 12);

        $customer->forceFill([
            'name' => 'Deleted customer',
            'phone' => '+'.$token,
            'addresses' => null,
            'approvals' => null,
            'bot_paused_until' => null,
            'is_blocked' => true,
            'block_reason' => 'anonymized',
        ])->save();

        // The orders stay for the shop's records, without where the customer lives.
        Order::query()
            ->where('merchant_id', $merchant->id)
            ->where('customer_id', $customer->id)
            ->update(['delivery_address' => null, 'delivery_lat' => null, 'delivery_lng' => null]);

        AiMemory::query()
            ->where('merchant_id', $merchant->id)
            ->where('customer_id', $customer->id)
            ->delete();

        return $customer;
    }
}

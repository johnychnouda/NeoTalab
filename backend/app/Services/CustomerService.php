<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;

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
}

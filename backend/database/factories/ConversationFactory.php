<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Support\Conversation\ConversationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'status' => ConversationStatus::Active,
            'channel' => 'simulation',
            'state' => ['messages' => [], 'phase' => 'idle'],
            'last_message_at' => null,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->id]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'merchant_id' => $customer->merchant_id,
            'customer_id' => $customer->id,
        ]);
    }
}

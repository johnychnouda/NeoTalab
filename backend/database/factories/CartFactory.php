<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Merchant;
use App\Support\Conversation\CartStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'conversation_id' => Conversation::factory(),
            'status' => CartStatus::Open,
            'subtotal' => 0,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->id]);
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->state(fn () => [
            'merchant_id' => $conversation->merchant_id,
            'conversation_id' => $conversation->id,
        ]);
    }
}

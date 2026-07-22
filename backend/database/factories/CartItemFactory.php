<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'variant_id' => null,
            'quantity' => 1,
            'unit_price' => 10.00,
            'modifier_ids' => [],
            'line_total' => 10.00,
            'notes' => null,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->id]);
    }

    public function forCart(Cart $cart): static
    {
        return $this->state(fn () => [
            'merchant_id' => $cart->merchant_id,
            'cart_id' => $cart->id,
        ]);
    }
}

<?php

namespace App\Support;

use RuntimeException;

/**
 * A checkout the customer can fix (empty cart, missing address, below minimum…).
 * `key` is under `whatsapp.checkout.*`; `params` fill its placeholders.
 */
class CheckoutException extends RuntimeException
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct("Checkout rejected: {$key}");
    }

    public function customerMessage(string $locale): string
    {
        return __("whatsapp.checkout.{$this->key}", $this->params, $locale);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_the_visitor_ip_comes_from_railways_proxy_header(): void
    {
        Route::get('/_test/ip', fn () => ['ip' => request()->ip(), 'secure' => request()->secure()]);

        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    }
}

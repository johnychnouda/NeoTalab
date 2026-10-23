<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_visitor_ip_comes_from_railways_proxy_header(): void
    {
        Route::get('/_test/ip', fn () => ['ip' => request()->ip(), 'secure' => request()->secure()]);

        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    }

    public function test_one_account_cannot_be_tried_from_endless_faked_addresses(): void
    {
        $codes = [];
        foreach (range(1, 12) as $i) {
            $codes[] = $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => "guess{$i}"],
                ['X-Forwarded-For' => "81.21.{$i}.7"])->status();
        }

        $this->assertSame(array_fill(0, 10, 422), array_slice($codes, 0, 10));
        $this->assertSame(429, $codes[10], 'the 11th guess on the same account is slowed down whatever address it claims');
    }

    public function test_a_flood_of_join_applications_stops_even_with_faked_addresses(): void
    {
        $codes = [];
        foreach (range(1, 31) as $i) {
            $codes[] = $this->postJson('/api/v1/onboarding/apply', [], ['X-Forwarded-For' => "81.22.{$i}.9"])->status();
        }

        $this->assertNotContains(429, array_slice($codes, 0, 30));
        $this->assertSame(429, $codes[30]);
    }
}

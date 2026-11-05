<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JoinServerErrorLimitTest extends TestCase
{
    use RefreshDatabase;

    private function application(): array
    {
        return [
            'shopName' => 'Join Shop', 'contactName' => 'Ahmad', 'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96178887777', 'country' => 'LB', 'city' => 'Beirut', 'region' => 'Beirut',
            'street' => 'Hamra Street', 'acceptedTerms' => true,
        ];
    }

    public function test_a_server_error_does_not_use_up_the_applicants_tries(): void
    {
        Http::fake();
        Schema::dropColumns('onboarding_requests', 'language'); // the production break of 5 Nov: the insert fails with a 500

        $codes = [];
        foreach (range(1, 8) as $i) {
            $codes[] = $this->postJson('/api/v1/onboarding/apply', $this->application())->status();
        }

        $this->assertSame(array_fill(0, 8, 500), $codes, 'our bug never locks the shop out of trying again');
    }

    public function test_applications_that_are_refused_still_count_toward_the_limit(): void
    {
        $codes = [];
        foreach (range(1, 6) as $i) {
            $codes[] = $this->postJson('/api/v1/onboarding/apply', [])->status();
        }

        $this->assertSame([422, 422, 422, 422, 422, 429], $codes);
    }
}

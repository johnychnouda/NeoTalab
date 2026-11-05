<?php

namespace Tests\Feature;

use App\Jobs\RetryPlatformSend;
use App\Jobs\RetryWhatsAppSend;
use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Services\WhatsApp\TransientWhatsAppException;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** A short Meta problem must not cost a customer (or the shop's team) their message. */
class WhatsAppSendRetryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['settings' => ['bot' => ['phone_id' => '111'], 'mode' => 'auto']]);
    }

    private function send(): array
    {
        return app(WhatsAppService::class)->sendToCustomer($this->merchant, '+96170123456', ['type' => 'text', 'text' => 'hello']);
    }

    public function test_a_message_is_kept_and_retried_when_meta_cannot_be_reached(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timeout')]);

        $this->send();

        Queue::assertPushed(RetryWhatsAppSend::class, fn ($job) => $job->toPhone === '+96170123456' && $job->message['text'] === 'hello');
    }

    public function test_a_message_is_retried_when_meta_is_busy_or_failing(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'busy']], 429)]);
        $this->send();
        Http::fake(['graph.facebook.com/*' => Http::response('bad gateway', 502)]);
        $this->send();

        Queue::assertPushed(RetryWhatsAppSend::class, 2);
    }

    public function test_a_refusal_from_meta_is_not_retried(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Re-engagement message']], 400)]);

        try {
            $this->send();
            $this->fail('a refused message must still throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Re-engagement', $e->getMessage());
        }
        Queue::assertNotPushed(RetryWhatsAppSend::class);
    }

    public function test_the_retry_sends_the_message_once_meta_is_back(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        (new RetryWhatsAppSend($this->merchant->id, '+96170123456', ['type' => 'text', 'text' => 'hello']))->handle(app(WhatsAppService::class));

        Http::assertSentCount(1);
        $this->assertDatabaseHas('whatsapp_messages', ['merchant_id' => $this->merchant->id, 'direction' => 'outbound', 'wa_message_id' => 'wamid.OK']);
    }

    public function test_the_retry_asks_the_queue_to_try_again_while_meta_is_still_down(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('down', 503)]);

        $this->expectException(TransientWhatsAppException::class);
        (new RetryWhatsAppSend($this->merchant->id, '+96170123456', ['type' => 'text', 'text' => 'hello']))->handle(app(WhatsAppService::class));
    }

    private function platformSend(): array
    {
        PlatformSetting::singleton()->forceFill(['settings' => ['wa_phone_id' => '999']])->save();

        return app(WhatsAppService::class)->sendPlatform('+96170123456', ['type' => 'text', 'text' => 'hello']);
    }

    public function test_a_message_from_the_platform_number_is_retried_when_meta_fails(): void
    {
        Queue::fake();
        $metaError = ['error' => ['message' => '(#131000) Something went wrong', 'code' => 131000]];
        Http::fake(['graph.facebook.com/*' => Http::response($metaError, 500)]);

        $this->assertSame([], $this->platformSend());

        Queue::assertPushed(RetryPlatformSend::class, fn ($job) => $job->toPhone === '+96170123456' && $job->message['text'] === 'hello');
    }

    public function test_a_refusal_for_a_platform_message_is_still_an_error(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);

        $this->expectException(RuntimeException::class);
        try {
            $this->platformSend();
        } finally {
            Queue::assertNotPushed(RetryPlatformSend::class);
        }
    }

    public function test_the_platform_retry_sends_once_meta_is_back(): void
    {
        PlatformSetting::singleton()->forceFill(['settings' => ['wa_phone_id' => '999']])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        (new RetryPlatformSend('+96170123456', ['type' => 'text', 'text' => 'hello']))->handle(app(WhatsAppService::class));

        Http::assertSentCount(1);
    }

    public function test_the_platform_retry_asks_the_queue_to_try_again_while_meta_is_still_down(): void
    {
        PlatformSetting::singleton()->forceFill(['settings' => ['wa_phone_id' => '999']])->save();
        Http::fake(['graph.facebook.com/*' => Http::response('down', 503)]);

        $this->expectException(TransientWhatsAppException::class);
        (new RetryPlatformSend('+96170123456', ['type' => 'text', 'text' => 'hello']))->handle(app(WhatsAppService::class));
    }
}

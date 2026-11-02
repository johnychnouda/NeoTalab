<?php

namespace Tests\Feature;

use App\Models\SystemAlert;
use App\Services\WhatsApp\CustomerTemplates;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffTemplateTexts;
use App\Services\WhatsApp\WhatsAppInboundService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomerTemplatesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_every_event_has_a_template_in_each_language(): void
    {
        $events = [CustomerTemplates::ACCEPTED, CustomerTemplates::READY, CustomerTemplates::ON_THE_WAY, CustomerTemplates::DELAYED, CustomerTemplates::CANCELLED, CustomerTemplates::BOOKING, CustomerTemplates::REFUND,
            CustomerTemplates::OUTSIDE, CustomerTemplates::DELIVERED, CustomerTemplates::DELIVERED_RATE, CustomerTemplates::QUOTE, CustomerTemplates::SCHEDULED,
            CustomerTemplates::PAY_INSTRUCTIONS, CustomerTemplates::PAY_CONFIRMED, CustomerTemplates::PAY_NOT_RECEIVED, CustomerTemplates::PAY_EXPIRED,
            CustomerTemplates::BOOKING_CANCELLED_SHOP, CustomerTemplates::BOOKING_MOVED];
        $defs = CustomerTemplates::definitions();

        $this->assertCount(count($events) * count(CustomerTemplates::LANGUAGES), $defs);
        foreach ($events as $event) {
            foreach (CustomerTemplates::LANGUAGES as $language) {
                $this->assertSame($language, $defs["neotalab_c_{$event}_{$language}"]['language']);
            }
        }
    }

    public function test_every_shop_template_follows_metas_rules_so_it_can_be_approved(): void
    {
        foreach (ShopTemplateService::definitions() as $name => $def) {
            preg_match_all('/\{\{(\d+)\}\}/', $def['body'], $found);
            $count = count(array_unique($found[1]));
            $this->assertCount($count, $found[1], "{$name}: no variable is used twice (Meta wants each once)");

            $this->assertSame(range(1, $count), array_values(array_map('intval', array_unique($found[1]))), "{$name}: variables are numbered 1, 2, 3…");
            $this->assertCount($count, $def['example'], "{$name}: one example per variable");
            $this->assertLessThanOrEqual(3, count($def['buttons'] ?? []), "{$name}: Meta allows 3 quick replies");
            foreach ($def['buttons'] ?? [] as $title) {
                $this->assertLessThanOrEqual(25, mb_strlen($title), "{$name}: a button title is at most 25 letters");
            }
            $this->assertDoesNotMatchRegularExpression('/^\s*\{\{/u', $def['body'], "{$name}: Meta refuses a variable at the start");
            $this->assertDoesNotMatchRegularExpression('/\{\{\d+\}\}\s*[.!?؟]?\s*$/u', $def['body'], "{$name}: Meta refuses a variable at the end");
            $this->assertDoesNotMatchRegularExpression('/https?:|www\./i', $def['body'], "{$name}: no link in the text");
            $this->assertLessThan(1024, mb_strlen($def['body']));
            $this->assertGreaterThanOrEqual(8, count(preg_split('/\s+/u', trim((string) preg_replace('/\{\{\d+\}\}/', '', $def['body'])))), "{$name}: enough fixed words around the variables");
        }
    }

    public function test_the_shop_template_set_includes_them_and_they_are_submitted_in_their_own_language(): void
    {
        $this->assertArrayHasKey('neotalab_c_order_ready_fr', ShopTemplateService::definitions());
        $this->assertArrayHasKey(ShopTemplateService::NEW_ORDER, ShopTemplateService::definitions());

        $message = ShopTemplateService::message('neotalab_c_order_ready_fr', ['128', 'Beirut Bites', 'Hamra'], [], 'fr');
        $this->assertSame('fr', $message['template']['language']['code']);
        $this->assertSame('en', ShopTemplateService::message(ShopTemplateService::BILLING_REMINDER, ['x', 'y'])['template']['language']['code']);
    }

    public function test_every_staff_template_exists_in_arabic_and_french_with_matching_buttons(): void
    {
        $defs = ShopTemplateService::definitions();
        foreach (array_keys(StaffTemplateTexts::all()) as $base) {
            foreach (['ar', 'fr'] as $language) {
                $this->assertArrayHasKey("{$base}_{$language}", $defs);
                $this->assertSame($language, $defs["{$base}_{$language}"]['language']);
                $this->assertSame(count($defs[$base]['buttons'] ?? []), count($defs["{$base}_{$language}"]['buttons'] ?? []), "{$base}_{$language}: same buttons as the English one");
            }
        }
        $this->assertCount(15, StaffTemplateTexts::all(), 'all fifteen staff and owner templates are translated');
    }

    public function test_the_name_falls_back_to_english_for_an_unknown_language(): void
    {
        $this->assertSame('neotalab_c_order_ready_ar', CustomerTemplates::name(CustomerTemplates::READY, 'ar'));
        $this->assertSame('neotalab_c_order_ready_en', CustomerTemplates::name(CustomerTemplates::READY, 'de'));
        $this->assertSame('neotalab_c_order_ready_en', CustomerTemplates::name(CustomerTemplates::READY, null));
        $this->assertSame(['neotalab_c_order_ready_fr', 'neotalab_c_order_ready_en'], CustomerTemplates::names(CustomerTemplates::READY, 'fr'));
    }

    public function test_a_failed_delivery_report_becomes_an_owner_alert(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$merchant] = $this->merchantWithUser(merchantAttrs: ['name' => 'Beirut Bites', 'settings' => ['bot' => ['phone_id' => '555']]]);

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'statuses' => [
                ['id' => 'wamid.A', 'status' => 'delivered', 'recipient_id' => '96170111111'],
                ['id' => 'wamid.B', 'status' => 'failed', 'recipient_id' => '96170123456',
                    'errors' => [['code' => 131047, 'title' => 'Re-engagement message']]],
            ],
        ]]]]]]);

        $alerts = SystemAlert::query()->get();
        $this->assertCount(1, $alerts, 'only the failure is reported');
        $this->assertSame($merchant->id, $alerts[0]->merchant_id);
        $this->assertSame('warning', $alerts[0]->level);
        $this->assertStringContainsString('Beirut Bites', $alerts[0]->message);
        $this->assertStringContainsString('+96170123456', $alerts[0]->message);
        $this->assertStringContainsString('Re-engagement message (Meta code 131047)', $alerts[0]->message);
    }

    public function test_a_failure_on_neotalabs_own_number_is_reported_too(): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'unknown-platform-id'],
            'statuses' => [['id' => 'wamid.C', 'status' => 'failed', 'recipient_id' => '96170999999', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]],
        ]]]]]]);

        $alert = SystemAlert::query()->sole();
        $this->assertNull($alert->merchant_id);
        $this->assertStringContainsString("NeoTalab's own number", $alert->message);
    }
}

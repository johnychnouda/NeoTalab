<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\ShopHour;
use App\Services\CheckoutService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lebanon changes the clock twice a year: 29 March 2026 00:00 → 01:00 (one hour skipped) and
 * 25 October 2026 00:00 → 24 October 23:00 (one hour twice). Hours, overnight hours and booking
 * slots must stay right on both nights.
 */
class DaylightSavingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['timezone' => 'Asia/Beirut', 'settings' => ['business_mode' => 'appointments']]);
        app(CurrentTenant::class)->set($this->merchant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hours(string $open, string $close): void
    {
        foreach (range(0, 6) as $day) {
            ShopHour::query()->forceCreate(['merchant_id' => $this->merchant->id, 'day_of_week' => $day, 'opens_at' => $open, 'closes_at' => $close, 'is_closed' => false]);
        }
    }

    private function openAt(string $utc): bool
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        return app(CheckoutService::class)->isOpen($this->merchant);
    }

    public function test_normal_hours_on_the_night_the_clock_goes_back(): void
    {
        $this->hours('09:00', '23:00');

        $this->assertTrue($this->openAt('2026-10-24 19:30'), '22:30 summer time: open');
        $this->assertFalse($this->openAt('2026-10-24 20:30'), '23:30 summer time: closed');
        $this->assertFalse($this->openAt('2026-10-24 21:30'), '23:30 again, winter time: still closed');
        $this->assertTrue($this->openAt('2026-10-25 07:30'), '09:30 winter time: open');
    }

    public function test_overnight_hours_across_both_clock_changes(): void
    {
        $this->hours('18:00', '02:00');

        $this->assertTrue($this->openAt('2026-10-24 21:30'), '23:30 (the repeated hour): open');
        $this->assertTrue($this->openAt('2026-10-24 23:30'), '01:30 Sunday, winter time: still open');
        $this->assertFalse($this->openAt('2026-10-25 00:30'), '02:30 Sunday: closed');

        $this->assertTrue($this->openAt('2026-03-28 21:30'), '23:30 Saturday: open');
        $this->assertTrue($this->openAt('2026-03-28 22:30'), '01:30 Sunday, summer time (00:xx never happened): open');
        $this->assertFalse($this->openAt('2026-03-28 23:30'), '02:30 Sunday: closed');
    }
}

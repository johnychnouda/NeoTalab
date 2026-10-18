<?php

namespace Tests\Unit;

use App\Support\ZoneArea;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ZoneAreaTest extends TestCase
{
    public function test_a_circle_contains_points_within_its_radius(): void
    {
        $area = ZoneArea::clean(['type' => 'circle', 'center' => [33.8938, 35.5018], 'radius' => 1500]);

        $this->assertTrue(ZoneArea::contains($area, 33.9000, 35.5018));   // ~700 m north
        $this->assertFalse(ZoneArea::contains($area, 33.9200, 35.5018));  // ~2.9 km north
    }

    public function test_a_drawn_shape_contains_points_inside_it_only(): void
    {
        // A square around Hamra.
        $area = ZoneArea::clean(['type' => 'polygon', 'points' => [[33.90, 35.47], [33.90, 35.49], [33.89, 35.49], [33.89, 35.47]]]);

        $this->assertTrue(ZoneArea::contains($area, 33.895, 35.48));
        $this->assertFalse(ZoneArea::contains($area, 33.895, 35.50));
        $this->assertGreaterThan(1_500_000, ZoneArea::size($area));
    }

    public function test_a_broken_shape_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        ZoneArea::clean(['type' => 'polygon', 'points' => [[33.9, 35.47], [33.9, 35.49]]]);
    }
}

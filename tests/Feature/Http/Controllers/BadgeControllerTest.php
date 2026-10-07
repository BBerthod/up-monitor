<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public uptime badge is embedded as an <img> in READMEs and in the
 * monitor page, so its SVG must place both texts at real coordinates.
 */
class BadgeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_badge_texts_have_numeric_anchors(): void
    {
        $monitor = Monitor::factory()->create();
        MonitorCheck::factory()->create(['monitor_id' => $monitor->id]);

        $response = $this->get("/badge/{$monitor->badge_secret}.svg");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');

        $svg = $response->getContent();
        preg_match_all('/<text x="([^"]*)"/', $svg, $matches);

        $this->assertCount(4, $matches[1]);
        foreach ($matches[1] as $x) {
            $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $x);
        }
        // Label is centred in the left segment, value in the right one.
        $this->assertLessThan((float) $matches[1][2], (float) $matches[1][0]);
        $this->assertStringContainsString('uptime', $svg);
    }

    public function test_unknown_secret_is_not_found(): void
    {
        $this->get('/badge/does-not-exist.svg')->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Services\Vikunja;

use App\Services\Vikunja\VikunjaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Vikunja API caps a page at 50 items whatever `per_page` asks for, so a
 * single request silently drops everything past the 50th — board-mode
 * "projet: …" labels past that point went unresolved.
 */
class VikunjaClientPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
    }

    public function test_labels_are_read_across_every_page(): void
    {
        $page1 = array_map(fn (int $i) => ['id' => $i, 'title' => "label {$i}"], range(1, 50));
        $page2 = [['id' => 51, 'title' => 'projet: example.com'], ['id' => 52, 'title' => 'sphère: Example']];

        Http::fake([
            'vikunja.test/*' => Http::sequence()->push($page1)->push($page2),
        ]);

        $labels = app(VikunjaClient::class)->labels();

        $this->assertCount(52, $labels);
        $this->assertSame('projet: example.com', $labels[50]['title']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'page=2'));
    }

    public function test_a_short_first_page_ends_the_listing(): void
    {
        Http::fake([
            'vikunja.test/*' => Http::response([['id' => 1, 'title' => 'Inbox']]),
        ]);

        $this->assertCount(1, app(VikunjaClient::class)->projects());
        Http::assertSentCount(1);
    }
}

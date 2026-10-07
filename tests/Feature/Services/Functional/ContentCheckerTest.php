<?php

namespace Tests\Feature\Services\Functional;

use App\Enums\FunctionalCheckType;
use App\Models\FunctionalCheck;
use App\Models\Monitor;
use App\Services\Checkers\Functional\ContentChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentCheckerTest extends TestCase
{
    use RefreshDatabase;

    private ContentChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new ContentChecker;
    }

    public function test_passes_when_required_text_present(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Welcome']],
        ]);

        Http::fake(['example.com' => Http::response('<h1>Welcome</h1>', 200)]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);
        $this->assertSame('text_present', $result->details[0]['rule']);
        $this->assertTrue($result->details[0]['passed']);
    }

    public function test_fails_when_required_text_absent(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'Welcome']],
        ]);

        Http::fake(['example.com' => Http::response('<p>Nothing here</p>', 200)]);

        $result = $this->checker->check($check);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->details[0]['passed']);
    }

    public function test_fails_when_forbidden_text_present(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_absent', 'value' => 'Fatal error']],
        ]);

        Http::fake(['example.com' => Http::response('Fatal error: Call to undefined function', 500)]);

        $result = $this->checker->check($check);

        $this->assertFalse($result->passed);
    }

    public function test_passes_when_content_long_enough(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'min_content_length', 'value' => 10]],
        ]);

        Http::fake(['example.com' => Http::response(str_repeat('x', 100), 200)]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);
    }

    public function test_fails_on_connection_error(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::CONTENT,
            'url' => 'https://example.com',
            'rules' => [['type' => 'text_present', 'value' => 'OK']],
        ]);

        Http::fake(['example.com' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = $this->checker->check($check);

        $this->assertFalse($result->passed);
        $this->assertNotNull($result->errorMessage);
    }

    public function test_resolves_relative_url_from_monitor_base(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://mysite.com']);
        $check = FunctionalCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'type' => FunctionalCheckType::CONTENT,
            'url' => '/best-deals',
            'rules' => [['type' => 'text_present', 'value' => 'Sale']],
        ]);

        Http::fake(['mysite.com/best-deals' => Http::response('<h1>Sale</h1>', 200)]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);
    }
}

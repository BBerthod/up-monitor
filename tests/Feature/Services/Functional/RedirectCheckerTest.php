<?php

namespace Tests\Feature\Services\Functional;

use App\Enums\FunctionalCheckType;
use App\Models\FunctionalCheck;
use App\Services\Checkers\Functional\RedirectChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedirectCheckerTest extends TestCase
{
    use RefreshDatabase;

    private RedirectChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new RedirectChecker;
    }

    public function test_passes_when_redirects_to_expected_url(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::REDIRECT,
            'url' => 'http://example.com',
            'rules' => [['type' => 'redirects_to', 'value' => 'https://example.com/']],
        ]);

        Http::fake([
            'http://example.com*' => Http::response('', 301, ['Location' => 'https://example.com/']),
            'https://example.com*' => Http::response('<html>', 200),
        ]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);
    }

    public function test_fails_when_redirects_to_wrong_url(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::REDIRECT,
            'url' => 'http://example.com',
            'rules' => [['type' => 'redirects_to', 'value' => 'https://expected.com/']],
        ]);

        Http::fake([
            'http://example.com' => Http::response('', 301, ['Location' => 'https://other.com/']),
            'https://other.com' => Http::response('<html>', 200),
        ]);

        $result = $this->checker->check($check);

        $this->assertFalse($result->passed);
    }

    public function test_passes_https_enforced_when_http_redirects_to_https(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::REDIRECT,
            'url' => 'http://example.com',
            'rules' => [['type' => 'https_enforced']],
        ]);

        Http::fake([
            'http://example.com*' => Http::response('', 301, ['Location' => 'https://example.com/']),
            'https://example.com*' => Http::response('', 200),
        ]);

        $result = $this->checker->check($check);

        $this->assertTrue($result->passed);
    }

    public function test_fails_no_redirect_when_redirect_exists(): void
    {
        $check = FunctionalCheck::factory()->create([
            'type' => FunctionalCheckType::REDIRECT,
            'url' => 'https://example.com/page',
            'rules' => [['type' => 'no_redirect']],
        ]);

        Http::fake([
            'https://example.com/page' => Http::response('', 301, ['Location' => 'https://example.com/other']),
            'https://example.com/other' => Http::response('', 200),
        ]);

        $result = $this->checker->check($check);

        $this->assertFalse($result->passed);
    }
}

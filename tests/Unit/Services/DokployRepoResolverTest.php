<?php

namespace Tests\Unit\Services;

use App\Services\DokployRepoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit tests for DokployRepoResolver.
 *
 * Http::fake() intercepts all outbound HTTP. Cache::flush() in setUp() ensures
 * the in-memory array cache (CACHE_STORE=array in phpunit.xml) does not bleed
 * between tests — the resolver caches results for 1 hour per host.
 */
class DokployRepoResolverTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://dok.test';

    private const API_TOKEN = 'test-token';

    /** Minimal Dokploy project.all fixture with one application. */
    private function projectAllResponse(): array
    {
        return [
            [
                'name' => 'Examplestore',
                'environments' => [
                    [
                        'applications' => [
                            [
                                'applicationId' => 'app1',
                                'name' => 'examplestore-app',
                                'customGitUrl' => 'git@github.com:BBerthod/Examplestore.git',
                                'branch' => 'main',
                                'domains' => [
                                    ['host' => 'fr.examplestore.com'],
                                    ['host' => 'us.examplestore.com'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent cache bleed: the array cache persists within the PHP process.
        Cache::flush();
    }

    private function makeResolver(): DokployRepoResolver
    {
        return new DokployRepoResolver(
            baseUrl: self::BASE_URL,
            apiToken: self::API_TOKEN,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Happy path
    // ──────────────────────────────────────────────────────────────────────

    public function test_resolves_host_to_repo(): void
    {
        Http::fake(['*/api/project.all' => Http::response($this->projectAllResponse(), 200)]);

        $resolver = $this->makeResolver();
        $result = $resolver->resolveRepoForHost('fr.examplestore.com');

        $this->assertNotNull($result);
        $this->assertSame('git@github.com:BBerthod/Examplestore.git', $result['repo']);
        $this->assertSame('Examplestore', $result['project_name']);
        $this->assertSame('examplestore-app', $result['app_name']);
        $this->assertSame('app1', $result['app_id']);
        $this->assertSame('main', $result['branch']);
        $this->assertContains('fr.examplestore.com', $result['all_domains']);
    }

    public function test_resolves_from_full_url(): void
    {
        Http::fake(['*/api/project.all' => Http::response($this->projectAllResponse(), 200)]);

        $resolver = $this->makeResolver();
        $result = $resolver->resolveRepoForHost('https://us.examplestore.com/api/health');

        $this->assertNotNull($result);
        $this->assertSame('git@github.com:BBerthod/Examplestore.git', $result['repo']);
        $this->assertSame('examplestore-app', $result['app_name']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Null / degraded cases
    // ──────────────────────────────────────────────────────────────────────

    public function test_returns_null_when_host_not_found(): void
    {
        Http::fake(['*/api/project.all' => Http::response($this->projectAllResponse(), 200)]);

        $resolver = $this->makeResolver();
        $result = $resolver->resolveRepoForHost('inconnu.com');

        $this->assertNull($result);
    }

    public function test_returns_null_without_token(): void
    {
        Http::fake(['*/api/project.all' => Http::response($this->projectAllResponse(), 200)]);

        // No token → should bail before any HTTP call.
        $resolver = new DokployRepoResolver(
            baseUrl: self::BASE_URL,
            apiToken: '',
        );

        $result = $resolver->resolveRepoForHost('fr.examplestore.com');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_returns_null_on_api_failure(): void
    {
        Http::fake(['*/api/project.all' => Http::response([], 500)]);

        $resolver = $this->makeResolver();
        $result = $resolver->resolveRepoForHost('fr.examplestore.com');

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Repo extraction from owner + repository fields
    // ──────────────────────────────────────────────────────────────────────

    public function test_builds_repo_from_owner_repository(): void
    {
        $payload = [
            [
                'name' => 'Examplestore',
                'environments' => [
                    [
                        'applications' => [
                            [
                                'applicationId' => 'app2',
                                'name' => 'examplestore-app',
                                // No customGitUrl — use owner + repository instead.
                                'owner' => 'BBerthod',
                                'repository' => 'Examplestore',
                                'branch' => 'main',
                                'domains' => [
                                    ['host' => 'fr.examplestore.com'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake(['*/api/project.all' => Http::response($payload, 200)]);

        $resolver = $this->makeResolver();
        $result = $resolver->resolveRepoForHost('fr.examplestore.com');

        $this->assertNotNull($result);
        $this->assertSame('git@github.com:BBerthod/Examplestore.git', $result['repo']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Caching
    // ──────────────────────────────────────────────────────────────────────

    public function test_caches_result_and_only_sends_one_http_request(): void
    {
        Http::fake(['*/api/project.all' => Http::response($this->projectAllResponse(), 200)]);

        $resolver = $this->makeResolver();

        // First call hits the API.
        $first = $resolver->resolveRepoForHost('fr.examplestore.com');
        // Second call should come from cache.
        $second = $resolver->resolveRepoForHost('fr.examplestore.com');

        $this->assertEquals($first, $second);
        // Only one outbound request should have been made.
        Http::assertSentCount(1);
    }
}

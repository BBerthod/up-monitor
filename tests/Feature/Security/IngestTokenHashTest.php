<?php

namespace Tests\Feature\Security;

use App\Models\IngestSource;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestTokenHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_via_url_token_still_works_after_migration(): void
    {
        $team = Team::factory()->create();
        $token = IngestSource::generateToken();

        IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Test Source',
            'slug' => 'test-source',
            'token' => $token,
            'token_hash' => IngestSource::hashToken($token),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/ingest/{$token}", [
            'type' => 'log',
            'level' => 'info',
            'message' => 'Test event',
        ]);

        $response->assertStatus(201);
    }

    public function test_ingest_via_bearer_token_works(): void
    {
        $team = Team::factory()->create();
        $token = IngestSource::generateToken();

        IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Test Source Bearer',
            'slug' => 'test-source-bearer',
            'token' => $token,
            'token_hash' => IngestSource::hashToken($token),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/ingest', [
            'type' => 'log',
            'level' => 'info',
            'message' => 'Test bearer event',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);
    }

    public function test_ingest_with_invalid_bearer_token_returns_401(): void
    {
        $response = $this->postJson('/api/ingest', [
            'type' => 'log',
            'level' => 'info',
            'message' => 'Test',
        ], [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_ingest_with_invalid_url_token_returns_401(): void
    {
        $response = $this->postJson('/api/ingest/invalid-token', [
            'type' => 'log',
            'level' => 'info',
            'message' => 'Test',
        ]);

        $response->assertStatus(401);
    }

    public function test_ingest_without_any_token_returns_401(): void
    {
        $response = $this->postJson('/api/ingest', [
            'type' => 'log',
            'level' => 'info',
            'message' => 'Test',
        ]);

        $response->assertStatus(401);
    }

    public function test_hash_token_generates_sha256_hash(): void
    {
        $token = 'test-plain-token';
        $expected = hash('sha256', $token);

        $this->assertSame($expected, IngestSource::hashToken($token));
    }

    public function test_token_hash_is_stored_when_creating_source(): void
    {
        $team = Team::factory()->create();
        $token = IngestSource::generateToken();

        $source = IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Hash Check',
            'slug' => 'hash-check',
            'token' => $token,
            'token_hash' => IngestSource::hashToken($token),
            'is_active' => true,
        ]);

        $this->assertSame(hash('sha256', $token), $source->token_hash);
    }

    public function test_ingest_route_throttle_is_60_per_minute(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'api.ingest.receive');

        $this->assertNotNull($route);

        $middlewares = $route->middleware();
        $throttleMiddleware = collect($middlewares)->first(fn ($m) => str_starts_with($m, 'throttle:'));

        $this->assertNotNull($throttleMiddleware);
        $this->assertSame('throttle:60,1', $throttleMiddleware);
    }

    public function test_inactive_source_returns_401_via_bearer(): void
    {
        $team = Team::factory()->create();
        $token = IngestSource::generateToken();

        IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Inactive Source',
            'slug' => 'inactive-source',
            'token' => $token,
            'token_hash' => IngestSource::hashToken($token),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/ingest', [
            'type' => 'log',
            'level' => 'info',
            'message' => 'Test',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(401);
    }
}

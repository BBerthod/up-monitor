<?php

namespace Tests\Feature\Http\Resources;

use App\Models\IngestSource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngestSourceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_does_not_expose_plaintext_token(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $secretToken = 'super-secret-ingest-token-1234567890abcdefghijklmnopqrstuvwxyz';
        IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Test Source',
            'slug' => 'test-source',
            'token' => $secretToken,
            'token_hash' => IngestSource::hashToken($secretToken),
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/ingest-sources');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString($secretToken, $body, 'Plain token must never appear in /api/ingest-sources');
    }

    public function test_show_does_not_expose_plaintext_token(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $secretToken = 'another-secret-token-abcdefghijklmnopqrstuvwxyz1234567890';
        $source = IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Test',
            'slug' => 'test',
            'token' => $secretToken,
            'token_hash' => IngestSource::hashToken($secretToken),
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/ingest-sources/{$source->id}");

        $response->assertOk();
        $this->assertStringNotContainsString($secretToken, $response->getContent());
    }

    public function test_store_returns_plain_token_once_at_creation(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/ingest-sources', [
            'name' => 'Production Webhook',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'name', 'slug'], 'token', 'token_warning']);

        // Token must be a non-empty string and not appear inside the resource data block
        $body = $response->json();
        $this->assertNotEmpty($body['token']);
        $this->assertIsString($body['token']);
        // After fetching index, that same token should no longer be visible
        $index = $this->getJson('/api/ingest-sources');
        $this->assertStringNotContainsString($body['token'], $index->getContent());
    }

    public function test_rotate_token_returns_new_plain_token_once(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $source = IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Test',
            'slug' => 'test',
            'token' => 'original-token-xyz',
            'token_hash' => IngestSource::hashToken('original-token-xyz'),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/ingest-sources/{$source->id}/rotate-token");

        $response->assertOk();
        $newToken = $response->json('token');
        $this->assertNotEmpty($newToken);
        $this->assertNotEquals('original-token-xyz', $newToken);

        // Index doesn't leak the new token either
        $this->assertStringNotContainsString($newToken, $this->getJson('/api/ingest-sources')->getContent());
    }

    public function test_resource_exposes_token_hash_prefix_not_full_hash(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $source = IngestSource::create([
            'team_id' => $team->id,
            'name' => 'Test',
            'slug' => 'test',
            'token' => 'tok',
            'token_hash' => str_repeat('a', 64),
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/ingest-sources/{$source->id}");

        $response->assertOk();
        $body = $response->json('data');
        $this->assertArrayHasKey('token_hash_prefix', $body);
        $this->assertEquals(str_repeat('a', 12), $body['token_hash_prefix']);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertArrayNotHasKey('token_hash', $body);
    }
}

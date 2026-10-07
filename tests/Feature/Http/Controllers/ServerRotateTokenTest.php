<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ServerController::rotateToken().
 *
 * POST servers.rotate-token
 *   - Generates a new ingest token, stores only the SHA-256 hash.
 *   - Flashes the plain-text token under 'serverToken' in the session (show once).
 *   - Returns a redirect back.
 *
 * Auth / scoping:
 *   - Guest → redirect login.
 *   - User from a different team → 404 (ScopedByTeam global scope, model not found).
 */
class ServerRotateTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bypass CSRF so POST requests work without a session CSRF token.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Guest guard
    // ──────────────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);

        $response = $this->post(route('servers.rotate-token', $server));

        $response->assertRedirect(route('login'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Happy path — authorized user rotates token
    // ──────────────────────────────────────────────────────────────────────

    public function test_rotate_token_updates_ingest_token_hash(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create([
            'team_id' => $user->team_id,
            'ingest_token_hash' => Server::hashIngestToken('old-plain-token'),
        ]);

        $oldHash = $server->ingest_token_hash;

        $this->actingAs($user)
            ->post(route('servers.rotate-token', $server));

        $server->refresh();

        // The stored hash must have changed.
        $this->assertNotEquals($oldHash, $server->getRawOriginal('ingest_token_hash') ?? $server->ingest_token_hash);
    }

    public function test_rotate_token_stores_valid_sha256_hash(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $this->actingAs($user)
            ->post(route('servers.rotate-token', $server));

        // After rotation, ingest_token_hash must be a 64-char hex string (SHA-256).
        $server->refresh();
        // We must bypass the $hidden cast to read the raw column value.
        $hashInDb = $server->getAttributes()['ingest_token_hash'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hashInDb);
    }

    public function test_rotate_token_flashes_plain_token_in_session(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)
            ->post(route('servers.rotate-token', $server));

        // The plain token must be flashed under 'serverToken' so the UI can show it once.
        $response->assertSessionHas('serverToken');
    }

    public function test_rotate_token_redirects_back(): void
    {
        $user = $this->createUserWithTeam();
        $server = Server::factory()->create(['team_id' => $user->team_id]);

        $response = $this->actingAs($user)
            ->post(route('servers.rotate-token', $server));

        // back() resolves to / when no Referer header is present.
        $response->assertRedirect();
    }

    public function test_flashed_token_is_different_from_old_hash(): void
    {
        $user = $this->createUserWithTeam();
        $oldPlain = Server::generateIngestToken();
        $server = Server::factory()->create([
            'team_id' => $user->team_id,
            'ingest_token_hash' => Server::hashIngestToken($oldPlain),
        ]);

        $response = $this->actingAs($user)
            ->post(route('servers.rotate-token', $server));

        $newPlain = $response->getSession()->get('serverToken');

        // The new plain token must not be the same as the old one.
        $this->assertNotEquals($oldPlain, $newPlain);

        // The new hash stored in DB must match the newly generated plain token.
        $server->refresh();
        $this->assertEquals(
            Server::hashIngestToken($newPlain),
            $server->getAttributes()['ingest_token_hash']
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Cross-team scoping
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The ScopedByTeam global scope makes a foreign server invisible to the
     * authenticated user — route model binding cannot resolve it → 404.
     */
    public function test_rotate_token_returns_404_for_server_from_another_team(): void
    {
        $userA = $this->createUserWithTeam();

        $teamB = Team::factory()->create();
        $serverB = Server::factory()->create([
            'team_id' => $teamB->id,
            'ingest_token_hash' => Server::hashIngestToken('original-token'),
        ]);

        $response = $this->actingAs($userA)
            ->post(route('servers.rotate-token', $serverB));

        $response->assertNotFound();

        // The hash must be unchanged.
        $serverB->refresh();
        $this->assertEquals(
            Server::hashIngestToken('original-token'),
            $serverB->getAttributes()['ingest_token_hash']
        );
    }
}

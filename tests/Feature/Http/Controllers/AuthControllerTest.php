<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
    }

    public function test_users_can_login_with_valid_credentials(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'team_id' => $team->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_cannot_login_with_invalid_credentials(): void
    {
        $team = Team::factory()->create();
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'team_id' => $team->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_register_route_is_removed(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_authenticated_users_can_logout(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect();
        $this->assertGuest();
    }
}

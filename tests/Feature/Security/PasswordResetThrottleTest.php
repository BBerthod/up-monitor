<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_get_route_is_accessible(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertStatus(200);
    }

    public function test_reset_password_get_route_with_token_is_accessible(): void
    {
        $response = $this->get(route('password.reset', ['token' => 'dummy-token']));

        $response->assertStatus(200);
    }

    public function test_forgot_password_post_route_has_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'password.email');

        $this->assertNotNull($route);
        $this->assertContains('throttle:auth', $route->middleware());
    }

    public function test_password_request_route_has_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'password.request');

        $this->assertNotNull($route);
        $this->assertContains('throttle:auth', $route->middleware());
    }

    public function test_password_reset_get_route_has_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'password.reset');

        $this->assertNotNull($route);
        $this->assertContains('throttle:auth', $route->middleware());
    }

    public function test_password_update_route_has_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'password.update');

        $this->assertNotNull($route);
        $this->assertContains('throttle:auth', $route->middleware());
    }
}

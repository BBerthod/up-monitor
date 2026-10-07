<?php

namespace Tests\Unit;

use App\Http\Middleware\IsAdmin;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IsAdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_pass_through(): void
    {
        $team = Team::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create(['team_id' => $team->id]);

        $middleware = new IsAdmin;
        $request = Request::create('/admin/users', 'GET');
        $request->setUserResolver(fn () => $superAdmin);

        $response = $middleware->handle($request, fn ($req) => new Response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_admin_can_pass_through(): void
    {
        $team = Team::factory()->create();
        $admin = User::factory()->admin()->create(['team_id' => $team->id]);

        $middleware = new IsAdmin;
        $request = Request::create('/admin/users', 'GET');
        $request->setUserResolver(fn () => $admin);

        $response = $middleware->handle($request, fn ($req) => new Response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_member_gets_403(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->member()->create(['team_id' => $team->id]);

        $middleware = new IsAdmin;
        $request = Request::create('/admin/users', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->expectException(HttpException::class);

        $middleware->handle($request, fn ($req) => new Response('OK'));
    }

    public function test_guest_gets_403(): void
    {
        $middleware = new IsAdmin;
        $request = Request::create('/admin/users', 'GET');
        $request->setUserResolver(fn () => null);

        $this->expectException(HttpException::class);

        $middleware->handle($request, fn ($req) => new Response('OK'));
    }
}

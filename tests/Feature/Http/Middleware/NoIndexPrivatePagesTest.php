<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoIndexPrivatePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_not_indexable(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_dashboard_is_not_indexable(): void
    {
        $user = User::factory()->for(Team::factory())->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_public_status_page_stays_indexable(): void
    {
        $page = StatusPage::factory()->for(Team::factory())->create();

        $this->get('/status/'.$page->slug)
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_landing_page_stays_indexable(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag');
    }
}

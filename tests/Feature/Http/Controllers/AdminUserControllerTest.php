<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(UserRole $role = UserRole::MEMBER): User
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }

    public function test_guest_cannot_access_admin_users(): void
    {
        $response = $this->get(route('admin.users.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_member_cannot_access_admin_users(): void
    {
        $user = $this->createUser(UserRole::MEMBER);

        $response = $this->actingAs($user)->get(route('admin.users.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_users_index(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
    }

    public function test_super_admin_can_view_users_index(): void
    {
        $superAdmin = $this->createUser(UserRole::SUPER_ADMIN);

        $response = $this->actingAs($superAdmin)->get(route('admin.users.index'));

        $response->assertOk();
    }

    public function test_admin_can_create_user_with_member_role(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'member',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(UserRole::MEMBER, $user->role);
        $this->assertNotNull($user->team_id);
    }

    public function test_admin_cannot_assign_super_admin_role(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin',
        ]);

        $response->assertSessionHasErrors(['role']);
    }

    public function test_super_admin_can_assign_super_admin_role(): void
    {
        $superAdmin = $this->createUser(UserRole::SUPER_ADMIN);

        $response = $this->actingAs($superAdmin)->post(route('admin.users.store'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin',
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'john@example.com')->first();
        $this->assertEquals(UserRole::SUPER_ADMIN, $user->role);
    }

    public function test_admin_can_update_member(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $member = $this->createUser(UserRole::MEMBER);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $member), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_admin_cannot_update_another_admin(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $otherAdmin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $otherAdmin), [
            'name' => 'Hacked Name',
            'email' => 'hacked@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_cannot_update_super_admin(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $superAdmin = $this->createUser(UserRole::SUPER_ADMIN);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $superAdmin), [
            'name' => 'Hacked Name',
            'email' => 'hacked@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_can_update_admin(): void
    {
        $superAdmin = $this->createUser(UserRole::SUPER_ADMIN);
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($superAdmin)->put(route('admin.users.update', $admin), [
            'name' => 'Updated Admin',
            'email' => $admin->email,
            'role' => 'member',
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $admin->refresh();
        $this->assertEquals(UserRole::MEMBER, $admin->role);
    }

    public function test_admin_can_delete_member(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $member = $this->createUser(UserRole::MEMBER);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $member));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_admin_cannot_delete_another_admin(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $otherAdmin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $otherAdmin));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    }

    public function test_admin_cannot_delete_super_admin(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $superAdmin = $this->createUser(UserRole::SUPER_ADMIN);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $superAdmin));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
    }

    public function test_super_admin_can_delete_admin(): void
    {
        $superAdmin = $this->createUser(UserRole::SUPER_ADMIN);
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($superAdmin)->delete(route('admin.users.destroy', $admin));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $admin));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), []);

        $response->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_store_validates_unique_email(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);
        $existing = $this->createUser(UserRole::MEMBER);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New User',
            'email' => $existing->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_default_role_is_member(): void
    {
        $admin = $this->createUser(UserRole::ADMIN);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Default Role User',
            'email' => 'default@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'default@example.com')->first();
        $this->assertEquals(UserRole::MEMBER, $user->role);
    }
}

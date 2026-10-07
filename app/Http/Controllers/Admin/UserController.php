<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::with('team')
            ->paginate(15)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'created_at' => $user->created_at,
                'team' => $user->team,
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'assignableRoles' => $this->getAssignableRoles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated) {
            $team = Team::create([
                'name' => $validated['name']."'s Team",
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'team_id' => $team->id,
            ]);

            $user->role = $validated['role'] ?? UserRole::MEMBER->value;
            $user->save();
        });

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function edit(Request $request, User $user): Response
    {
        $actor = $request->user();
        $canChangeRole = $actor->id !== $user->id && $actor->role->level() > $user->role->level();

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'team' => $user->team,
            ],
            'assignableRoles' => $this->getAssignableRoles(),
            'canChangeRole' => $canChangeRole,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $canChangeRole = $actor->id !== $user->id && $actor->role->level() > $user->role->level();
        if ($canChangeRole && ! empty($validated['role'])) {
            $user->role = $validated['role'];
        }

        $user->save();

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($user->id === $actor->id) {
            return redirect()->route('admin.users.index')->with('error', 'You cannot delete your own account.');
        }

        if ($user->role->level() >= $actor->role->level()) {
            return redirect()->route('admin.users.index')->with('error', 'You cannot delete a user with an equal or higher role.');
        }

        $teamId = $user->team_id;

        $user->delete();

        if ($teamId && User::where('team_id', $teamId)->doesntExist()) {
            Team::where('id', $teamId)->delete();
        }

        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }

    private function getAssignableRoles(): array
    {
        return array_map(
            fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
            auth()->user()->role->assignableRoles(),
        );
    }
}

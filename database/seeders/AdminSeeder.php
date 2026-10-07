<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = env('ADMIN_EMAIL', 'admin@example.com');
        $adminPassword = env('ADMIN_PASSWORD', 'password');
        $adminName = env('ADMIN_NAME', 'Admin');

        DB::transaction(function () use ($adminEmail, $adminPassword, $adminName) {
            if (User::where('email', $adminEmail)->exists()) {
                return;
            }

            $team = Team::create([
                'name' => "{$adminName}'s Team",
            ]);

            $user = User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'team_id' => $team->id,
            ]);

            $user->role = UserRole::SUPER_ADMIN;
            $user->save();
        });
    }
}

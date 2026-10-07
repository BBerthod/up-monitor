<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if (! $actor->isAdmin()) {
            return false;
        }

        // Cannot edit a user of equal or higher level (unless it's yourself)
        if ($target->id !== $actor->id && $target->role->level() >= $actor->role->level()) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        $assignable = array_map(
            fn (UserRole $r) => $r->value,
            $this->user()->role->assignableRoles(),
        );

        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => 'nullable|string|confirmed|min:8',
            'role' => ['nullable', 'string', Rule::in($assignable)],
        ];
    }
}

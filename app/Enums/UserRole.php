<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case MEMBER = 'member';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            self::MEMBER => 'Member',
        };
    }

    public function level(): int
    {
        return match ($this) {
            self::SUPER_ADMIN => 3,
            self::ADMIN => 2,
            self::MEMBER => 1,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Roles this role can assign to other users.
     *
     * @return self[]
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => [self::SUPER_ADMIN, self::ADMIN, self::MEMBER],
            self::ADMIN => [self::ADMIN, self::MEMBER],
            self::MEMBER => [],
        };
    }
}

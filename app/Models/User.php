<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'two_factor_secret', 'two_factor_confirmed_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_DESIGNER = 'designer';

    public const ROLE_FIELD = 'field';

    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_ADMINISTRATOR,
        self::ROLE_DESIGNER,
        self::ROLE_FIELD,
        self::ROLE_VIEWER,
    ];

    private const ROLE_PERMISSIONS = [
        self::ROLE_ADMINISTRATOR => ['*'],
        self::ROLE_DESIGNER => ['project.view', 'project.edit', 'project.export', 'project.backup'],
        self::ROLE_FIELD => ['project.view', 'field.capture'],
        self::ROLE_VIEWER => ['project.view'],
    ];

    public function hasPermission(string $permission): bool
    {
        $permissions = self::ROLE_PERMISSIONS[$this->role] ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMINISTRATOR;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}

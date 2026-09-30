<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * الجدول يحمل password_hash (تصميم الأعمال) لا password (اتفاقية Laravel)،
 * لذلك نوجّه Laravel إلى العمود الصحيح بدل تغيير الهيكل.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'role', 'is_active'];

    protected $hidden = ['password_hash', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function canAct(string $ability): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($this->role) {
            'owner' => true,
            'accountant' => ! in_array($ability, ['manage-users', 'manage-settings'], true),
            'cashier' => in_array($ability, [
                'view-dashboard', 'use-pos', 'view-catalog', 'manage-cash-session', 'print-documents',
            ], true),
            default => false,
        };
    }

    public function setPassword(string $plain): void
    {
        $this->password_hash = bcrypt($plain);
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = ['role' => 'admin'];

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
            'must_change_password' => 'boolean',
            'kvkk_accepted_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** Komisyon rolleri: başkan (aynı zamanda sistem yöneticisi) ve üye. */
    public const COMMISSION_ROLES = [
        'admin' => 'Komisyon başkanı (yönetici)',
        'commission' => 'Komisyon üyesi',
    ];

    /** Komisyon başkanı yalnız öğretim üyesi olabilir. */
    public const TITLES = [
        'Prof. Dr.' => true,
        'Doç. Dr.' => true,
        'Dr. Öğr. Üyesi' => true,
        'Öğr. Gör. Dr.' => false,
        'Öğr. Gör.' => false,
        'Arş. Gör. Dr.' => false,
        'Arş. Gör.' => false,
    ];

    /** Komisyon başkanı: kesin karar, kullanıcı yönetimi, ayarlar ve sınav modülü. */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Muafiyet işlerini yürüten komisyon (başkan ya da üye). */
    public function isCommission(): bool
    {
        return in_array($this->role, ['admin', 'commission'], true);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->name);
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /** Sınav modülünde yalnızca kendisine atanmış dersleri yöneten öğretim elemanı. */
    public function isInstructor(): bool
    {
        return $this->role === 'instructor';
    }
}

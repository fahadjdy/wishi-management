<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp_number',
        'avatar_path',
        'password',
        'credit_score',
        'trust_level',
        'max_active_wishis',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'failed_login_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'credit_score' => 'integer',
            'max_active_wishis' => 'integer',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Accessor: full public URL for the user's avatar (via the 'public' disk
     * that's served at /storage). Returns null when no avatar is set so the
     * UI can render the initials fallback.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->avatar_path) return null;
            try {
                return Storage::disk('public')->url($this->avatar_path);
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function createdWishis(): HasMany
    {
        return $this->hasMany(Wishi::class, 'created_by');
    }

    public function wishiMemberships(): HasMany
    {
        return $this->hasMany(WishiMember::class);
    }

    public function activeWishis()
    {
        return $this->belongsToMany(Wishi::class, 'wishi_members')
            ->wherePivotIn('status', ['approved', 'active'])
            ->withTimestamps();
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class);
    }

    public function creditScoreLogs(): HasMany
    {
        return $this->hasMany(CreditScoreLog::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function wonCycles(): HasMany
    {
        return $this->hasMany(Cycle::class, 'winner_id');
    }

    public function activeWishisCount(): int
    {
        return $this->wishiMemberships()
            ->whereIn('status', ['approved', 'active'])
            ->whereHas('wishi', fn ($q) => $q->where('status', 'active'))
            ->count();
    }

}

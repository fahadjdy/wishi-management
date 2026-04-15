<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Wishi extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'created_by',
        'total_members',
        'monthly_contribution',
        'duration_months',
        'cycle_frequency',
        'cycle_interval_days',
        'cycle_day',
        'start_date',
        'wishi_opening_time',
        'status',
        'auto_join',
        'require_approval',
        'winner_selection_mode',
        'cycle_type',
        'hybrid_pattern',
        'current_cycle',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'auto_join' => 'boolean',
            'require_approval' => 'boolean',
            'hybrid_pattern' => 'array',
            'monthly_contribution' => 'decimal:2',
            'total_members' => 'integer',
            'duration_months' => 'integer',
            'cycle_interval_days' => 'integer',
            'current_cycle' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Wishi $wishi) {
            if (empty($wishi->uuid)) {
                $wishi->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Invitable / joinable seat count. `total_members` is the WISHI size
     * INCLUDING the admin (who holds seat #1 as organizer). Members that
     * can actually be invited/approved = total_members - 1.
     */
    public function memberCapacity(): int
    {
        return max(0, (int) $this->total_members - 1);
    }

    /**
     * Per-cycle pool. Admin contributes equally with every member (admin
     * holds seat #1; receives the cycle-#1 organizer payout but pays the
     * monthly contribution like everyone else for every cycle, including
     * cycle #1). So pool = total_members × monthly_contribution.
     */
    public function totalPool(): float
    {
        return (float) $this->monthly_contribution * (int) $this->total_members;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WishiMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(WishiMember::class)->whereIn('status', ['approved', 'active']);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }

    public function currentCycleModel()
    {
        return $this->hasOne(Cycle::class)->where('cycle_number', $this->current_cycle);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}

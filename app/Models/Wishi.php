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
        'status',
        'auto_join',
        'require_approval',
        'winner_selection_mode',
        'cycle_type',
        'hybrid_pattern',
        'bidding_window_days',
        'min_credit_score',
        'max_active_wishis_per_member',
        'block_if_missed_payments',
        'tender_start_time',
        'tender_end_time',
        'current_cycle',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'auto_join' => 'boolean',
            'require_approval' => 'boolean',
            'block_if_missed_payments' => 'boolean',
            'hybrid_pattern' => 'array',
            'monthly_contribution' => 'decimal:2',
            'total_members' => 'integer',
            'duration_months' => 'integer',
            'cycle_interval_days' => 'integer',
            'bidding_window_days' => 'integer',
            'current_cycle' => 'integer',
            'min_credit_score' => 'integer',
            'max_active_wishis_per_member' => 'integer',
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

    public function totalPool(): float
    {
        return (float) $this->monthly_contribution * $this->total_members;
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

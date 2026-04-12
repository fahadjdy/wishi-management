<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'wishi_id',
        'cycle_number',
        'mode',
        'status',
        'total_pool',
        'winner_id',
        'winning_bid',
        'surplus',
        'surplus_action',
        'surplus_recipient_id',
        'deferred_amount',
        'deferred_released_at',
        'deferred_payout_id',
        'selection_method',
        'selection_seed',
        'selected_at',
        'payout_amount',
        'paid_out_at',
        'contribution_due_at',
        'tender_opens_at',
        'tender_closes_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'total_pool' => 'decimal:2',
            'winning_bid' => 'decimal:2',
            'surplus' => 'decimal:2',
            'deferred_amount' => 'decimal:2',
            'deferred_released_at' => 'datetime',
            'payout_amount' => 'decimal:2',
            'selected_at' => 'datetime',
            'paid_out_at' => 'datetime',
            'contribution_due_at' => 'datetime',
            'tender_opens_at' => 'datetime',
            'tender_closes_at' => 'datetime',
        ];
    }

    public function wishi(): BelongsTo
    {
        return $this->belongsTo(Wishi::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function surplusRecipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surplus_recipient_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    public function isBiddingOpen(): bool
    {
        if ($this->mode !== 'tender' || $this->status !== 'bidding_open') {
            return false;
        }
        $now = now();
        return $this->tender_opens_at && $this->tender_closes_at
            && $now->between($this->tender_opens_at, $this->tender_closes_at);
    }
}

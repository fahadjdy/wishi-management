<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditScoreLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wishi_id',
        'cycle_id',
        'action',
        'points',
        'score_before',
        'score_after',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'score_before' => 'integer',
            'score_after' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wishi(): BelongsTo
    {
        return $this->belongsTo(Wishi::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }
}

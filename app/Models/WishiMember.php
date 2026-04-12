<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WishiMember extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'wishi_id',
        'user_id',
        'status',
        'is_admin',
        'invited_by_admin',
        'joined_at',
        'has_won',
        'won_in_cycle',
    ];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'invited_by_admin' => 'boolean',
            'has_won' => 'boolean',
            'joined_at' => 'datetime',
            'won_in_cycle' => 'integer',
        ];
    }

    public function wishi(): BelongsTo
    {
        return $this->belongsTo(Wishi::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

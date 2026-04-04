<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'player_id',
        'uuid',
        'status',
        'human_color',
        'current_turn',
        'fen',
        'halfmove_clock',
        'fullmove_number',
        'result',
    ];

    public function moves(): HasMany
    {
        return $this->hasMany(Move::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function isHumanTurn(): bool
    {
        return $this->current_turn === $this->human_color;
    }

    public function botColor(): string
    {
        return $this->human_color === 'white' ? 'black' : 'white';
    }
}

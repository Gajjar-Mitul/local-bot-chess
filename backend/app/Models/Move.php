<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Move extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'ply',
        'player_color',
        'from_square',
        'to_square',
        'promotion',
        'uci',
        'san',
        'fen_after',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}

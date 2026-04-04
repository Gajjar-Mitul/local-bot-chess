<?php

namespace App\Services\Chess;

class BotMoveService
{
    public function __construct(private readonly ChessEngine $engine)
    {
    }

    public function chooseMove(string $fen): ?array
    {
        $state = $this->engine->parseFen($fen);
        $color = $state['turn'];

        $moves = $this->engine->getLegalMoves($fen, $color);
        if (count($moves) === 0) {
            return null;
        }

        shuffle($moves);

        $bestMove = null;
        $bestScore = null;

        foreach ($moves as $move) {
            $result = $this->engine->playMove($fen, $move['from'], $move['to'], $move['promotion']);

            if ($result['status'] === 'checkmate') {
                return $move;
            }

            $material = $this->engine->materialScore($result['fen']);
            $score = $color === 'white' ? $material : -$material;

            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }

        return $bestMove ?? $moves[0];
    }
}

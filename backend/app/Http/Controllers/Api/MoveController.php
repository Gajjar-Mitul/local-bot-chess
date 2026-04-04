<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Move;
use App\Services\Chess\BotMoveService;
use App\Services\Chess\ChessEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MoveController extends Controller
{
    public function __construct(
        private readonly ChessEngine $engine,
        private readonly BotMoveService $botService,
    ) {
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game already finished.'], 422);
        }

        if (!$game->isHumanTurn()) {
            return response()->json(['message' => 'Please wait for bot turn completion.'], 422);
        }

        $validated = $request->validate([
            'from' => ['required', 'regex:/^[a-h][1-8]$/'],
            'to' => ['required', 'regex:/^[a-h][1-8]$/'],
            'promotion' => ['nullable', 'in:Q,R,B,N'],
        ]);

        $from = strtolower($validated['from']);
        $to = strtolower($validated['to']);
        $promotion = $validated['promotion'] ?? null;

        try {
            $humanResult = $this->engine->playMove($game->fen, $from, $to, $promotion);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->persistMoveResult($game, [
            'from' => $from,
            'to' => $to,
            'promotion' => $promotion,
        ], $humanResult);

        $botMovePayload = null;
        $game = $game->fresh();

        if ($game !== null && $game->status === 'in_progress' && !$game->isHumanTurn()) {
            $botMove = $this->botService->chooseMove($game->fen);
            if ($botMove !== null) {
                $botResult = $this->engine->playMove($game->fen, $botMove['from'], $botMove['to'], $botMove['promotion']);
                $this->persistMoveResult($game, $botMove, $botResult);
                $botMovePayload = [
                    'from' => $botMove['from'],
                    'to' => $botMove['to'],
                    'promotion' => $botMove['promotion'],
                    'san' => $botResult['san'],
                    'uci' => $botResult['uci'],
                ];
            }
        }

        $updatedGame = $game?->fresh()?->load('player');

        $isInCheck = ($updatedGame?->status === 'in_progress')
            ? $this->engine->isInCheck($updatedGame->fen, $updatedGame->current_turn)
            : false;

        return response()->json([
            'game' => $updatedGame,
            'moves' => $updatedGame?->moves()->orderBy('ply')->get() ?? [],
            'player' => $updatedGame?->player,
            'human_move' => [
                'from' => $from,
                'to' => $to,
                'promotion' => $promotion,
                'san' => $humanResult['san'],
                'uci' => $humanResult['uci'],
            ],
            'bot_move' => $botMovePayload,
            'is_human_turn' => $updatedGame?->isHumanTurn() ?? false,
            'bot_color' => $updatedGame?->botColor(),
            'is_in_check' => $isInCheck,
        ]);
    }

    private function persistMoveResult(Game $game, array $move, array $result): void
    {
        $ply = (int) $game->moves()->max('ply') + 1;

        Move::query()->create([
            'game_id' => $game->id,
            'ply' => $ply,
            'player_color' => $game->current_turn,
            'from_square' => $move['from'],
            'to_square' => $move['to'],
            'promotion' => $move['promotion'],
            'uci' => $result['uci'],
            'san' => $result['san'],
            'fen_after' => $result['fen'],
        ]);

        $game->update([
            'fen' => $result['fen'],
            'status' => $result['status'],
            'current_turn' => $result['turn'],
            'halfmove_clock' => $result['halfmove'],
            'fullmove_number' => $result['fullmove'],
            'result' => $result['result'],
        ]);
    }
}

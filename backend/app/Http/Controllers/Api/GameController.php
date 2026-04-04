<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Move;
use App\Models\Player;
use App\Services\Chess\BotMoveService;
use App\Services\Chess\ChessEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GameController extends Controller
{
    public function __construct(
        private readonly ChessEngine $engine,
        private readonly BotMoveService $botService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'player_name' => 'required|string|min:2|max:60',
            'human_color' => 'nullable|in:white,black',
        ]);

        $player = Player::query()->firstOrCreate([
            'name' => trim($validated['player_name']),
        ]);

        $humanColor = $validated['human_color'] ?? 'white';
        $state = $this->engine->parseFen($this->engine->startFen());

        $game = Game::query()->create([
            'player_id' => $player->id,
            'uuid' => (string) Str::uuid(),
            'status' => 'in_progress',
            'human_color' => $humanColor,
            'current_turn' => $state['turn'],
            'fen' => $this->engine->startFen(),
            'halfmove_clock' => $state['halfmove'],
            'fullmove_number' => $state['fullmove'],
        ]);

        if (!$game->isHumanTurn()) {
            $this->applyBotMove($game);
        }

        $freshGame = $game->fresh()->load('player');

        return response()->json($this->payload($freshGame, $freshGame->moves()->orderBy('ply')->get()), 201);
    }

    public function show(Game $game): JsonResponse
    {
        $game->load('player');

        return response()->json($this->payload($game, $game->moves()->orderBy('ply')->get()));
    }

    public function moves(Game $game): JsonResponse
    {
        return response()->json([
            'moves' => $game->moves()->orderBy('ply')->get(),
        ]);
    }

    public function legalMoves(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'regex:/^[a-h][1-8]$/'],
        ]);

        if ($game->status !== 'in_progress') {
            return response()->json([
                'from' => strtolower($validated['from']),
                'moves' => [],
                'legal_to' => [],
            ]);
        }

        if (!$game->isHumanTurn()) {
            return response()->json([
                'message' => 'Please wait for bot turn completion.',
                'from' => strtolower($validated['from']),
                'moves' => [],
                'legal_to' => [],
            ], 422);
        }

        $from = strtolower($validated['from']);
        $allMoves = $this->engine->getLegalMoves($game->fen, $game->current_turn);

        $fromMoves = array_values(array_filter(
            $allMoves,
            static fn (array $move): bool => $move['from'] === $from,
        ));

        $movesPayload = array_map(static fn (array $move): array => [
            'to' => $move['to'],
            'promotion' => $move['promotion'],
            'capture' => (bool) $move['capture'],
        ], $fromMoves);

        return response()->json([
            'from' => $from,
            'moves' => $movesPayload,
            'legal_to' => array_values(array_unique(array_map(
                static fn (array $move): string => $move['to'],
                $movesPayload,
            ))),
        ]);
    }

    public function reset(Game $game): JsonResponse
    {
        $game->moves()->delete();

        $state = $this->engine->parseFen($this->engine->startFen());
        $game->update([
            'status' => 'in_progress',
            'current_turn' => $state['turn'],
            'fen' => $this->engine->startFen(),
            'halfmove_clock' => $state['halfmove'],
            'fullmove_number' => $state['fullmove'],
            'result' => null,
        ]);

        if (!$game->isHumanTurn()) {
            $this->applyBotMove($game);
        }

        $freshGame = $game->fresh()->load('player');

        return response()->json($this->payload($freshGame, $freshGame->moves()->orderBy('ply')->get()));
    }

    private function applyBotMove(Game $game): void
    {
        if ($game->status !== 'in_progress') {
            return;
        }

        $botMove = $this->botService->chooseMove($game->fen);
        if ($botMove === null) {
            return;
        }

        $result = $this->engine->playMove($game->fen, $botMove['from'], $botMove['to'], $botMove['promotion']);
        $this->persistMoveResult($game, $botMove, $result);
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

    public function resign(Game $game): JsonResponse
    {
        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is already finished.'], 422);
        }

        $botColor = $game->botColor();
        $result = $botColor === 'white' ? '1-0' : '0-1';

        $game->update([
            'status' => 'resigned',
            'result' => $result,
        ]);

        $freshGame = $game->fresh()->load('player');
        $payload = $this->payload($freshGame, $freshGame->moves()->orderBy('ply')->get());

        if ($freshGame->player_id) {
            $this->refreshPlayerStats($freshGame);
        }

        return response()->json($payload);
    }

    private function refreshPlayerStats(Game $game): void
    {
        // stats are computed on-demand by StatsController; nothing to do here
    }

    private function payload(Game $game, $moves): array
    {
        $isInCheck = $game->status === 'in_progress'
            ? $this->engine->isInCheck($game->fen, $game->current_turn)
            : false;

        return [
            'game' => $game,
            'moves' => $moves,
            'player' => $game->player,
            'bot_color' => $game->botColor(),
            'is_human_turn' => $game->isHumanTurn(),
            'is_in_check' => $isInCheck,
        ];
    }
}

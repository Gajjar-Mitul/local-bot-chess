<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function leaderboard(): JsonResponse
    {
        $leaderboard = Player::query()
            ->leftJoin('games', 'games.player_id', '=', 'players.id')
            ->groupBy('players.id', 'players.name')
            ->select(
                'players.id',
                'players.name',
                DB::raw('COUNT(games.id) as total_games'),
                DB::raw("SUM(CASE WHEN games.result = '1/2-1/2' THEN 1 ELSE 0 END) as draws"),
                DB::raw("SUM(CASE WHEN (games.human_color = 'white' AND games.result = '1-0') OR (games.human_color = 'black' AND games.result = '0-1') THEN 1 ELSE 0 END) as wins"),
                DB::raw("SUM(CASE WHEN (games.human_color = 'white' AND games.result = '0-1') OR (games.human_color = 'black' AND games.result = '1-0') THEN 1 ELSE 0 END) as losses")
            )
            ->orderByDesc('wins')
            ->orderBy('losses')
            ->limit(20)
            ->get()
            ->map(static function ($row): array {
                $wins = (int) $row->wins;
                $losses = (int) $row->losses;
                $draws = (int) $row->draws;
                $total = (int) $row->total_games;

                return [
                    'player_id' => (int) $row->id,
                    'name' => $row->name,
                    'total_games' => $total,
                    'wins' => $wins,
                    'losses' => $losses,
                    'draws' => $draws,
                    'win_rate' => $total > 0 ? round(($wins / $total) * 100, 2) : 0,
                    'win_loss_ratio' => $losses > 0 ? round($wins / $losses, 2) : ($wins > 0 ? $wins : 0),
                ];
            });

        return response()->json([
            'leaderboard' => $leaderboard,
        ]);
    }

    public function history(Player $player): JsonResponse
    {
        $games = $player->games()
            ->with(['moves' => static fn ($query) => $query->orderBy('ply')])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $allGames = $player->games()->get(['result', 'human_color']);

        $summary = [
            'total_games' => $allGames->count(),
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
        ];

        foreach ($allGames as $game) {
            if ($game->result === '1/2-1/2') {
                $summary['draws']++;
                continue;
            }

            $isWin = ($game->human_color === 'white' && $game->result === '1-0')
                || ($game->human_color === 'black' && $game->result === '0-1');

            if ($isWin) {
                $summary['wins']++;
            } elseif ($game->result !== null) {
                $summary['losses']++;
            }
        }

        return response()->json([
            'player' => $player,
            'summary' => $summary,
            'games' => $games,
        ]);
    }
}

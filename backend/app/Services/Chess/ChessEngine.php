<?php

namespace App\Services\Chess;

use InvalidArgumentException;

class ChessEngine
{
    private const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    public function startFen(): string
    {
        return self::START_FEN;
    }

    public function parseFen(string $fen): array
    {
        $parts = preg_split('/\s+/', trim($fen));

        if (!is_array($parts) || count($parts) < 6) {
            throw new InvalidArgumentException('Invalid FEN string.');
        }

        [$boardPart, $turnPart, $castlingPart, $enPassantPart, $halfmovePart, $fullmovePart] = $parts;

        $rows = explode('/', $boardPart);
        if (count($rows) !== 8) {
            throw new InvalidArgumentException('Invalid board section in FEN.');
        }

        $board = [];
        foreach ($rows as $row) {
            $line = [];
            $chars = str_split($row);
            foreach ($chars as $ch) {
                if (ctype_digit($ch)) {
                    $count = (int) $ch;
                    for ($i = 0; $i < $count; $i++) {
                        $line[] = null;
                    }
                } else {
                    $line[] = $ch;
                }
            }

            if (count($line) !== 8) {
                throw new InvalidArgumentException('Invalid rank width in FEN.');
            }

            $board[] = $line;
        }

        $turn = $turnPart === 'w' ? 'white' : 'black';

        return [
            'board' => $board,
            'turn' => $turn,
            'castling' => $castlingPart === '-' ? '' : $castlingPart,
            'en_passant' => $enPassantPart,
            'halfmove' => (int) $halfmovePart,
            'fullmove' => (int) $fullmovePart,
        ];
    }

    public function toFen(array $state): string
    {
        $rows = [];

        for ($r = 0; $r < 8; $r++) {
            $emptyCount = 0;
            $rowFen = '';

            for ($c = 0; $c < 8; $c++) {
                $piece = $state['board'][$r][$c];
                if ($piece === null) {
                    $emptyCount++;
                    continue;
                }

                if ($emptyCount > 0) {
                    $rowFen .= (string) $emptyCount;
                    $emptyCount = 0;
                }

                $rowFen .= $piece;
            }

            if ($emptyCount > 0) {
                $rowFen .= (string) $emptyCount;
            }

            $rows[] = $rowFen;
        }

        $boardFen = implode('/', $rows);
        $turnFen = $state['turn'] === 'white' ? 'w' : 'b';
        $castlingFen = $state['castling'] === '' ? '-' : $state['castling'];

        return sprintf(
            '%s %s %s %s %d %d',
            $boardFen,
            $turnFen,
            $castlingFen,
            $state['en_passant'] ?? '-',
            (int) $state['halfmove'],
            (int) $state['fullmove'],
        );
    }

    public function getLegalMoves(string $fen, string $color): array
    {
        $state = $this->parseFen($fen);
        return $this->generateLegalMovesState($state, $color);
    }

    public function materialScore(string $fen): int
    {
        $state = $this->parseFen($fen);
        $score = 0;

        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                $piece = $state['board'][$r][$c];
                if ($piece === null) {
                    continue;
                }

                $value = match (strtolower($piece)) {
                    'p' => 100,
                    'n' => 320,
                    'b' => 330,
                    'r' => 500,
                    'q' => 900,
                    'k' => 0,
                    default => 0,
                };

                if ($this->pieceColor($piece) === 'white') {
                    $score += $value;
                } else {
                    $score -= $value;
                }
            }
        }

        return $score;
    }

    public function playMove(string $fen, string $from, string $to, ?string $promotion = null): array
    {
        $state = $this->parseFen($fen);
        $color = $state['turn'];

        $candidateMoves = $this->generateLegalMovesState($state, $color);
        $matchingMove = null;

        $normalizedPromotion = $promotion !== null ? strtoupper($promotion) : null;

        foreach ($candidateMoves as $move) {
            if ($move['from'] !== strtolower($from) || $move['to'] !== strtolower($to)) {
                continue;
            }

            $movePromotion = $move['promotion'];
            if ($movePromotion === null && $normalizedPromotion === null) {
                $matchingMove = $move;
                break;
            }

            if ($movePromotion !== null && strtoupper($movePromotion) === $normalizedPromotion) {
                $matchingMove = $move;
                break;
            }
        }

        if ($matchingMove === null) {
            throw new InvalidArgumentException('Illegal move.');
        }

        $nextState = $this->applyMove($state, $matchingMove);
        $nextFen = $this->toFen($nextState);

        $opponent = $nextState['turn'];
        $opponentInCheck = $this->isKingInCheck($nextState, $opponent);
        $opponentMoves = $this->generateLegalMovesState($nextState, $opponent);

        $status = 'in_progress';
        $result = null;

        if (count($opponentMoves) === 0) {
            if ($opponentInCheck) {
                $status = 'checkmate';
                $result = $color === 'white' ? '1-0' : '0-1';
            } else {
                $status = 'stalemate';
                $result = '1/2-1/2';
            }
        } elseif ((int) $nextState['halfmove'] >= 100) {
            $status = 'draw';
            $result = '1/2-1/2';
        }

        return [
            'fen' => $nextFen,
            'turn' => $nextState['turn'],
            'halfmove' => (int) $nextState['halfmove'],
            'fullmove' => (int) $nextState['fullmove'],
            'status' => $status,
            'result' => $result,
            'san' => $this->toSan($matchingMove, $opponentInCheck, $status === 'checkmate'),
            'uci' => $this->toUci($matchingMove),
        ];
    }

    private function toSan(array $move, bool $check, bool $mate): string
    {
        if ($move['castle'] === 'K') {
            return $mate ? 'O-O#' : ($check ? 'O-O+' : 'O-O');
        }

        if ($move['castle'] === 'Q') {
            return $mate ? 'O-O-O#' : ($check ? 'O-O-O+' : 'O-O-O');
        }

        $piece = strtoupper($move['piece']);
        $pieceMark = $piece === 'P' ? '' : $piece;
        $captureMark = $move['capture'] ? 'x' : '';
        $target = $move['to'];

        if ($piece === 'P' && $move['capture']) {
            $pieceMark = $move['from'][0];
        }

        $promotion = $move['promotion'] ? '='.strtoupper($move['promotion']) : '';
        $suffix = $mate ? '#' : ($check ? '+' : '');

        return $pieceMark.$captureMark.$target.$promotion.$suffix;
    }

    private function toUci(array $move): string
    {
        return $move['from'].$move['to'].($move['promotion'] ? strtolower($move['promotion']) : '');
    }

    private function generateLegalMovesState(array $state, string $color): array
    {
        $moves = [];

        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                $piece = $state['board'][$r][$c];
                if ($piece === null || $this->pieceColor($piece) !== $color) {
                    continue;
                }

                $pseudo = $this->generatePseudoMovesForPiece($state, $r, $c);
                foreach ($pseudo as $move) {
                    $preview = $this->applyMove($state, $move);
                    if (!$this->isKingInCheck($preview, $color)) {
                        $moves[] = $move;
                    }
                }
            }
        }

        return $moves;
    }

    private function generatePseudoMovesForPiece(array $state, int $row, int $col): array
    {
        $piece = $state['board'][$row][$col];
        if ($piece === null) {
            return [];
        }

        $color = $this->pieceColor($piece);
        $moves = [];
        $fromSquare = $this->coordsToSquare($row, $col);

        $appendMove = function (int $toRow, int $toCol, bool $capture = false, ?string $promotion = null, bool $enPassant = false, ?string $castle = null) use (&$moves, $row, $col, $piece, $fromSquare, $state): void {
            $capturedPiece = $state['board'][$toRow][$toCol] ?? null;
            if ($enPassant) {
                $capturedPiece = $this->pieceColor($piece) === 'white' ? 'p' : 'P';
            }

            $moves[] = [
                'from_row' => $row,
                'from_col' => $col,
                'to_row' => $toRow,
                'to_col' => $toCol,
                'from' => $fromSquare,
                'to' => $this->coordsToSquare($toRow, $toCol),
                'piece' => $piece,
                'capture' => $capture,
                'captured' => $capturedPiece,
                'promotion' => $promotion,
                'en_passant' => $enPassant,
                'castle' => $castle,
            ];
        };

        $kind = strtolower($piece);

        if ($kind === 'p') {
            $dir = $color === 'white' ? -1 : 1;
            $startRow = $color === 'white' ? 6 : 1;
            $promotionRow = $color === 'white' ? 0 : 7;

            $forwardRow = $row + $dir;
            if ($this->isInside($forwardRow, $col) && $state['board'][$forwardRow][$col] === null) {
                if ($forwardRow === $promotionRow) {
                    foreach (['Q', 'R', 'B', 'N'] as $promo) {
                        $appendMove($forwardRow, $col, false, $promo);
                    }
                } else {
                    $appendMove($forwardRow, $col);
                }

                $doubleRow = $row + 2 * $dir;
                if ($row === $startRow && $state['board'][$doubleRow][$col] === null) {
                    $appendMove($doubleRow, $col);
                }
            }

            foreach ([-1, 1] as $dc) {
                $captureRow = $row + $dir;
                $captureCol = $col + $dc;
                if (!$this->isInside($captureRow, $captureCol)) {
                    continue;
                }

                $targetPiece = $state['board'][$captureRow][$captureCol];
                if ($targetPiece !== null && $this->pieceColor($targetPiece) !== $color) {
                    if ($captureRow === $promotionRow) {
                        foreach (['Q', 'R', 'B', 'N'] as $promo) {
                            $appendMove($captureRow, $captureCol, true, $promo);
                        }
                    } else {
                        $appendMove($captureRow, $captureCol, true);
                    }
                }
            }

            if (($state['en_passant'] ?? '-') !== '-') {
                [$epRow, $epCol] = $this->squareToCoords($state['en_passant']);
                if ($epRow === $row + $dir && abs($epCol - $col) === 1) {
                    $appendMove($epRow, $epCol, true, null, true);
                }
            }

            return $moves;
        }

        if ($kind === 'n') {
            $deltas = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
            foreach ($deltas as [$dr, $dc]) {
                $r = $row + $dr;
                $c = $col + $dc;
                if (!$this->isInside($r, $c)) {
                    continue;
                }

                $targetPiece = $state['board'][$r][$c];
                if ($targetPiece === null) {
                    $appendMove($r, $c);
                } elseif ($this->pieceColor($targetPiece) !== $color) {
                    $appendMove($r, $c, true);
                }
            }

            return $moves;
        }

        if (in_array($kind, ['b', 'r', 'q'], true)) {
            $directions = [];
            if (in_array($kind, ['b', 'q'], true)) {
                $directions = array_merge($directions, [[-1, -1], [-1, 1], [1, -1], [1, 1]]);
            }
            if (in_array($kind, ['r', 'q'], true)) {
                $directions = array_merge($directions, [[-1, 0], [1, 0], [0, -1], [0, 1]]);
            }

            foreach ($directions as [$dr, $dc]) {
                $r = $row + $dr;
                $c = $col + $dc;
                while ($this->isInside($r, $c)) {
                    $targetPiece = $state['board'][$r][$c];
                    if ($targetPiece === null) {
                        $appendMove($r, $c);
                    } else {
                        if ($this->pieceColor($targetPiece) !== $color) {
                            $appendMove($r, $c, true);
                        }
                        break;
                    }
                    $r += $dr;
                    $c += $dc;
                }
            }

            return $moves;
        }

        if ($kind === 'k') {
            for ($dr = -1; $dr <= 1; $dr++) {
                for ($dc = -1; $dc <= 1; $dc++) {
                    if ($dr === 0 && $dc === 0) {
                        continue;
                    }

                    $r = $row + $dr;
                    $c = $col + $dc;
                    if (!$this->isInside($r, $c)) {
                        continue;
                    }

                    $targetPiece = $state['board'][$r][$c];
                    if ($targetPiece === null) {
                        $appendMove($r, $c);
                    } elseif ($this->pieceColor($targetPiece) !== $color) {
                        $appendMove($r, $c, true);
                    }
                }
            }

            $opponent = $color === 'white' ? 'black' : 'white';

            if ($color === 'white' && $row === 7 && $col === 4) {
                if (str_contains($state['castling'], 'K')
                    && $state['board'][7][5] === null
                    && $state['board'][7][6] === null
                    && !$this->isSquareAttacked($state, 7, 4, $opponent)
                    && !$this->isSquareAttacked($state, 7, 5, $opponent)
                    && !$this->isSquareAttacked($state, 7, 6, $opponent)
                ) {
                    $appendMove(7, 6, false, null, false, 'K');
                }

                if (str_contains($state['castling'], 'Q')
                    && $state['board'][7][1] === null
                    && $state['board'][7][2] === null
                    && $state['board'][7][3] === null
                    && !$this->isSquareAttacked($state, 7, 4, $opponent)
                    && !$this->isSquareAttacked($state, 7, 3, $opponent)
                    && !$this->isSquareAttacked($state, 7, 2, $opponent)
                ) {
                    $appendMove(7, 2, false, null, false, 'Q');
                }
            }

            if ($color === 'black' && $row === 0 && $col === 4) {
                if (str_contains($state['castling'], 'k')
                    && $state['board'][0][5] === null
                    && $state['board'][0][6] === null
                    && !$this->isSquareAttacked($state, 0, 4, $opponent)
                    && !$this->isSquareAttacked($state, 0, 5, $opponent)
                    && !$this->isSquareAttacked($state, 0, 6, $opponent)
                ) {
                    $appendMove(0, 6, false, null, false, 'K');
                }

                if (str_contains($state['castling'], 'q')
                    && $state['board'][0][1] === null
                    && $state['board'][0][2] === null
                    && $state['board'][0][3] === null
                    && !$this->isSquareAttacked($state, 0, 4, $opponent)
                    && !$this->isSquareAttacked($state, 0, 3, $opponent)
                    && !$this->isSquareAttacked($state, 0, 2, $opponent)
                ) {
                    $appendMove(0, 2, false, null, false, 'Q');
                }
            }
        }

        return $moves;
    }

    private function applyMove(array $state, array $move): array
    {
        $next = [
            'board' => $state['board'],
            'turn' => $state['turn'],
            'castling' => $state['castling'],
            'en_passant' => '-',
            'halfmove' => $state['halfmove'],
            'fullmove' => $state['fullmove'],
        ];

        $piece = $move['piece'];
        $color = $this->pieceColor($piece);

        $fromRow = $move['from_row'];
        $fromCol = $move['from_col'];
        $toRow = $move['to_row'];
        $toCol = $move['to_col'];

        $next['board'][$fromRow][$fromCol] = null;

        $isCapture = (bool) $move['capture'];
        if ($move['en_passant']) {
            $capturedRow = $color === 'white' ? $toRow + 1 : $toRow - 1;
            $next['board'][$capturedRow][$toCol] = null;
            $isCapture = true;
        }

        $placedPiece = $piece;
        if ($move['promotion'] !== null) {
            $placedPiece = $color === 'white' ? strtoupper($move['promotion']) : strtolower($move['promotion']);
        }
        $next['board'][$toRow][$toCol] = $placedPiece;

        if ($move['castle'] === 'K') {
            if ($color === 'white') {
                $next['board'][7][7] = null;
                $next['board'][7][5] = 'R';
            } else {
                $next['board'][0][7] = null;
                $next['board'][0][5] = 'r';
            }
        }

        if ($move['castle'] === 'Q') {
            if ($color === 'white') {
                $next['board'][7][0] = null;
                $next['board'][7][3] = 'R';
            } else {
                $next['board'][0][0] = null;
                $next['board'][0][3] = 'r';
            }
        }

        $next['castling'] = $this->updateCastlingRights($next['castling'], $piece, $fromRow, $fromCol, $toRow, $toCol, $move['captured']);

        if (strtolower($piece) === 'p' && abs($toRow - $fromRow) === 2) {
            $epRow = (int) (($toRow + $fromRow) / 2);
            $next['en_passant'] = $this->coordsToSquare($epRow, $toCol);
        }

        if (strtolower($piece) === 'p' || $isCapture) {
            $next['halfmove'] = 0;
        } else {
            $next['halfmove'] = (int) $state['halfmove'] + 1;
        }

        if ($color === 'black') {
            $next['fullmove'] = (int) $state['fullmove'] + 1;
        }

        $next['turn'] = $color === 'white' ? 'black' : 'white';

        return $next;
    }

    private function updateCastlingRights(string $rights, string $piece, int $fromRow, int $fromCol, int $toRow, int $toCol, ?string $captured): string
    {
        $updated = $rights;

        if ($piece === 'K') {
            $updated = str_replace(['K', 'Q'], '', $updated);
        }

        if ($piece === 'k') {
            $updated = str_replace(['k', 'q'], '', $updated);
        }

        if ($piece === 'R' && $fromRow === 7 && $fromCol === 0) {
            $updated = str_replace('Q', '', $updated);
        }

        if ($piece === 'R' && $fromRow === 7 && $fromCol === 7) {
            $updated = str_replace('K', '', $updated);
        }

        if ($piece === 'r' && $fromRow === 0 && $fromCol === 0) {
            $updated = str_replace('q', '', $updated);
        }

        if ($piece === 'r' && $fromRow === 0 && $fromCol === 7) {
            $updated = str_replace('k', '', $updated);
        }

        if ($captured === 'R' && $toRow === 7 && $toCol === 0) {
            $updated = str_replace('Q', '', $updated);
        }

        if ($captured === 'R' && $toRow === 7 && $toCol === 7) {
            $updated = str_replace('K', '', $updated);
        }

        if ($captured === 'r' && $toRow === 0 && $toCol === 0) {
            $updated = str_replace('q', '', $updated);
        }

        if ($captured === 'r' && $toRow === 0 && $toCol === 7) {
            $updated = str_replace('k', '', $updated);
        }

        return $updated;
    }

    public function isInCheck(string $fen, string $color): bool
    {
        $state = $this->parseFen($fen);
        return $this->isKingInCheck($state, $color);
    }

    private function isKingInCheck(array $state, string $color): bool
    {
        [$kingRow, $kingCol] = $this->findKing($state, $color);
        if ($kingRow === -1) {
            return false;
        }

        $opponent = $color === 'white' ? 'black' : 'white';
        return $this->isSquareAttacked($state, $kingRow, $kingCol, $opponent);
    }

    private function findKing(array $state, string $color): array
    {
        $target = $color === 'white' ? 'K' : 'k';

        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                if ($state['board'][$r][$c] === $target) {
                    return [$r, $c];
                }
            }
        }

        return [-1, -1];
    }

    private function isSquareAttacked(array $state, int $row, int $col, string $attackerColor): bool
    {
        $pawnDir = $attackerColor === 'white' ? -1 : 1;
        $pawnRow = $row - $pawnDir;
        foreach ([-1, 1] as $dc) {
            $pawnCol = $col + $dc;
            if ($this->isInside($pawnRow, $pawnCol)) {
                $piece = $state['board'][$pawnRow][$pawnCol];
                if ($piece !== null && strtolower($piece) === 'p' && $this->pieceColor($piece) === $attackerColor) {
                    return true;
                }
            }
        }

        $knightMoves = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
        foreach ($knightMoves as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;
            if (!$this->isInside($r, $c)) {
                continue;
            }

            $piece = $state['board'][$r][$c];
            if ($piece !== null && strtolower($piece) === 'n' && $this->pieceColor($piece) === $attackerColor) {
                return true;
            }
        }

        $diagDirections = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
        foreach ($diagDirections as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;
            while ($this->isInside($r, $c)) {
                $piece = $state['board'][$r][$c];
                if ($piece !== null) {
                    if ($this->pieceColor($piece) === $attackerColor && in_array(strtolower($piece), ['b', 'q'], true)) {
                        return true;
                    }
                    break;
                }
                $r += $dr;
                $c += $dc;
            }
        }

        $straightDirections = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ($straightDirections as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;
            while ($this->isInside($r, $c)) {
                $piece = $state['board'][$r][$c];
                if ($piece !== null) {
                    if ($this->pieceColor($piece) === $attackerColor && in_array(strtolower($piece), ['r', 'q'], true)) {
                        return true;
                    }
                    break;
                }
                $r += $dr;
                $c += $dc;
            }
        }

        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) {
                    continue;
                }

                $r = $row + $dr;
                $c = $col + $dc;
                if (!$this->isInside($r, $c)) {
                    continue;
                }

                $piece = $state['board'][$r][$c];
                if ($piece !== null && strtolower($piece) === 'k' && $this->pieceColor($piece) === $attackerColor) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pieceColor(?string $piece): ?string
    {
        if ($piece === null) {
            return null;
        }

        return ctype_upper($piece) ? 'white' : 'black';
    }

    private function squareToCoords(string $square): array
    {
        $normalized = strtolower(trim($square));
        if (!preg_match('/^[a-h][1-8]$/', $normalized)) {
            throw new InvalidArgumentException('Invalid square notation.');
        }

        $file = ord($normalized[0]) - ord('a');
        $rank = (int) $normalized[1];
        $row = 8 - $rank;

        return [$row, $file];
    }

    private function coordsToSquare(int $row, int $col): string
    {
        return chr(ord('a') + $col).(string) (8 - $row);
    }

    private function isInside(int $row, int $col): bool
    {
        return $row >= 0 && $row < 8 && $col >= 0 && $col < 8;
    }
}

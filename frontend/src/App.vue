<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'

type Game = {
    id: number
    player_id: number | null
    uuid: string
    status: string
    human_color: 'white' | 'black'
    current_turn: 'white' | 'black'
    fen: string
    result: string | null
}

type Player = {
    id: number
    name: string
}

type LeaderboardEntry = {
    player_id: number
    name: string
    total_games: number
    wins: number
    losses: number
    draws: number
    win_rate: number
    win_loss_ratio: number
}

type PlayerSummary = {
    total_games: number
    wins: number
    losses: number
    draws: number
}

type Move = {
    id: number
    ply: number
    player_color: 'white' | 'black'
    san: string | null
    uci: string
    fen_after: string
}

type MovePayload = {
    from: string
    to: string
    promotion?: string | null
}

type ApiPayload = {
    game: Game
    moves: Move[]
    player?: Player | null
    is_human_turn: boolean
    bot_color: 'white' | 'black'
    is_in_check: boolean
    human_move?: MovePayload
    bot_move?: MovePayload | null
}

type MovingPiece = {
    glyph: string
    tone: 'white' | 'black'
    row: number
    col: number
}

const apiBase = import.meta.env.VITE_API_BASE ?? 'http://127.0.0.1:8000/api'

const game = ref<Game | null>(null)
const boardFen = ref<string>('8/8/8/8/8/8/8/8 w - - 0 1')
const moves = ref<Move[]>([])
const selectedSquare = ref<string | null>(null)
const legalTargets = ref<string[]>([])
const movingPiece = ref<MovingPiece | null>(null)
const movingFromSquare = ref<string | null>(null)
const errorMessage = ref('')
const loading = ref(false)
const botThinking = ref(false)
const humanColor = ref<'white' | 'black'>('white')
const humanName = ref('')
const currentPlayer = ref<Player | null>(null)
const leaderboard = ref<LeaderboardEntry[]>([])
const playerSummary = ref<PlayerSummary | null>(null)
const matchHistory = ref<Array<{ id: number; result: string | null; status: string; moves: Move[] }>>([])
const lastMove = ref<{ from: string; to: string } | null>(null)
const promotionPending = ref<{ from: string; to: string } | null>(null)
const isInCheck = ref(false)
const gameOverDismissed = ref(false)

const checkedKingSquare = computed(() => {
    if (!isInCheck.value || !game.value) return null
    const kingPiece = game.value.current_turn === 'white' ? 'K' : 'k'
    for (let r = 0; r < 8; r++) {
        for (let c = 0; c < 8; c++) {
            if (board.value[r][c] === kingPiece) {
                return squareName(r, c)
            }
        }
    }
    return null
})

const gameOverInfo = computed(() => {
    if (!game.value || gameOverDismissed.value) return null
    const s = game.value.status
    if (s === 'in_progress') return null

    if (s === 'checkmate') {
        const result = game.value.result
        const humanWon = (humanColor.value === 'white' && result === '1-0') || (humanColor.value === 'black' && result === '0-1')
        return {
            emoji: humanWon ? '🏆' : '💀',
            title: humanWon ? 'You Win!' : 'You Lose!',
            subtitle: 'Checkmate',
            color: humanWon ? 'win' : 'lose',
        }
    }
    if (s === 'stalemate') return { emoji: '🤝', title: 'Draw', subtitle: 'Stalemate', color: 'draw' }
    if (s === 'draw') return { emoji: '🤝', title: 'Draw', subtitle: game.value.result ?? 'Draw', color: 'draw' }
    if (s === 'resigned') return { emoji: '🏳️', title: 'You Resigned', subtitle: 'Better luck next time', color: 'lose' }
    return null
})

const pieceMap: Record<string, string> = {
    P: '♟',
    N: '♞',
    B: '♝',
    R: '♜',
    Q: '♛',
    K: '♚',
    p: '♟',
    n: '♞',
    b: '♝',
    r: '♜',
    q: '♛',
    k: '♚',
}

const board = computed(() => {
    if (!game.value) {
        return Array.from({ length: 8 }, () => Array.from({ length: 8 }, () => null as string | null))
    }

    const [boardPart] = boardFen.value.split(' ')
    const rows = boardPart.split('/')
    return rows.map((row) => {
        const parsed: (string | null)[] = []
        row.split('').forEach((ch) => {
            if (/\d/.test(ch)) {
                for (let i = 0; i < Number(ch); i++) {
                    parsed.push(null)
                }
            } else {
                parsed.push(ch)
            }
        })
        return parsed
    })
})

const statusLabel = computed(() => {
    if (!game.value) return 'Create a game to start.'
    if (game.value.status === 'checkmate') return `Checkmate (${game.value.result ?? 'finished'})`
    if (game.value.status === 'stalemate') return 'Stalemate'
    if (game.value.status === 'draw') return 'Draw'
    return `${game.value.current_turn.toUpperCase()} to move`
})

const canMove = computed(() => game.value && game.value.status === 'in_progress' && game.value.current_turn === humanColor.value)

function squareName(row: number, col: number): string {
    return `${String.fromCharCode(97 + col)}${8 - row}`
}

function isLightSquare(row: number, col: number): boolean {
    return (row + col) % 2 === 0
}

function isHumanPiece(piece: string | null): boolean {
    if (!piece) return false
    const white = piece === piece.toUpperCase()
    return (humanColor.value === 'white' && white) || (humanColor.value === 'black' && !white)
}

function pieceTone(piece: string | null): 'white' | 'black' {
    if (!piece) return 'white'
    return piece === piece.toUpperCase() ? 'white' : 'black'
}

async function request<T>(path: string, options?: RequestInit): Promise<T> {
    const response = await fetch(`${apiBase}${path}`, {
        headers: { 'Content-Type': 'application/json' },
        ...options,
    })

    const data = await response.json()
    if (!response.ok) {
        throw new Error(data.message ?? 'Request failed')
    }

    return data as T
}

function applyPayload(payload: ApiPayload) {
    game.value = payload.game
    currentPlayer.value = payload.player ?? currentPlayer.value
    boardFen.value = payload.game.fen
    moves.value = payload.moves
    selectedSquare.value = null
    legalTargets.value = []
    isInCheck.value = payload.is_in_check ?? false
}

async function refreshLeaderboard() {
    const data = await request<{ leaderboard: LeaderboardEntry[] }>('/stats/leaderboard')
    leaderboard.value = data.leaderboard
}

async function refreshPlayerHistory(playerId: number) {
    const data = await request<{
        summary: PlayerSummary
        games: Array<{ id: number; result: string | null; status: string; moves: Move[] }>
    }>(`/players/${playerId}/history`)

    playerSummary.value = data.summary
    matchHistory.value = data.games
}

async function refreshStats(playerId: number | null | undefined) {
    await refreshLeaderboard()

    if (playerId) {
        await refreshPlayerHistory(playerId)
    }
}

function parseBoardFromFen(fen: string): (string | null)[][] {
    const [boardPart] = fen.split(' ')
    const rows = boardPart.split('/')
    return rows.map((row) => {
        const parsed: (string | null)[] = []
        row.split('').forEach((ch) => {
            if (/\d/.test(ch)) {
                for (let i = 0; i < Number(ch); i++) {
                    parsed.push(null)
                }
            } else {
                parsed.push(ch)
            }
        })
        return parsed
    })
}

function squareToCoords(square: string): { row: number; col: number } {
    return {
        row: 8 - Number(square[1]),
        col: square.charCodeAt(0) - 97,
    }
}

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
}

async function animateMove(move: MovePayload, sourceFen: string): Promise<void> {
    const source = parseBoardFromFen(sourceFen)
    const from = squareToCoords(move.from)
    const to = squareToCoords(move.to)
    const piece = source[from.row]?.[from.col]

    if (!piece) {
        return
    }

    movingFromSquare.value = move.from
    movingPiece.value = {
        glyph: pieceMap[piece],
        tone: pieceTone(piece),
        row: from.row,
        col: from.col,
    }

    await nextTick()

    if (movingPiece.value) {
        movingPiece.value = {
            ...movingPiece.value,
            row: to.row,
            col: to.col,
        }
    }

    await sleep(260)
    movingPiece.value = null
    movingFromSquare.value = null
}

async function loadLegalMoves(from: string) {
    if (!game.value) return

    try {
        const payload = await request<{ legal_to: string[] }>(
            `/games/${game.value.id}/legal-moves?from=${from}`,
        )
        legalTargets.value = payload.legal_to
    } catch {
        legalTargets.value = []
    }
}

async function resignGame() {
    if (!game.value) return

    loading.value = true
    errorMessage.value = ''

    try {
        const payload = await request<ApiPayload>(`/games/${game.value.id}/resign`, { method: 'POST' })
        applyPayload(payload)
        lastMove.value = null
        await refreshStats(payload.player?.id)
    } catch (error) {
        errorMessage.value = (error as Error).message
    } finally {
        loading.value = false
    }
}

async function createGame() {
    loading.value = true
    errorMessage.value = ''
    selectedSquare.value = null

    if (humanName.value.trim().length < 2) {
        errorMessage.value = 'Please enter your name (min 2 chars).'
        loading.value = false
        return
    }

    try {
        const payload = await request<ApiPayload>('/games', {
            method: 'POST',
            body: JSON.stringify({
                player_name: humanName.value.trim(),
                human_color: humanColor.value,
            }),
        })
        gameOverDismissed.value = false
        applyPayload(payload)
        await refreshStats(payload.player?.id)
    } catch (error) {
        errorMessage.value = (error as Error).message
    } finally {
        loading.value = false
    }
}

async function resetGame() {
    if (!game.value) return

    loading.value = true
    errorMessage.value = ''

    try {
        const payload = await request<ApiPayload>(`/games/${game.value.id}/reset`, { method: 'POST' })
        gameOverDismissed.value = false
        applyPayload(payload)
        await refreshStats(payload.player?.id)
    } catch (error) {
        errorMessage.value = (error as Error).message
    } finally {
        loading.value = false
    }
}

async function submitMove(from: string, to: string, promotion?: string) {
    if (!game.value) return

    botThinking.value = true
    errorMessage.value = ''

    try {
        const payload = await request<ApiPayload>(`/games/${game.value.id}/moves`, {
            method: 'POST',
            body: JSON.stringify({ from, to, promotion }),
        })

        const beforeFen = boardFen.value
        const hasBotMove = !!payload.bot_move
        const humanFen = hasBotMove
            ? payload.moves[payload.moves.length - 2]?.fen_after ?? payload.game.fen
            : payload.moves[payload.moves.length - 1]?.fen_after ?? payload.game.fen

        if (payload.human_move) {
            await animateMove(payload.human_move, beforeFen)
            boardFen.value = humanFen
            lastMove.value = { from: payload.human_move.from, to: payload.human_move.to }
        }

        if (payload.bot_move) {
            await sleep(650)
            await animateMove(payload.bot_move, humanFen)
            boardFen.value = payload.game.fen
            lastMove.value = { from: payload.bot_move.from, to: payload.bot_move.to }
        } else {
            // No bot move, keep the highlight visible
            await nextTick()
        }

        applyPayload(payload)
        // Preserve lastMove after applyPayload
        if (payload.human_move) {
            lastMove.value = { from: payload.human_move.from, to: payload.human_move.to }
        }
        if (payload.bot_move) {
            lastMove.value = { from: payload.bot_move.from, to: payload.bot_move.to }
        }
        await refreshStats(payload.player?.id)
    } catch (error) {
        errorMessage.value = (error as Error).message
    } finally {
        botThinking.value = false
    }
}

void refreshLeaderboard()

function onSquareClick(row: number, col: number) {
    if (!game.value || !canMove.value || botThinking.value) return

    const sq = squareName(row, col)
    const piece = board.value[row][col]

    if (selectedSquare.value === null) {
        if (isHumanPiece(piece)) {
            errorMessage.value = ''
            selectedSquare.value = sq
            void loadLegalMoves(sq)
        }
        return
    }

    if (selectedSquare.value === sq) {
        selectedSquare.value = null
        legalTargets.value = []
        return
    }

    if (isHumanPiece(piece)) {
        errorMessage.value = ''
        selectedSquare.value = sq
        void loadLegalMoves(sq)
        return
    }

    if (!legalTargets.value.includes(sq)) {
        errorMessage.value = 'That is not a legal destination for this piece.'
        return
    }

    // Check if it's a pawn promotion move
    const coords = squareToCoords(selectedSquare.value)
    const selectedPiece = board.value[coords.row]?.[coords.col]
    const destRow = 8 - Number(sq[1])
    const isPawnPromotion = (selectedPiece === 'P' && destRow === 0) || (selectedPiece === 'p' && destRow === 7)

    if (isPawnPromotion) {
        promotionPending.value = { from: selectedSquare.value, to: sq }
        return
    }

    void submitMove(selectedSquare.value, sq)
}
</script>

<template>
    <div class="page">
        <header class="hero">
            <h1>Local Chess: Human vs Bot</h1>
            <p>Vue frontend + Laravel chess engine, fully local.</p>
        </header>

        <section class="controls">
            <label>
                Human Name
                <input v-model="humanName" type="text" maxlength="60" placeholder="Enter your name"
                    :disabled="loading" />
            </label>
            <label>
                Human Color
                <select v-model="humanColor" :disabled="loading || !!game">
                    <option value="white">White</option>
                    <option value="black">Black</option>
                </select>
            </label>
            <button @click="createGame" :disabled="loading">{{ game ? 'New Game' : 'Start Game' }}</button>
            <button @click="resetGame" :disabled="loading || !game">Reset Current</button>
            <button @click="resignGame" :disabled="loading || !game || game.status !== 'in_progress'"
                class="btn-resign">Resign</button>
        </section>

        <section class="status">
            <strong>{{ statusLabel }}</strong>
            <span v-if="botThinking">Bot is thinking...</span>
            <span v-if="errorMessage" class="error">{{ errorMessage }}</span>
        </section>

        <main class="layout">
            <div class="board" v-if="game">
                <div v-for="(row, rowIdx) in board" :key="rowIdx" class="board-row">
                    <button v-for="(piece, colIdx) in row" :key="`${rowIdx}-${colIdx}`" class="square" :class="{
                        light: isLightSquare(rowIdx, colIdx),
                        dark: !isLightSquare(rowIdx, colIdx),
                        selected: selectedSquare === squareName(rowIdx, colIdx),
                        target: legalTargets.includes(squareName(rowIdx, colIdx)),
                        'last-move': lastMove && (lastMove.from === squareName(rowIdx, colIdx) || lastMove.to === squareName(rowIdx, colIdx)),
                        'in-check': checkedKingSquare === squareName(rowIdx, colIdx),
                    }" @click="onSquareClick(rowIdx, colIdx)">
                        <span v-if="!(movingPiece && movingFromSquare === squareName(rowIdx, colIdx))" class="piece"
                            :class="piece ? `piece-${pieceTone(piece)}` : ''">
                            {{ piece ? pieceMap[piece] : '' }}
                        </span>
                        <span v-if="!piece && legalTargets.includes(squareName(rowIdx, colIdx))"
                            class="target-dot"></span>
                        <span v-if="piece && legalTargets.includes(squareName(rowIdx, colIdx))"
                            class="capture-ring"></span>
                        <small class="coord">{{ squareName(rowIdx, colIdx) }}</small>
                    </button>
                </div>

                <div v-if="movingPiece" class="moving-piece" :class="`piece-${movingPiece.tone}`"
                    :style="{ transform: `translate(${movingPiece.col * 100}%, ${movingPiece.row * 100}%)` }">
                    {{ movingPiece.glyph }}
                </div>
            </div>

            <!-- Game Over Overlay -->
            <div v-if="gameOverInfo" class="gameover-overlay">
                <div class="gameover-card" :class="`gameover-${gameOverInfo.color}`">
                    <div class="gameover-emoji">{{ gameOverInfo.emoji }}</div>
                    <h2 class="gameover-title">{{ gameOverInfo.title }}</h2>
                    <p class="gameover-subtitle">{{ gameOverInfo.subtitle }}</p>
                    <div class="gameover-actions">
                        <button @click="createGame" :disabled="loading">New Game</button>
                        <button @click="gameOverDismissed = true" class="btn-secondary">View Board</button>
                    </div>
                </div>
            </div>

            <!-- Promotion Dialog -->
            <div v-if="promotionPending" class="promotion-dialog">
                <div class="promotion-content">
                    <p>Choose promotion piece:</p>
                    <div class="promotion-options">
                        <button v-for="piece in ['q', 'r', 'b', 'n']" :key="piece" class="promotion-btn"
                            @click="submitMove(promotionPending.from, promotionPending.to, humanColor === 'white' ? piece.toUpperCase() : piece); promotionPending = null">
                            <span class="piece" :class="`piece-${humanColor}`">{{ pieceMap[humanColor === 'white' ?
                                piece.toUpperCase() : piece] }}</span>
                            <span>{{ { q: 'Queen', r: 'Rook', b: 'Bishop', n: 'Knight' }[piece] }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>Move List</h2>
                <ol class="move-list" v-if="moves.length">
                    <li v-for="(move, idx) in moves" :key="idx">
                        <span>#{{ move.ply }}</span>
                        <span>{{ move.player_color }}</span>
                        <strong>{{ move.san ?? move.uci }}</strong>
                    </li>
                </ol>
                <p v-else>No moves yet.</p>

                <div class="panel-section" v-if="currentPlayer">
                    <h3>{{ currentPlayer.name }} Stats</h3>
                    <p v-if="playerSummary">
                        Games: {{ playerSummary.total_games }} | W: {{ playerSummary.wins }} | L: {{
                            playerSummary.losses }} | D: {{ playerSummary.draws }}
                    </p>
                    <ul class="compact-list" v-if="matchHistory.length">
                        <li v-for="historyGame in matchHistory.slice(0, 5)" :key="historyGame.id">
                            <span>Match #{{ historyGame.id }}</span>
                            <strong>{{ historyGame.result ?? historyGame.status }}</strong>
                            <span>{{ historyGame.moves.length }} moves</span>
                        </li>
                    </ul>
                </div>

                <div class="panel-section">
                    <h3>Leaderboard</h3>
                    <ul class="compact-list" v-if="leaderboard.length">
                        <li v-for="entry in leaderboard.slice(0, 5)" :key="entry.player_id">
                            <span>{{ entry.name }}</span>
                            <strong>{{ entry.wins }}W / {{ entry.losses }}L</strong>
                            <span>{{ entry.win_rate }}%</span>
                        </li>
                    </ul>
                    <p v-else>No leaderboard data yet.</p>
                </div>
            </div>
        </main>
    </div>
</template>

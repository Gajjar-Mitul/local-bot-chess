<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'

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
    name?: string
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

type BoardTheme =
    | 'green'
    | 'wood'
    | 'glass'
    | 'brown'
    | 'icy-sea'
    | 'newspaper'
    | 'walnut'
    | 'sky'
    | 'stone'
    | 'bases'

type PieceStyle =
    | 'neo'
    | 'neo-angle'
    | 'game-room'
    | 'wood'
    | 'glass'
    | 'gothic'
    | 'classic'
    | 'metal'
    | 'bases'
    | 'neo-wood'
    | 'icy-sea'
    | 'club'
    | 'ocean'
    | 'newspaper'
    | 'alpha'

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
const humanName = ref(localStorage.getItem('chess_playerName') ?? '')
const currentPlayer = ref<Player | null>(null)
const leaderboard = ref<LeaderboardEntry[]>([])
const playerSummary = ref<PlayerSummary | null>(null)
const matchHistory = ref<Array<{ id: number; result: string | null; status: string; human_color: 'white' | 'black'; moves: Move[] }>>([])
const lastMove = ref<{ from: string; to: string } | null>(null)
const promotionPending = ref<{ from: string; to: string } | null>(null)
const isInCheck = ref(false)
const gameOverDismissed = ref(false)
const boardTheme = ref<BoardTheme>((localStorage.getItem('chess_boardTheme') as BoardTheme) ?? 'green')
const pieceStyle = ref<PieceStyle>((localStorage.getItem('chess_pieceStyle') as PieceStyle) ?? 'neo')
const appearanceOpen = ref(false)
const selectedHistoryGame = ref<{ id: number; result: string | null; status: string; human_color: 'white' | 'black'; moves: Move[] } | null>(null)
const reviewIndex = ref(-1) // -1 = start pos, 0..n-1 = after that move

watch(boardTheme, (v) => localStorage.setItem('chess_boardTheme', v))
watch(pieceStyle, (v) => localStorage.setItem('chess_pieceStyle', v))
watch(humanName, (v) => localStorage.setItem('chess_playerName', v))

const STARTING_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'

const reviewFen = computed(() => {
    if (!selectedHistoryGame.value) return STARTING_FEN
    if (reviewIndex.value < 0) return STARTING_FEN
    return selectedHistoryGame.value.moves[reviewIndex.value]?.fen_after ?? STARTING_FEN
})

const reviewBoard = computed(() => {
    const [boardPart] = reviewFen.value.split(' ')
    return boardPart.split('/').map((row) => {
        const parsed: (string | null)[] = []
        for (const ch of row.split('')) {
            if (/\d/.test(ch)) {
                for (let i = 0; i < Number(ch); i++) parsed.push(null)
            } else {
                parsed.push(ch)
            }
        }
        return parsed
    })
})

const reviewFlipped = computed(() => selectedHistoryGame.value?.human_color === 'black')

function reviewSquareName(rowIdx: number, colIdx: number): string {
    return reviewFlipped.value
        ? `${String.fromCharCode(97 + (7 - colIdx))}${rowIdx + 1}`
        : `${String.fromCharCode(97 + colIdx)}${8 - rowIdx}`
}

function openReview(hg: typeof selectedHistoryGame.value) {
    selectedHistoryGame.value = hg
    reviewIndex.value = -1
}

const defeatedKingSquare = computed(() => {
    if (!game.value || game.value.status !== 'checkmate') return null
    // The loser is the side that was just checkmated — they had the move when checkmate landed,
    // i.e. the side whose turn it WAS (current_turn after move resolves to the mated side? No —
    // after checkmate the engine leaves current_turn as the mated color).
    // result '1-0' means white won → black king is defeated; '0-1' → white king defeated.
    const result = game.value.result
    const loserKing = result === '1-0' ? 'k' : 'K'
    for (let r = 0; r < 8; r++) {
        for (let c = 0; c < 8; c++) {
            if (board.value[r][c] === loserKing) {
                return squareName(r, c)
            }
        }
    }
    return null
})

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

const pieceSets: Record<PieceStyle, Record<string, string>> = {
    neo: {
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
    },
    'neo-angle': {
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
    },
    'game-room': {
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
    },
    wood: {
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
    },
    glass: {
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
    },
    gothic: {
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
    },
    classic: {
        P: '♙',
        N: '♘',
        B: '♗',
        R: '♖',
        Q: '♕',
        K: '♔',
        p: '♟',
        n: '♞',
        b: '♝',
        r: '♜',
        q: '♛',
        k: '♚',
    },
    metal: {
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
    },
    bases: {
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
    },
    'neo-wood': {
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
    },
    'icy-sea': {
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
    },
    club: {
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
    },
    ocean: {
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
    },
    newspaper: {
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
    },
    alpha: {
        P: 'P',
        N: 'N',
        B: 'B',
        R: 'R',
        Q: 'Q',
        K: 'K',
        p: 'P',
        n: 'N',
        b: 'B',
        r: 'R',
        q: 'Q',
        k: 'K',
    },
}

const activePieceMap = computed(() => pieceSets[pieceStyle.value])

const materialValues: Record<string, number> = {
    p: 1,
    n: 3,
    b: 3,
    r: 5,
    q: 9,
    k: 0,
}

const startingPieceCounts: Record<string, number> = {
    p: 8,
    n: 2,
    b: 2,
    r: 2,
    q: 1,
    k: 1,
}

const capturedDisplayOrder = ['q', 'r', 'b', 'n', 'p']

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

const boardPieceCounts = computed(() => {
    const white: Record<string, number> = { p: 0, n: 0, b: 0, r: 0, q: 0, k: 0 }
    const black: Record<string, number> = { p: 0, n: 0, b: 0, r: 0, q: 0, k: 0 }

    if (!game.value) {
        return { white, black }
    }

    for (const row of board.value) {
        for (const piece of row) {
            if (!piece) continue
            const key = piece.toLowerCase()
            if (!(key in white)) continue
            if (piece === piece.toUpperCase()) {
                white[key] += 1
            } else {
                black[key] += 1
            }
        }
    }

    return { white, black }
})

function expandCapturedPieces(capturedColor: 'white' | 'black'): string[] {
    if (!game.value) return []

    const current = capturedColor === 'white' ? boardPieceCounts.value.white : boardPieceCounts.value.black
    const captured: string[] = []

    for (const pieceType of capturedDisplayOrder) {
        const missing = Math.max(0, startingPieceCounts[pieceType] - current[pieceType])
        for (let i = 0; i < missing; i += 1) {
            captured.push(capturedColor === 'white' ? pieceType.toUpperCase() : pieceType)
        }
    }

    return captured
}

const capturedByBlack = computed(() => expandCapturedPieces('white'))
const capturedByWhite = computed(() => expandCapturedPieces('black'))

const materialScore = computed(() => {
    if (!game.value) return 0

    let whiteTotal = 0
    let blackTotal = 0

    for (const [pieceType, count] of Object.entries(boardPieceCounts.value.white)) {
        whiteTotal += (materialValues[pieceType] ?? 0) * count
    }

    for (const [pieceType, count] of Object.entries(boardPieceCounts.value.black)) {
        blackTotal += (materialValues[pieceType] ?? 0) * count
    }

    return whiteTotal - blackTotal
})

const clampedMaterialScore = computed(() => Math.max(-10, Math.min(10, materialScore.value)))
const whiteEvalHeight = computed(() => `${(Math.max(0, clampedMaterialScore.value) / 10) * 50}%`)
const blackEvalHeight = computed(() => `${(Math.max(0, -clampedMaterialScore.value) / 10) * 50}%`)

const statusLabel = computed(() => {
    if (!game.value) return 'Create a game to start.'
    if (game.value.status === 'checkmate') return `Checkmate (${game.value.result ?? 'finished'})`
    if (game.value.status === 'stalemate') return 'Stalemate'
    if (game.value.status === 'draw') return 'Draw'
    return `${game.value.current_turn.toUpperCase()} to move`
})

const canMove = computed(() => game.value && game.value.status === 'in_progress' && game.value.current_turn === humanColor.value)

const boardFlipped = computed(() => humanColor.value === 'black')

const displayBoard = computed(() => {
    if (!boardFlipped.value) return board.value
    return [...board.value].reverse().map(row => [...row].reverse())
})

function squareName(row: number, col: number): string {
    return `${String.fromCharCode(97 + col)}${8 - row}`
}

function displaySquareName(rowIdx: number, colIdx: number): string {
    return boardFlipped.value
        ? squareName(7 - rowIdx, 7 - colIdx)
        : squareName(rowIdx, colIdx)
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
    if (payload.player?.id) {
        localStorage.setItem('chess_playerId', String(payload.player.id))
    }
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
        games: Array<{ id: number; result: string | null; status: string; human_color: 'white' | 'black'; moves: Move[] }>
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

    const toDisplayCoords = (r: number, c: number) =>
        boardFlipped.value ? { row: 7 - r, col: 7 - c } : { row: r, col: c }

    const dispFrom = toDisplayCoords(from.row, from.col)
    const dispTo = toDisplayCoords(to.row, to.col)

    movingFromSquare.value = move.from
    movingPiece.value = {
        glyph: activePieceMap.value[piece],
        tone: pieceTone(piece),
        row: dispFrom.row,
        col: dispFrom.col,
    }

    await nextTick()

    if (movingPiece.value) {
        movingPiece.value = {
            ...movingPiece.value,
            row: dispTo.row,
            col: dispTo.col,
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

function goHome() {
    const playerId = currentPlayer.value?.id ?? null
    game.value = null
    boardFen.value = '8/8/8/8/8/8/8/8 w - - 0 1'
    moves.value = []
    selectedSquare.value = null
    legalTargets.value = []
    movingPiece.value = null
    movingFromSquare.value = null
    errorMessage.value = ''
    botThinking.value = false
    lastMove.value = null
    promotionPending.value = null
    isInCheck.value = false
    gameOverDismissed.value = false
    currentPlayer.value = null
    playerSummary.value = null
    matchHistory.value = []
    appearanceOpen.value = false
    selectedHistoryGame.value = null
    reviewIndex.value = -1
    void refreshStats(playerId)
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

// On startup: load leaderboard + history\n{\n    const savedId = localStorage.getItem('chess_playerId')\n    const savedName = localStorage.getItem('chess_playerName')\n\n    if (savedId) {\n        void refreshStats(Number(savedId))\n    } else if (savedName && savedName.trim().length >= 2) {\n        try {\n            const data = await request<{\n                player: Player | null\n                summary: PlayerSummary | null\n                games: Array<{ id: number; result: string | null; status: string; human_color: 'white' | 'black'; moves: Move[] }>\n            }>(`/players/lookup?name=${encodeURIComponent(savedName.trim())}`)\n            if (data.player) {\n                localStorage.setItem('chess_playerId', String(data.player.id))\n                currentPlayer.value = data.player\n            }\n            if (data.summary) playerSummary.value = data.summary\n            if (data.games) matchHistory.value = data.games\n            void refreshLeaderboard()\n        } catch {\n            void refreshLeaderboard()\n        }\n    } else {\n        void refreshLeaderboard()\n    }\n}

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
            <div class="controls-main">
                <label>
                    Human Name
                    <input v-model="humanName" type="text" maxlength="60" placeholder="Enter your name"
                        :disabled="loading" />
                </label>
                <button @click="createGame" :disabled="loading" class="btn-primary">{{ game ? 'New Game' : 'Start Game' }}</button>
                <label>
                    Human Color
                    <select v-model="humanColor" :disabled="loading || !!(game && game.status === 'in_progress')">
                        <option value="white">White</option>
                        <option value="black">Black</option>
                    </select>
                </label>
            </div>

            <div class="controls-actions" v-if="game">
                <button @click="resetGame" :disabled="loading || !game" class="icon-btn" title="Reset Current">↺</button>
                <button @click="resignGame" :disabled="loading || !game || game.status !== 'in_progress'"
                    class="icon-btn icon-danger" title="Resign">⚑</button>
                <button @click="goHome" :disabled="loading || !game" class="icon-btn icon-home" title="Back Home">⌂</button>
                <button @click="appearanceOpen = !appearanceOpen" :disabled="loading" class="icon-btn" title="Appearance">⚙</button>
            </div>

            <button v-else @click="appearanceOpen = !appearanceOpen" :disabled="loading" class="btn-ghost">Appearance</button>
        </section>

        <section v-if="appearanceOpen" class="appearance-panel">
            <label>
                Board Theme
                <select v-model="boardTheme" :disabled="loading">
                    <option value="green">Green</option>
                    <option value="wood">Wood</option>
                    <option value="glass">Glass</option>
                    <option value="brown">Brown</option>
                    <option value="icy-sea">Icy Sea</option>
                    <option value="newspaper">Newspaper</option>
                    <option value="walnut">Walnut</option>
                    <option value="sky">Sky</option>
                    <option value="stone">Stone</option>
                    <option value="bases">Bases</option>
                </select>
            </label>
            <label>
                Piece Style
                <select v-model="pieceStyle" :disabled="loading">
                    <option value="neo">Neo</option>
                    <option value="neo-angle">Neo Angle</option>
                    <option value="game-room">Game Room</option>
                    <option value="wood">Wood</option>
                    <option value="glass">Glass</option>
                    <option value="gothic">Gothic</option>
                    <option value="classic">Classic</option>
                    <option value="metal">Metal</option>
                    <option value="bases">Bases</option>
                    <option value="neo-wood">Neo-Wood</option>
                    <option value="icy-sea">Icy Sea</option>
                    <option value="club">Club</option>
                    <option value="ocean">Ocean</option>
                    <option value="newspaper">Newspaper</option>
                    <option value="alpha">Alpha (Letters)</option>
                </select>
            </label>
        </section>

        <section class="status">
            <strong>{{ statusLabel }}</strong>
            <span v-if="botThinking">Bot is thinking...</span>
            <span v-if="errorMessage" class="error">{{ errorMessage }}</span>
        </section>

        <main class="layout">
            <div class="board-shell" :class="`piece-style-${pieceStyle}`" v-if="game">
                <div class="captured-tray captured-top">
                    <strong>Black Pieces Captured</strong>
                    <div class="captured-pieces">
                        <span v-for="(piece, idx) in capturedByWhite" :key="`cw-${idx}`" class="piece piece-captured-black">
                            {{ activePieceMap[piece] }}
                        </span>
                        <span v-if="!capturedByWhite.length" class="captured-empty">-</span>
                    </div>
                </div>

                <div class="board-main">
                    <div class="eval-column">
                        <div class="eval-score">{{ clampedMaterialScore > 0 ? '+' : '' }}{{ clampedMaterialScore }}</div>
                        <div class="eval-bar">
                            <div class="eval-center-line"></div>
                            <div class="eval-white" :style="{ height: whiteEvalHeight }"></div>
                            <div class="eval-black" :style="{ height: blackEvalHeight }"></div>
                        </div>
                        <small>Eval</small>
                    </div>

                    <div class="board" :class="`theme-${boardTheme}`">
                        <div v-for="(row, rowIdx) in displayBoard" :key="rowIdx" class="board-row">
                            <button v-for="(piece, colIdx) in row" :key="`${rowIdx}-${colIdx}`" class="square" :class="{
                                light: isLightSquare(rowIdx, colIdx),
                                dark: !isLightSquare(rowIdx, colIdx),
                                selected: selectedSquare === displaySquareName(rowIdx, colIdx),
                                target: legalTargets.includes(displaySquareName(rowIdx, colIdx)),
                                'last-move': lastMove && (lastMove.from === displaySquareName(rowIdx, colIdx) || lastMove.to === displaySquareName(rowIdx, colIdx)),
                                'in-check': checkedKingSquare === displaySquareName(rowIdx, colIdx),
                                'defeated-king': defeatedKingSquare === displaySquareName(rowIdx, colIdx),
                            }" @click="onSquareClick(boardFlipped ? 7 - rowIdx : rowIdx, boardFlipped ? 7 - colIdx : colIdx)">
                                <span v-if="!(movingPiece && movingFromSquare === displaySquareName(rowIdx, colIdx))" class="piece"
                                    :class="piece ? `piece-${pieceTone(piece)}` : ''">
                                    {{ piece ? activePieceMap[piece] : '' }}
                                </span>
                                <span v-if="!piece && legalTargets.includes(displaySquareName(rowIdx, colIdx))"
                                    class="target-dot"></span>
                                <span v-if="piece && legalTargets.includes(displaySquareName(rowIdx, colIdx))"
                                    class="capture-ring"></span>
                                <small class="coord">{{ displaySquareName(rowIdx, colIdx) }}</small>
                            </button>
                        </div>

                        <div v-if="movingPiece" class="moving-piece" :class="`piece-${movingPiece.tone}`"
                            :style="{ transform: `translate(${movingPiece.col * 100}%, ${movingPiece.row * 100}%)` }">
                            {{ movingPiece.glyph }}
                        </div>
                    </div>
                </div>

                <div class="captured-tray captured-bottom">
                    <strong>White Pieces Captured</strong>
                    <div class="captured-pieces">
                        <span v-for="(piece, idx) in capturedByBlack" :key="`cb-${idx}`" class="piece piece-white">
                            {{ activePieceMap[piece] }}
                        </span>
                        <span v-if="!capturedByBlack.length" class="captured-empty">-</span>
                    </div>
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
                            <span class="piece" :class="`piece-${humanColor}`">{{ activePieceMap[humanColor === 'white' ?
                                piece.toUpperCase() : piece] }}</span>
                            <span>{{ { q: 'Queen', r: 'Rook', b: 'Bishop', n: 'Knight' }[piece] }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- In-game panel: current move list only -->
            <div class="panel" v-if="game && !selectedHistoryGame">
                <h2>Move List</h2>
                <ol class="move-list" v-if="moves.length">
                    <li v-for="(move, idx) in moves" :key="idx">
                        <span>#{{ move.ply }}</span>
                        <span>{{ move.player_color }}</span>
                        <strong>{{ move.san ?? move.uci }}</strong>
                    </li>
                </ol>
                <p v-else>No moves yet.</p>
            </div>

            <!-- Review mode: full-width board + move list + nav -->
            <div class="review-layout" v-if="selectedHistoryGame">
                    <!-- Left: review board -->
                    <div class="review-board-wrap" :class="`piece-style-${pieceStyle}`">
                        <div class="board" :class="`theme-${boardTheme}`">
                            <div v-for="(row, rowIdx) in (reviewFlipped ? [...reviewBoard].reverse().map(r => [...r].reverse()) : reviewBoard)" :key="rowIdx" class="board-row">
                                <div v-for="(piece, colIdx) in row" :key="`${rowIdx}-${colIdx}`" class="square" :class="{
                                    light: (rowIdx + colIdx) % 2 === 0,
                                    dark: (rowIdx + colIdx) % 2 !== 0,
                                    'review-last-from': reviewIndex >= 0 && selectedHistoryGame.moves[reviewIndex]?.uci.slice(0,2) === reviewSquareName(rowIdx, colIdx),
                                    'review-last-to':   reviewIndex >= 0 && selectedHistoryGame.moves[reviewIndex]?.uci.slice(2,4) === reviewSquareName(rowIdx, colIdx),
                                }">
                                    <span class="piece" :class="piece ? `piece-${piece === piece.toUpperCase() ? 'white' : 'black'}` : ''">
                                        {{ piece ? activePieceMap[piece] : '' }}
                                    </span>
                                    <small class="coord">{{ reviewSquareName(rowIdx, colIdx) }}</small>
                                </div>
                            </div>
                        </div>

                        <!-- Nav controls -->
                        <div class="review-nav">
                            <button @click="reviewIndex = -1" title="Start" class="nav-btn">⏮</button>
                            <button @click="reviewIndex = Math.max(-1, reviewIndex - 1)" title="Prev" class="nav-btn">◀</button>
                            <span class="nav-label">
                                {{ reviewIndex < 0 ? 'Start' : `Move ${reviewIndex + 1} / ${selectedHistoryGame.moves.length}` }}
                            </span>
                            <button @click="reviewIndex = Math.min(selectedHistoryGame.moves.length - 1, reviewIndex + 1)" title="Next" class="nav-btn">▶</button>
                            <button @click="reviewIndex = selectedHistoryGame.moves.length - 1" title="End" class="nav-btn">⏭</button>
                        </div>
                    </div>

                    <!-- Right: match info + move pair list -->
                    <div class="review-panel">
                        <div class="panel-header">
                            <button class="btn-back" @click="selectedHistoryGame = null; reviewIndex = -1">← Back</button>
                            <h2>Match #{{ selectedHistoryGame.id }}</h2>
                        </div>
                        <p class="match-result-label">Result: <strong>{{ selectedHistoryGame.result ?? selectedHistoryGame.status }}</strong></p>

                        <ol class="review-move-list">
                            <template v-for="n in Math.ceil(selectedHistoryGame.moves.length / 2)" :key="n">
                                <li class="review-move-row">
                                    <span class="move-num">{{ n }}.</span>
                                    <button
                                        v-if="selectedHistoryGame.moves[(n-1)*2]"
                                        class="move-token"
                                        :class="{ 'move-active': reviewIndex === (n-1)*2 }"
                                        @click="reviewIndex = (n-1)*2">
                                        {{ selectedHistoryGame.moves[(n-1)*2].san ?? selectedHistoryGame.moves[(n-1)*2].uci }}
                                    </button>
                                    <button
                                        v-if="selectedHistoryGame.moves[(n-1)*2+1]"
                                        class="move-token move-token-black"
                                        :class="{ 'move-active': reviewIndex === (n-1)*2+1 }"
                                        @click="reviewIndex = (n-1)*2+1">
                                        {{ selectedHistoryGame.moves[(n-1)*2+1].san ?? selectedHistoryGame.moves[(n-1)*2+1].uci }}
                                    </button>
                                </li>
                            </template>
                        </ol>
                    </div>
            </div>

            <!-- Home panel: stats + leaderboard + match history -->
            <div class="panel" v-if="!game && !selectedHistoryGame">
                <div class="panel-section" v-if="playerSummary">
                    <h2>{{ humanName }} Stats</h2>
                    <p>Games: {{ playerSummary.total_games }} | W: {{ playerSummary.wins }} | L: {{ playerSummary.losses }} | D: {{ playerSummary.draws }}</p>
                </div>

                <div class="panel-section" v-if="matchHistory.length">
                    <h3>Recent Matches</h3>
                    <ul class="history-list">
                        <li v-for="hg in matchHistory" :key="hg.id" class="history-row" @click="openReview(hg)">
                            <span class="history-id">#{{ hg.id }}</span>
                            <span class="history-status" :class="{
                                'res-win': hg.result === '1-0' || hg.result === '0-1',
                                'res-draw': hg.result === '½-½',
                                'res-ongoing': !hg.result
                            }">{{ hg.result ?? hg.status }}</span>
                            <span class="history-moves">{{ hg.moves.length }} moves</span>
                            <span class="history-arrow">›</span>
                        </li>
                    </ul>
                </div>

                <div class="panel-section">
                    <h3>Leaderboard</h3>
                    <ul class="compact-list" v-if="leaderboard.length">
                        <li v-for="entry in leaderboard" :key="entry.player_id">
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

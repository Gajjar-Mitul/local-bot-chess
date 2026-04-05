# Chess (Human vs Bot)

This repo contains:
- `backend` Laravel 13 API (chess rules + bot move generation)
- `frontend` Vue 3 + Vite app (board UI)

## What is implemented

- New game creation with selectable human color
- Move submission and legal move validation on backend
- Core rules: check, checkmate, stalemate, castling, en passant, promotion
- Simple bot move strategy (material-aware one-ply search)
- Move history and game reset endpoints
- Frontend board + click-to-move interaction

## Backend setup

1. Go to backend:
```bash
cd backend
```

2. Configure database in `.env` (MySQL example):
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chess
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

3. Run migrations:
```bash
php artisan migrate
```

4. Start API server:
```bash
php artisan serve
```

API base URL: `http://127.0.0.1:8000/api`

## Frontend setup

1. Go to frontend:
```bash
cd frontend
```

2. Optional API override:
```bash
cp .env.example .env
```

3. Run dev server:
```bash
npm run dev
```

Frontend URL: `http://localhost:5173`

## API endpoints

- `POST /api/games`
- `GET /api/games/{game}`
- `GET /api/games/{game}/moves`
- `POST /api/games/{game}/moves`
- `POST /api/games/{game}/reset`

## Current environment note

On this machine, `pdo_sqlite` is not enabled, so Laravel's default SQLite setup fails migrations. Use MySQL credentials in `.env` and then run `php artisan migrate`.

## Phase 2 Highlights (In Progress)

Current Phase 2 includes:
- Captured pieces display around the board
- Material evaluation bar (clamped score from -10 to +10)

Phase 2 high-priority TODO:
- Strategy-aware evaluation bar (not only material points)
- Add positional scoring factors:
	- center control
	- mobility
	- king safety
	- pawn structure
- Upgrade bot move selection so it responds with the best possible move for the current position (multi-factor evaluation and deeper lookahead)

# local-bot-chess

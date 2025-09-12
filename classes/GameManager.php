<?php
// classes/GameManager.php

require_once __DIR__ . '/BaseChess.php';
require_once __DIR__ . '/HexChess.php';

class GameManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /** Safe to keep: no-op once columns exist */
    private function ensureTurnTimerColumns(): void {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM games LIKE 'turn_timer'");
            if ($stmt && $stmt->rowCount() === 0) {
                $this->pdo->exec("
                    ALTER TABLE games
                    ADD COLUMN turn_timer INT DEFAULT 30 AFTER game_mode,
                    ADD COLUMN skip_action VARCHAR(20) DEFAULT 'skip_turn' AFTER turn_timer
                ");
                error_log("[GameManager] Added turn_timer & skip_action columns");
            }
        } catch (Throwable $e) {
            error_log("[GameManager] Schema check failed: " . $e->getMessage());
        }
    }

    /** Create or get the AI user id */
    private function getOrCreateAIUser() {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = 'AI_PLAYER'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) {
            return (int)$row['id'];
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, password_hash, email, wins, losses, created_at)
            VALUES ('AI_PLAYER', 'no_password', 'ai@system.local', 0, 0, NOW())
        ");
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /** Generate a unique varchar game id */
    private function generateGameId(): string {
        return 'game_' . uniqid('', true) . '_' . time();
    }

    /**
     * Create a new game
     * Called from index.php with fog/shogi flags and ai vs modes.
     */
    public function createGame(
        $creatorId,
        $roomName,
        $playerCount,
        $boardSize,
        $gameMode = 'multiplayer',
        $aiDifficulty = 'basic',
        $gameType = 'online',
        $turnTimer = 30,
        $skipAction = 'skip_turn',
        $startingPositions = [],
        $fogOfWar = 0,
        $shogiDrops = 0
    ) {
        $this->ensureTurnTimerColumns();

        $gameId = $this->generateGameId();

        // Build game object (id set so it persists in state)
        $game = new HexChess($gameId, (int)$playerCount, (int)$boardSize);
        if ($fogOfWar)   { $game->enableFogOfWar(); }
        if ($shogiDrops) { $game->enableShogiDrops(); }

        $serialized = json_encode($game->getSerializableData());

        // Insert base game row as waiting
        $stmt = $this->pdo->prepare("
            INSERT INTO games
              (game_id, creator_id, game_name, status, player_count, board_size, game_mode, ai_difficulty,
               game_type, turn_timer, skip_action, fog_of_war, shogi_drops, game_state, created_at)
            VALUES
              (?,       ?,          ?,         'waiting', ?,           ?,         ?,          ?,
               ?,        ?,          ?,           ?,           ?,          ?,          NOW())
        ");
        $stmt->execute([
            $gameId,
            $creatorId,
            $roomName,
            (int)$playerCount,
            (int)$boardSize,
            $gameMode,
            $aiDifficulty,
            $gameType,
            (int)$turnTimer,
            $skipAction,
            (int)$fogOfWar,
            (int)$shogiDrops,
            $serialized
        ]);

        // Creator is always slot 0
        $stmt = $this->pdo->prepare("INSERT INTO game_players (game_id, user_id, player_slot) VALUES (?, ?, 0)");
        $stmt->execute([$gameId, $creatorId]);

        // If AI game, auto-add AI in slot 1 and start immediately
        if ($gameMode === 'ai') {
            $aiUserId = $this->getOrCreateAIUser();

            // Avoid double insert if schema already had it (defensive)
            $stmt = $this->pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND user_id = ?");
            $stmt->execute([$gameId, $aiUserId]);
            if (!$stmt->fetchColumn()) {
                $stmt = $this->pdo->prepare("INSERT INTO game_players (game_id, user_id, player_slot) VALUES (?, ?, 1)");
                $stmt->execute([$gameId, $aiUserId]);
            }

            // Activate the game now that both seats are filled
            $stmt = $this->pdo->prepare("UPDATE games SET status = 'active', started_at = NOW() WHERE game_id = ?");
            $stmt->execute([$gameId]);
        } else {
            // Non-AI: auto-activate if all seats filled at creation (rare, e.g., hotseat pre-fill)
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_players WHERE game_id = ?");
            $stmt->execute([$gameId]);
            $current = (int)$stmt->fetchColumn();
            if ($current >= (int)$playerCount) {
                $stmt = $this->pdo->prepare("UPDATE games SET status = 'active', started_at = NOW() WHERE game_id = ?");
                $stmt->execute([$gameId]);
            }
        }

        return $gameId;
    }

    /** Load a game (JSON state path) */
    public function getGame($gameId) {
        $stmt = $this->pdo->prepare("SELECT * FROM games WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $gameData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$gameData) return null;

        $decoded = [];
        if (!empty($gameData['game_state'])) {
            $decoded = json_decode($gameData['game_state'], true) ?: [];
        }

        $playerCount = (int)($gameData['player_count'] ?? 2);
        $boardSize   = (int)($gameData['board_size'] ?? 8);

        $game = new HexChess($gameData['game_id'], $playerCount, $boardSize);
        if ($decoded) {
            $game->restoreFromData($decoded);
        }
        if (!empty($gameData['fog_of_war']))   { $game->enableFogOfWar(); }
        if (!empty($gameData['shogi_drops'])) { $game->enableShogiDrops(); }

        $stmt = $this->pdo->prepare("
            SELECT gp.player_slot, gp.user_id, u.username
            FROM game_players gp
            JOIN users u ON gp.user_id = u.id
            WHERE gp.game_id = ?
            ORDER BY gp.player_slot
        ");
        $stmt->execute([$gameId]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($players as $p) {
            $game->setPlayerUser((int)$p['player_slot'], (int)$p['user_id']);
        }

        return [
            'game'    => $game,
            'data'    => $gameData,
            'players' => $players
        ];
    }

    /** Save updated state */
    public function updateGameState($gameId, BaseChess $game) {
        $state  = json_encode($game->getSerializableData());
        $status = $game->isGameOver() ? 'finished' : 'active';
        $stmt = $this->pdo->prepare("
            UPDATE games
               SET game_state = ?, status = ?, updated_at = NOW()
             WHERE game_id = ?
        ");
        $stmt->execute([$state, $status, $gameId]);
        return true;
    }

    /** Join a waiting game */
    public function joinGame($gameId, $userId) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND user_id = ?");
        $stmt->execute([$gameId, $userId]);
        if ($stmt->fetchColumn()) return "Already joined";

        $stmt = $this->pdo->prepare("SELECT player_count, status FROM games WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'waiting') return false;

        $maxPlayers = (int)$row['player_count'];

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM game_players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $current = (int)$stmt->fetchColumn();
        if ($current >= $maxPlayers) return false;

        $stmt = $this->pdo->prepare("INSERT INTO game_players (game_id, user_id, player_slot) VALUES (?, ?, ?)");
        $stmt->execute([$gameId, $userId, $current]);

        if ($current + 1 >= $maxPlayers) {
            $stmt = $this->pdo->prepare("UPDATE games SET status = 'active', started_at = NOW() WHERE game_id = ?");
            $stmt->execute([$gameId]);
        }
        return true;
    }

    /** Mark finished */
    public function finishGame($gameId, $winnerId = null) {
        $stmt = $this->pdo->prepare("
            UPDATE games
               SET status = 'finished', finished_at = NOW(), winner_id = ?
             WHERE game_id = ?
        ");
        $stmt->execute([$winnerId, $gameId]);
    }

    /** Legacy shim */
    public function saveGame($gameId, BaseChess $game) {
        return $this->updateGameState($gameId, $game);
    }
}

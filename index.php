<?php
// Clear PHP opcache to force reload of changed files
if (function_exists('opcache_reset')) {
    opcache_reset();
    error_log("OpCache cleared");
}
clearstatcache();

// ------------------------------------------------------------
// index.php (full file with fog of war support)
// ------------------------------------------------------------

// Production-friendly error settings
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Guard against unserialize gadget
if (!class_exists('PoohGame')) {
    class PoohGame { public function __wakeup() {} }
}

// Includes
require_once 'config/database.php';
require_once 'classes/Piece.php';
require_once 'classes/User.php';
require_once 'classes/GameManager.php';
require_once 'classes/BaseChess.php';
require_once 'classes/HexChess.php';
require_once 'classes/HexChessAI.php';
require_once 'utils/render.php';

// Core
$user        = new User($pdo);
$gameManager = new GameManager($pdo);

$page = $_GET['page'] ?? 'lobby';

// Initialize game variables for header display
$gameData = null;
$game = null;
$players = null;

// Load game data if we're on a game page
if ($page === 'game' && !empty($_GET['id'])) {
    $gameId = $_GET['id'];
    $gameInfo = $gameManager->getGame($gameId);
    if ($gameInfo) {
        $game = $gameInfo['game'];
        $players = $gameInfo['players'];
        $gameData = $gameInfo['data'];
    }
}

// ONE-TIME DATABASE UPDATE FUNCTION (run this once, then comment out)
function updateDatabaseForFogOfWar($pdo) {
    try {
        // Check if fog_of_war column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'fog_of_war'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE games ADD COLUMN fog_of_war TINYINT(1) DEFAULT 0");
            error_log("Added fog_of_war column to games table");
        }
        
        // Check if shogi_drops column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'shogi_drops'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE games ADD COLUMN shogi_drops TINYINT(1) DEFAULT 0");
            error_log("Added shogi_drops column to games table");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Database update error: " . $e->getMessage());
        return false;
    }
}

// UNCOMMENT THE NEXT LINE TO RUN DATABASE UPDATE (run once, then comment out)
// updateDatabaseForFogOfWar($pdo);

// ------------------------------
// Lightweight JSON API (GET)
// ------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    switch ($_GET['api']) {

        case 'debug-hexchess':
    try {
        $game = new HexChess(null, 2, 8);
        echo json_encode([
            'ok' => true,
            'playerCount' => $game->getPlayerCount(),
            'boardSize'   => $game->getBoardSize(),
            'currentPlayerSlot' => $game->getCurrentPlayerSlot(),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
            'where' => 'construct HexChess',
        ]);
    }
    exit;

case 'board':
    try {
        // For now, build a fresh in-memory game (2 players, size 8)
        $game = new HexChess(null, 2, 8);
        $board = $game->getBoard();

        // Flatten to a compact list of occupied hexes so JSON is small and predictable
        $cells = [];
        foreach ($board as $q => $col) {
            foreach ($col as $r => $piece) {
                if ($piece) {
                    // Assuming Piece exposes ->type and ->player (public or via __get)
                    $cells[] = [
                        'q'      => (int)$q,
                        'r'      => (int)$r,
                        'type'   => $piece->type,
                        'player' => $piece->player,
                    ];
                }
            }
        }

        echo json_encode([
            'ok'            => true,
            'playerCount'   => $game->getPlayerCount(),
            'boardSize'     => $game->getBoardSize(),
            'currentPlayer' => $game->getCurrentPlayerSlot(),
            'cells'         => $cells,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
            'where' => 'board api',
        ]);
    }
    exit;

        case 'games':
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        g.game_id,
                        g.game_name,
                        g.status,
                        g.player_count,
                        g.game_mode,
                        g.ai_difficulty,
                        g.fog_of_war,
                        g.shogi_drops,
                        COUNT(gp.user_id) as current_players,
                        creator.username as creator,
                        GROUP_CONCAT(u.username SEPARATOR ', ') as player_names
                    FROM games g
                    LEFT JOIN game_players gp ON g.game_id = gp.game_id
                    LEFT JOIN users creator ON g.creator_id = creator.id
                    LEFT JOIN users u ON gp.user_id = u.id
                    WHERE g.status = 'waiting'
                      AND (g.game_type = 'online' OR g.game_type = 'hotseat' OR g.game_type = 'chess' OR g.game_type = 'hexchess' OR g.game_type IS NULL)
AND (g.game_mode != 'ai' OR g.game_mode IS NULL)
                    GROUP BY g.game_id
                    ORDER BY g.created_at DESC
                    LIMIT 20
                ");
                $stmt->execute();
                $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $result = array_map(function ($game) {
                    return [
                        'game_id'        => $game['game_id'],
                        'game_name'      => $game['game_name'] ?: 'Unnamed Game',
                        'status'         => $game['status'],
                        'player_count'   => (int)$game['player_count'],
                        'current_players'=> (int)$game['current_players'],
                        'creator'        => $game['creator'],
                        'player_names'   => $game['player_names'],
                        'game_mode'      => $game['game_mode'],
                        'ai_difficulty'  => $game['ai_difficulty'],
                        'fog_of_war'     => (bool)$game['fog_of_war'],
                        'shogi_drops'    => (bool)$game['shogi_drops']
                    ];
                }, $games);

                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'my-games':
            if (!isset($_SESSION['user_id'])) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        g.game_id,
                        g.game_name,
                        g.status,
                        g.player_count,
                        g.game_mode,
                        g.ai_difficulty,
                        g.fog_of_war,
                        g.shogi_drops,
                        gp.player_slot,
                        w.username as winner_name,
                        GROUP_CONCAT(
                            CASE 
                                WHEN u.id = ? THEN CONCAT(u.username, ' (You)')
                                WHEN g.game_mode = 'ai' AND u.id != ? THEN 
                                    CASE g.ai_difficulty
                                        WHEN 'easy' THEN 'Easy AI'
                                        WHEN 'basic' THEN 'Basic AI'
                                        WHEN 'player' THEN 'Player AI'
                                        WHEN 'hard' THEN 'Hard AI'
                                        ELSE 'AI'
                                    END
                                ELSE u.username
                            END 
                            ORDER BY gp2.player_slot 
                            SEPARATOR ' vs '
                        ) as player_names
                    FROM games g
                    JOIN game_players gp  ON g.game_id = gp.game_id
                    JOIN game_players gp2 ON g.game_id = gp2.game_id
                    JOIN users u          ON gp2.user_id = u.id
                    LEFT JOIN users w     ON g.winner_id = w.id
                    WHERE gp.user_id = ?
                      AND g.status IN ('waiting','active','finished')
                      AND (g.game_type = 'chess' OR g.game_type = 'hexchess' OR g.game_type IS NULL)
                    GROUP BY g.game_id, gp.player_slot
                    ORDER BY g.created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
                $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $result = array_map(function ($game) {
                    return [
                        'game_id'      => $game['game_id'],
                        'game_name'    => $game['game_name'] ?: 'Unnamed Game',
                        'status'       => $game['status'],
                        'player_count' => (int)$game['player_count'],
                        'current_players' => (int)$game['player_count'],
                        'is_your_turn' => false,
                        'winner_name'  => $game['winner_name'],
                        'player_names' => $game['player_names'],
                        'game_mode'    => $game['game_mode'] ?? 'multiplayer',
                        'fog_of_war'   => (bool)$game['fog_of_war'],
                        'shogi_drops'  => (bool)$game['shogi_drops']
                    ];
                }, $games);

                echo json_encode($result);
                exit;
            } catch (Exception $e) {
                echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
                exit;
            }

        default:
            echo json_encode(['error' => 'Unknown API endpoint']);
            exit;
    }
}

// --------------------------------------
// POST Actions (AJAX from the frontend)
// --------------------------------------
//if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {

    header('Content-Type: application/json');
    // DEBUG: Log what action we received

    try {
        switch ($_POST['action']) {

            // --- CREATE GAME (with fog of war support) ---
            case 'create_game':
                error_log("=== CREATE GAME DEBUG START ===");
                error_log("POST data: " . print_r($_POST, true));

                if (!isset($_SESSION['user_id'])) {
                    error_log("Error: User not logged in");
                    echo json_encode(['success' => false, 'error' => 'Not logged in']);
                    exit;
                }

                $roomName    = trim($_POST['room_name'] ?? $_POST['game_name'] ?? '');
                $gameType    = trim($_POST['game_type'] ?? 'online');
                $playerCount = (int)($_POST['player_count'] ?? 2);
                $boardSize   = (int)($_POST['board_size'] ?? 7);
                $turnTimer   = (int)($_POST['turn_timer'] ?? 30);
                $skipAction  = trim($_POST['skip_action'] ?? 'skip_turn');
                
                // NEW: Extract fog of war and shogi drops options
                $fogOfWar    = isset($_POST['fog_of_war']) && $_POST['fog_of_war'] === '1';
                $shogiDrops  = isset($_POST['shogi_drops']) && $_POST['shogi_drops'] === '1';

                error_log("Parsed data - Room: $roomName, Type: $gameType, Players: $playerCount, Board: $boardSize, FogOfWar: " . ($fogOfWar ? 'true' : 'false') . ", ShogiDrops: " . ($shogiDrops ? 'true' : 'false'));

                $boardLimits = [
    4 => ['min' => 2, 'max' => 3], // Mini
    5 => ['min' => 2, 'max' => 3], // Small
    6 => ['min' => 2, 'max' => 3], // Medium
    7 => ['min' => 2, 'max' => 6], // Large
    8 => ['min' => 2, 'max' => 6]  // Jumbo
];

                if (!isset($boardLimits[$boardSize])) {
                    $msg = "Invalid board size: $boardSize";
                    error_log("Error: $msg");
                    echo json_encode(['success' => false, 'error' => $msg]);
                    exit;
                }
                $limits = $boardLimits[$boardSize];
                if ($playerCount > $limits['max']) {
                    $msg = "Board size $boardSize supports maximum {$limits['max']} players. Requested: $playerCount";
                    error_log("Error: $msg");
                    echo json_encode(['success' => false, 'error' => $msg]);
                    exit;
                }
                if ($playerCount < $limits['min']) {
                    $msg = "Minimum {$limits['min']} players required. Requested: $playerCount";
                    error_log("Error: $msg");
                    echo json_encode(['success' => false, 'error' => $msg]);
                    exit;
                }

                // Map new game_type -> legacy game_mode used elsewhere
                $gameMode = $_POST['game_mode'] ?? ([
                    'vs_ai'  => 'ai',
                    'hotseat'=> 'hotseat',
                    'online' => 'multiplayer'
                ][$gameType] ?? 'multiplayer');

                $aiDifficulty = trim($_POST['ai_difficulty'] ?? 'basic');

                error_log("Mapped game mode: $gameMode, AI difficulty: $aiDifficulty");

                if (empty($roomName)) {
                    error_log("Error: Room name is empty");
                    echo json_encode(['success' => false, 'error' => 'Room name is required']);
                    exit;
                }

                // For AI games, force 2 players
                if ($gameMode === 'ai') {
                    $playerCount = 2;
                    $gameType    = 'vs_ai';
                    error_log("AI game detected, forcing 2 players");
                }

                try {
                    $startingPositions = calculateStartingPositions($playerCount);
                    error_log("Starting positions: " . json_encode($startingPositions));

                    if (!method_exists($gameManager, 'createGame')) {
                        error_log("Error: GameManager::createGame method does not exist");
                        echo json_encode(['success' => false, 'error' => 'GameManager method missing']);
                        exit;
                    }

                    // NEW: Create enhanced game data with fog of war options
                    $enhancedGameData = [
                        'room_name'    => $roomName,
                        'player_count' => $playerCount,
                        'board_size'   => $boardSize,
                        'game_mode'    => $gameMode,
                        'ai_difficulty'=> $aiDifficulty,
                        'game_type'    => $gameType,
                        'turn_timer'   => $turnTimer,
                        'skip_action'  => $skipAction,
                        'fog_of_war'   => $fogOfWar ? 1 : 0,
                        'shogi_drops'  => $shogiDrops ? 1 : 0,
                        'starting_positions' => $startingPositions
                    ];

                    $reflection = new ReflectionMethod($gameManager, 'createGame');
                    $paramCount = $reflection->getNumberOfParameters();
                    error_log("createGame method expects $paramCount parameters");

                    // Try enhanced createGame method first
                    if ($paramCount >= 9) {
                        $gameId = $gameManager->createGame(
                            $_SESSION['user_id'],
                            $roomName,
                            $playerCount,
                            $boardSize,
                            $gameMode,
                            $aiDifficulty,
                            $gameType,
                            $turnTimer,
                            $skipAction,
                            $startingPositions,
                            $fogOfWar ? 1 : 0,  // NEW: fog of war parameter
                            $shogiDrops ? 1 : 0  // NEW: shogi drops parameter
                        );
                    } else {
                        // Fallback to legacy method, then update with new options
                        error_log("Using legacy createGame method, will update with new options");
                        $gameId = $gameManager->createGame(
                            $_SESSION['user_id'],
                            $roomName,
                            $playerCount,
                            $boardSize,
                            $gameMode,
                            $aiDifficulty
                        );
                        
                        // Update with fog of war options if game was created
                        if ($gameId) {
                            $stmt = $pdo->prepare("
                                UPDATE games 
                                SET fog_of_war = ?, shogi_drops = ? 
                                WHERE game_id = ?
                            ");
                            $stmt->execute([$fogOfWar ? 1 : 0, $shogiDrops ? 1 : 0, $gameId]);
                            error_log("Updated game $gameId with fog_of_war=" . ($fogOfWar ? 1 : 0) . ", shogi_drops=" . ($shogiDrops ? 1 : 0));
                        }
                    }

                    error_log("createGame returned: " . ($gameId ? $gameId : 'false'));

                    if ($gameId) {
                        error_log("SUCCESS: Game created with ID: $gameId");
                        echo json_encode([
                            'success'      => true,
                            'game_id'      => $gameId,
                            'game_type'    => $gameType,
                            'player_count' => $playerCount,
                            'fog_of_war'   => $fogOfWar,
                            'shogi_drops'  => $shogiDrops
                        ]);
                    } else {
                        error_log("ERROR: createGame returned false");
                        echo json_encode(['success' => false, 'error' => 'Failed to create game in database']);
                    }
                } catch (Exception $e) {
                    $errorMsg = "Enhanced game creation error: " . $e->getMessage();
                    error_log($errorMsg);
                    error_log("Stack trace: " . $e->getTraceAsString());
                    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
                }

                error_log("=== CREATE GAME DEBUG END ===");
                exit;

            case 'join_game':
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Not logged in']);
                    exit;
                }
                $gameId = trim($_POST['game_id'] ?? '');
                if ($gameId === '') {
                    echo json_encode(['success' => false, 'error' => 'Game ID is required']);
                    exit;
                }
                $result = $gameManager->joinGame($gameId, $_SESSION['user_id']);
                echo json_encode($result === true ? ['success' => true] : ['success' => false, 'error' => $result ?: 'Failed to join game']);
                exit;

            case 'resign':
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Not logged in']);
                    exit;
                }
                $gameId = trim($_POST['game_id'] ?? '');
                if ($gameId === '') {
                    echo json_encode(['success' => false, 'error' => 'Game ID required']);
                    exit;
                }
                $gameInfo = $gameManager->getGame($gameId);
                if (!$gameInfo) {
                    echo json_encode(['success' => false, 'error' => 'Game not found']);
                    exit;
                }
                $game     = $gameInfo['game'];
                $gameData = $gameInfo['data'];

                if ($gameData['status'] !== 'active') {
                    echo json_encode(['success' => false, 'error' => 'Game is not active']);
                    exit;
                }

                $userPlayerSlot = null;
                foreach ($gameInfo['players'] as $player) {
                    if ($player['user_id'] == $_SESSION['user_id']) {
                        $userPlayerSlot = $player['player_slot'];
                        break;
                    }
                }
                if ($userPlayerSlot === null) {
                    echo json_encode(['success' => false, 'error' => 'You are not in this game']);
                    exit;
                }

                $result = $game->resignPlayer($userPlayerSlot);

                if ($result === true) {
                    $gameManager->updateGameState($gameId, $game);
                    $gameState = $game->getGameState();
                    if ($gameState['gameStatus']['gameOver']) {
                        $winnerId = $gameState['gameStatus']['winner'];
                        $gameManager->finishGame($gameId, $winnerId);

                        $userObj = new User($pdo);
                        $userObj->updateStats($_SESSION['user_id'], false);
                        if ($winnerId) {
                            $userObj->updateStats($winnerId, true);
                        }
                    }
                    echo json_encode(['success' => true, 'message' => 'You have resigned from the game']);
                } else {
                    echo json_encode(['success' => false, 'error' => $result ?: 'Failed to resign']);
                }
                exit;

            case 'login':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                if ($username === '' || $password === '') {
                    echo json_encode(['success' => false, 'error' => 'Username and password required']);
                    exit;
                }
                $userId = $user->login($username, $password);
                if ($userId) {
                    $_SESSION['user_id'] = $userId;
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
                }
                exit;

            case 'register':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $email    = trim($_POST['email'] ?? '');
                if ($username === '' || $password === '') {
                    echo json_encode(['success' => false, 'error' => 'Username and password required']);
                    exit;
                }
                if (strlen($username) < 3) {
                    echo json_encode(['success' => false, 'error' => 'Username must be at least 3 characters']);
                    exit;
                }
                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
                    exit;
                }
                $userId = $user->register($username, $password, $email);
                if ($userId) {
                    $_SESSION['user_id'] = $userId;
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Registration failed - username may be taken']);
                }
                exit;

            case 'logout':
                session_destroy();
                echo json_encode(['success' => true]);
                exit;

            // NEW: Get board state with fog of war filtering
            case 'get_board_state':
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Not logged in']);
                    exit;
                }
                $gameId = trim($_POST['game_id'] ?? '');
                if ($gameId === '') {
                    echo json_encode(['success' => false, 'error' => 'Game ID required']);
                    exit;
                }
                $gameInfo = $gameManager->getGame($gameId);
                if (!$gameInfo) {
                    echo json_encode(['success' => false, 'error' => 'Game not found']);
                    exit;
                }

                $game = $gameInfo['game'];
                
                // Determine viewer's player slot
                $viewerPlayerSlot = null;
                foreach ($gameInfo['players'] as $p) {
                    if ($p['user_id'] == $_SESSION['user_id']) {
                        $viewerPlayerSlot = $p['player_slot'];
                        break;
                    }
                }
                
                $boardState = getBoardStateJson($game, $viewerPlayerSlot);
                $gameState = $game->getGameState();
                
                echo json_encode([
                    'success' => true,
                    'board' => $boardState,
                    'gameState' => $gameState,
                    'fogOfWar' => $game->isFogOfWarEnabled()
                ]);
                exit;

case 'move':
    $debug = [
        'timestamp' => date('H:i:s'),
        'action' => 'move',
        'step' => 'start',
        'postData' => $_POST
    ];
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false, 
            'error' => 'Not logged in',
            'debug' => array_merge($debug, ['step' => 'auth_failed'])
        ]);
        exit;
    }
    
    $debug['userId'] = $_SESSION['user_id'];
    $debug['step'] = 'auth_passed';
    
    $gameId = trim($_POST['game_id'] ?? '');
    $fromQ = (int)($_POST['fromQ'] ?? 0);
    $fromR = (int)($_POST['fromR'] ?? 0);
    $toQ = (int)($_POST['toQ'] ?? 0);
    $toR = (int)($_POST['toR'] ?? 0);
    
    $debug = array_merge($debug, [
        'gameId' => $gameId,
        'move' => ['from' => [$fromQ, $fromR], 'to' => [$toQ, $toR]]
    ]);
    
    if ($gameId === '') {
        echo json_encode([
            'success' => false, 
            'error' => 'Game ID required',
            'debug' => array_merge($debug, ['step' => 'no_gameId'])
        ]);
        exit;
    }
    
    try {
        $debug['step'] = 'loading_game';
        
        $gameInfo = $gameManager->getGame($gameId);
        if (!$gameInfo) {
            echo json_encode([
                'success' => false, 
                'error' => 'Game not found',
                'debug' => array_merge($debug, ['step' => 'game_not_found'])
            ]);
            exit;
        }
        
        $debug['step'] = 'game_loaded';
        $debug['gameStatus'] = $gameInfo['data']['status'] ?? 'unknown';
        
        $game = $gameInfo['game'];
        $gameData = $gameInfo['data'];
        $players = $gameInfo['players'];
        
        $debug['currentPlayer'] = $game->getCurrentPlayerSlot();
        $debug['gameMode'] = $gameData['game_mode'] ?? 'unknown';
        
        // Get piece info
        $piece = $game->getPiece($fromQ, $fromR);
        $debug['piece'] = $piece ? ['type' => $piece->type, 'player' => $piece->player] : null;
        
        // Check if it's hotseat mode
        $isHotseat = (isset($gameData['game_mode']) && $gameData['game_mode'] === 'hotseat');
        $debug['isHotseat'] = $isHotseat;
        
        if (!$isHotseat) {
            $debug['step'] = 'checking_turn';
            
            $currentPlayerSlot = $game->getCurrentPlayerSlot();
            $userPlayerSlot = null;
            
            foreach ($players as $player) {
                if ($player['user_id'] == $_SESSION['user_id']) {
                    $userPlayerSlot = $player['player_slot'];
                    break;
                }
            }
            
            $debug['userPlayerSlot'] = $userPlayerSlot;
            $debug['currentPlayerSlot'] = $currentPlayerSlot;
            
            if ($userPlayerSlot === null) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'You are not in this game',
                    'debug' => array_merge($debug, ['step' => 'user_not_in_game'])
                ]);
                exit;
            }
            
            if ($currentPlayerSlot !== $userPlayerSlot) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'Not your turn',
                    'debug' => array_merge($debug, ['step' => 'wrong_turn'])
                ]);
                exit;
            }
        }
        
        $debug['step'] = 'attempting_move';
        
        // Try to make the move
        $moveResult = $game->movePiece($fromQ, $fromR, $toQ, $toR);
        $debug['moveResult'] = $moveResult;
        
        if ($moveResult) {
            $debug['step'] = 'saving_game';
            
            // Save the updated game state
            if (method_exists($gameManager, 'updateGameState')) {
                $gameManager->updateGameState($gameId, $game);
                $debug['saveMethod'] = 'updateGameState';
            } elseif (method_exists($gameManager, 'saveGame')) {
                $gameManager->saveGame($gameId, $game);
                $debug['saveMethod'] = 'saveGame';
            } else {
                $debug['saveMethod'] = 'no_method_available';
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Move completed',
                'debug' => array_merge($debug, ['step' => 'success'])
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'error' => 'Invalid move - movePiece returned false',
                'debug' => array_merge($debug, ['step' => 'move_failed'])
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'error' => 'Server error: ' . $e->getMessage(),
            'debug' => array_merge($debug, [
                'step' => 'exception',
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ])
        ]);
    }
    exit;

case 'getValidMoves':
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_level()) {
        ob_clean(); // Clear any output buffers
    }

    // Start with basic error catching
    try {
        error_log("=== getValidMoves DEBUG START ===");
        
        // Check session
        if (!isset($_SESSION['user_id'])) {
            error_log("No user session");
            echo json_encode(['success' => false, 'error' => 'Not logged in', 'debug' => 'no_session']);
            exit;
        }
        error_log("User ID: " . $_SESSION['user_id']);
        
        // Get parameters
        $gameId = trim($_POST['game_id'] ?? '');
        $fromQ = (int)($_POST['fromQ'] ?? 0);
        $fromR = (int)($_POST['fromR'] ?? 0);
        
        error_log("Game ID: $gameId, From: ($fromQ, $fromR)");
        
        if ($gameId === '') {
            error_log("Empty game ID");
            echo json_encode(['success' => false, 'error' => 'Game ID required', 'debug' => 'empty_gameId']);
            exit;
        }
        
        // Try to get game
        error_log("Getting game...");
        $gameInfo = $gameManager->getGame($gameId);
        if (!$gameInfo) {
            error_log("Game not found");
            echo json_encode(['success' => false, 'error' => 'Game not found', 'debug' => 'no_game']);
            exit;
        }
        error_log("Game found");
        
        // Get game object
        $game = $gameInfo['game'];
        $gameData = $gameInfo['data'];
        error_log("Game object type: " . get_class($game));
        
        // Check if method exists
        if (!method_exists($game, 'getValidMoves')) {
            error_log("getValidMoves method does not exist!");
            echo json_encode(['success' => false, 'error' => 'Method not found', 'debug' => 'no_method']);
            exit;
        }
        error_log("getValidMoves method exists");
        
        // Try to call getValidMoves
        error_log("Calling getValidMoves($fromQ, $fromR)...");
        $validMoves = $game->getValidMoves($fromQ, $fromR);
        error_log("getValidMoves returned " . count($validMoves) . " moves");
        
        // Success response
        echo json_encode([
            'success' => true, 
            'validMoves' => $validMoves,
            'debug' => [
                'gameId' => $gameId,
                'fromQ' => $fromQ,
                'fromR' => $fromR,
                'moveCount' => count($validMoves),
                'currentPlayer' => method_exists($game, 'getCurrentPlayerSlot') ? $game->getCurrentPlayerSlot() : 'unknown'
            ]
        ]);
        error_log("=== getValidMoves DEBUG SUCCESS ===");
        
    } catch (Exception $e) {
        error_log("=== getValidMoves ERROR ===");
        error_log("Error: " . $e->getMessage());
        error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
        error_log("Stack: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false, 
            'error' => 'Server error: ' . $e->getMessage(),
            'debug' => [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
    }
    exit;
    
/*    case 'getValidMoves':
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $gameId = trim($_POST['game_id'] ?? '');
    $fromQ  = (int)($_POST['fromQ'] ?? 0);
    $fromR  = (int)($_POST['fromR'] ?? 0);
    if ($gameId === '') {
        echo json_encode(['success' => false, 'error' => 'Game ID required']);
        exit;
    }
    $gameInfo = $gameManager->getGame($gameId);
    if (!$gameInfo) {
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }
    $game = $gameInfo['game'];
    $gameData = $gameInfo['data'];
    $isHotseat = (isset($gameData['game_mode']) && $gameData['game_mode'] === 'hotseat');

    if (!$isHotseat && !$game->canUserMove($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not your turn']);
        exit;
    }

    $validMoves = [];
    $piece = $game->getPiece($fromQ, $fromR);
    
    if ($piece) {
        error_log("=== DEBUGGING MOVES FOR {$piece->type} at ($fromQ, $fromR) ===");
        
        $totalChecked = 0;
        $validCount = 0;
        $invalidReasons = [];
        
        for ($q = -$game->getBoardSize(); $q <= $game->getBoardSize(); $q++) {
            for ($r = max(-$game->getBoardSize(), -$q - $game->getBoardSize()); 
                 $r <= min($game->getBoardSize(), -$q + $game->getBoardSize()); $r++) {
                
                if ($q === $fromQ && $r === $fromR) continue;
                
                $totalChecked++;
                
                // Debug each step of isValidMove
                if (!$game->isValidHex($q, $r)) {
                    $invalidReasons['invalid_hex'] = ($invalidReasons['invalid_hex'] ?? 0) + 1;
                    continue;
                }
                
                $targetPiece = $game->getPiece($q, $r);
                if ($targetPiece && $targetPiece->player === $piece->player) {
                    $invalidReasons['own_piece'] = ($invalidReasons['own_piece'] ?? 0) + 1;
                    continue;
                }
                
                // Test the core movement logic for bishops specifically
                $dq = $q - $fromQ;
                $dr = $r - $fromR;
                
                if ($piece->type === 'bishop') {
                    // Use reflection to access private isDiagonalMove method
                    $reflection = new ReflectionClass($game);
                    $isDiagonalMethod = $reflection->getMethod('isDiagonalMove');
                    $isDiagonalMethod->setAccessible(true);
                    $isDiagonal = $isDiagonalMethod->invoke($game, $dq, $dr);
                    
                    if (!$isDiagonal) {
                        $invalidReasons['not_diagonal'] = ($invalidReasons['not_diagonal'] ?? 0) + 1;
                        continue;
                    }
                    
                    // Use reflection to access private isPathClear method
                    $isPathClearMethod = $reflection->getMethod('isPathClear');
                    $isPathClearMethod->setAccessible(true);
                    $pathClear = $isPathClearMethod->invoke($game, $fromQ, $fromR, $q, $r);
                    
                    if (!$pathClear) {
                        $invalidReasons['path_blocked'] = ($invalidReasons['path_blocked'] ?? 0) + 1;
                        error_log("Path blocked for bishop move to ($q, $r)");
                        continue;
                    }
                    
                    error_log("Bishop CAN move to ($q, $r) - dq=$dq, dr=$dr");
                        $game->debugBishopMove($fromQ, $fromR, $q, $r);

                }
                
                if ($game->isValidMove($fromQ, $fromR, $q, $r)) {
                    $validMoves[] = [
                        'q' => $q,
                        'r' => $r,
                        'type' => $targetPiece ? 'capture' : 'move'
                    ];
                    $validCount++;
                    error_log("VALID MOVE: ($fromQ, $fromR) -> ($q, $r)");
                } else {
                    $invalidReasons['isValidMove_failed'] = ($invalidReasons['isValidMove_failed'] ?? 0) + 1;
                    
                    // For bishops, let's see exactly why isValidMove failed
                    if ($piece->type === 'bishop') {
                        error_log("isValidMove FAILED for bishop move ($fromQ, $fromR) -> ($q, $r), dq=$dq, dr=$dr");
                        
                    }
                }
            }
        }
        
        error_log("SUMMARY: Checked $totalChecked positions, found $validCount valid moves");
        error_log("Invalid reasons: " . print_r($invalidReasons, true));
        error_log("=== END DEBUGGING ===");
    }
    
    echo json_encode(['success' => true, 'validMoves' => $validMoves]);
    exit;
*/

case 'ai_move':
    // Start output buffering to capture ALL debug output
    ob_start();
    
    try {
        if (!isset($_SESSION['user_id'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }

        $gameId = trim($_POST['game_id'] ?? '');
        if ($gameId === '') {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Game ID required']);
            exit;
        }

        $gameInfo = $gameManager->getGame($gameId);
        if (!$gameInfo || empty($gameInfo['game'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Game not found']);
            exit;
        }

        $game = $gameInfo['game'];
        $gameData = $gameInfo['data'] ?? [];
        $players = $gameInfo['players'] ?? [];

        $gameMode = $gameData['game_mode'] ?? '';
        if ($gameMode !== 'ai') {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Not an AI game']);
            exit;
        }

        // Find AI player slot
        $aiPlayerSlot = null;
        $userPlayerSlot = null;
        
        foreach ($players as $p) {
            if ((int)$p['user_id'] === (int)$_SESSION['user_id']) {
                $userPlayerSlot = (int)$p['player_slot'];
            } else {
                $aiPlayerSlot = (int)$p['player_slot'];
            }
        }
        
        if ($aiPlayerSlot === null) {
            $aiPlayerSlot = ($userPlayerSlot === 0) ? 1 : 0;
        }

        // Check if it's AI's turn
        $currentPlayerSlot = (int)$game->getCurrentPlayerSlot();
        if ($currentPlayerSlot !== (int)$aiPlayerSlot) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Not AI turn']);
            exit;
        }

        // Check if game is over
        $gameState = $game->getGameState();
        if (!empty($gameState['gameStatus']['gameOver'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Game is over']);
            exit;
        }

        // Get AI difficulty
        $aiDifficulty = $gameData['ai_difficulty'] ?? 'basic';
        
        // Create AI and make move (this generates the debug output)
        $ai = new HexChessAI($game, $aiDifficulty, (int)$aiPlayerSlot);
        $moveResult = $ai->makeMove();

        if ($moveResult === true) {
            // Save the updated game state
            if (method_exists($gameManager, 'updateGameState')) {
                $gameManager->updateGameState($gameId, $game);
            } elseif (method_exists($gameManager, 'saveGame')) {
                $gameManager->saveGame($gameId, $game);
            }

            // Get updated game state
            $newGameState = $game->getGameState();
            
            // Check if game ended
            if (!empty($newGameState['gameStatus']['gameOver'])) {
                $winnerId = $newGameState['gameStatus']['winner'] ?? null;
                if ($winnerId) {
                    $gameManager->finishGame($gameId, $winnerId);
                }
            }

            // Discard ALL captured output and send clean JSON
            ob_clean();
            echo json_encode([
                'success' => true,
                'gameState' => $newGameState,
                'kingsInCheck' => $newGameState['kingsInCheck'] ?? [],
                'aiName' => (method_exists($ai, 'getAIName') ? $ai->getAIName() : 'AI'),
                'newCurrentPlayer' => $game->getCurrentPlayerSlot()
            ]);
        } else {
            // Discard ALL captured output and send clean JSON
            ob_clean();
            echo json_encode([
                'success' => false, 
                'error' => 'AI could not make a move'
            ]);
        }

    } catch (Exception $e) {
        // Discard ALL captured output and send clean JSON
        ob_clean();
        error_log("AI Move Error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'error' => 'Server error during AI move'
        ]);
    }
    
    // End output buffering
    ob_end_flush();
    exit;


    /*
    case 'ai_move':
    header('Content-Type: application/json; charset=utf-8');

    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    // --- TEMP DEBUG SWITCH (set to false after you fix it) ---
    $AI_DEBUG = true;


    // Convert warnings/notices into exceptions so they reach catch(Throwable)
    $__old_handler = set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    // If something still escapes, send JSON from shutdown (prevents blank 500s)
    $__sent = false;
    register_shutdown_function(function() use (&$__sent, $AI_DEBUG) {
        if ($__sent) return;
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            $payload = ['success' => false, 'error' => 'server_error', 'where' => 'shutdown'];
            if ($AI_DEBUG) {
                $payload['dev_error'] = $e['message'];
                $payload['file']      = $e['file'];
                $payload['line']      = $e['line'];
            }
            echo json_encode($payload);
        }
    });

    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']); $__sent = true; exit;
        }

        $gameId = trim($_POST['game_id'] ?? '');
        if ($gameId === '') {
            echo json_encode(['success' => false, 'error' => 'Game ID required']); $__sent = true; exit;
        }

        $gameInfo = $gameManager->getGame($gameId);
        if (!$gameInfo || empty($gameInfo['game'])) {
            echo json_encode(['success' => false, 'error' => 'Game not found']); $__sent = true; exit;
        }

        $game     = $gameInfo['game'];
        $gameData = is_array($gameInfo['data'] ?? null) ? $gameInfo['data'] : [];
        $players  = is_array($gameInfo['players'] ?? null) ? $gameInfo['players'] : [];

        $gameMode = $gameData['game_mode'] ?? '';
        if ($gameMode !== 'ai') {
            echo json_encode(['success' => false, 'error' => 'Not an AI game']); $__sent = true; exit;
        }

        // Determine AI player slot (the non-user in a 2-player AI game)
        $aiPlayerSlot = null;
        foreach ($players as $p) {
            if ((int)$p['user_id'] !== (int)$_SESSION['user_id']) { $aiPlayerSlot = (int)$p['player_slot']; break; }
        }
        if ($aiPlayerSlot === null) {
            // Fallback if players array is odd—infer from the user's slot
            $userPlayerSlot = null;
            foreach ($players as $p) {
                if ((int)$p['user_id'] === (int)$_SESSION['user_id']) { $userPlayerSlot = (int)$p['player_slot']; break; }
            }
            if ($userPlayerSlot === null) {
                echo json_encode(['success' => false, 'error' => 'User not in game']); $__sent = true; exit;
            }
            // Adjust if your engine uses 1/2 instead of 0/1
            $aiPlayerSlot = ($userPlayerSlot === 0) ? 1 : 0;
        }

        $currentPlayerSlot = (int)$game->getCurrentPlayerSlot();
        if ($currentPlayerSlot !== (int)$aiPlayerSlot) {
            echo json_encode(['success' => false, 'error' => 'Not AI turn']); $__sent = true; exit;
        }

        // Normalize difficulty robustly (covers "advanced"→"player", "medium"→"basic", and labels like "Player AI")
        $rawDiff = (string)($gameData['ai_difficulty'] ?? 'basic');
        $norm    = strtolower(preg_replace('/[^a-z]/', '', $rawDiff));
        $map     = [
            'advanced'=>'player', 'playerai'=>'player', 'player'=>'player',
            'medium'=>'basic',    'basicai'=>'basic',   'basic'=>'basic',
            'easy'=>'easy', 'hard'=>'hard'
        ];
        $aiDifficulty = $map[$norm] ?? 'basic';

        error_log(sprintf('AI_MOVE start: game=%s diff=%s (raw=%s) aiSlot=%d curSlot=%d',
            $gameId, $aiDifficulty, $rawDiff, (int)$aiPlayerSlot, (int)$currentPlayerSlot));

        // Run the AI
        $ai = new HexChessAI($game, $aiDifficulty, (int)$aiPlayerSlot);
        $ok = $ai->makeMove();

        if ($ok === true) {
            if (method_exists($gameManager, 'updateGameState')) {
                $gameManager->updateGameState($gameId, $game);
            } elseif (method_exists($gameManager, 'saveGame')) {
                $gameManager->saveGame($gameId, $game);
            }

            $gameState = $game->getGameState();
            echo json_encode([
                'success'          => true,
                'gameState'        => $gameState,
                'kingsInCheck'     => $gameState['kingsInCheck'] ?? [],
                'aiName'           => (method_exists($ai, 'getAIName') ? $ai->getAIName() : 'AI'),
                'newCurrentPlayer' => $game->getCurrentPlayerSlot()
            ]);
            $__sent = true; exit;
        }

        // AI declined/failed gracefully (still respond JSON)
        error_log('AI_MOVE declined: '.var_export($ok, true));
        echo json_encode(['success' => false, 'error' => 'AI move failed', 'result' => $ok]);
        $__sent = true; exit;

    } catch (Throwable $e) {
        // Catch EVERYTHING (Error/TypeError/Exception/notice-as-exception)
        error_log('AI_MOVE_FATAL: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        error_log($e->getTraceAsString());
        http_response_code(500);
        $payload = ['success'=>false,'error'=>'server_error','where'=>'ai_move'];
        if ($AI_DEBUG) {
            $payload['dev_error'] = $e->getMessage();
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
        }
        echo json_encode($payload);
        $__sent = true; exit;
    } finally {
        if ($__old_handler !== null) set_error_handler($__old_handler);
    }
*/

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
                exit;
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error occurred']);
        exit;
    }
}

// Helpers
function calculateStartingPositions($playerCount) {
    switch ($playerCount) {
        case 2: return [0, 3];
        case 3: return [0, 2, 4];
        case 4: return [0, 1, 3, 4];
        case 5: return [0, 1, 2, 3, 4];
        case 6: return [0, 1, 2, 3, 4, 5];
        default: return [0, 3];
    }
}
/* */

$isLoggedIn  = isset($_SESSION['user_id']);
$currentUser = $isLoggedIn ? $user->getUserById($_SESSION['user_id']) : null;
//$page        = $_GET['page'] ?? 'lobby';

// Place this after the calculateStartingPositions() function, before the HTML starts

function getRoomDisplayName($page, $gameData = null, $game = null, $players = null) {
    if ($page !== 'game' || !$gameData || !$game) {
        return null; // No room name for non-game pages
    }
    
    $gameMode = $gameData['game_mode'] ?? 'multiplayer';
    $roomName = $gameData['room_name'] ?? '';
    
    // If there's a custom room name that's not auto-generated, use it
    if ($roomName && !preg_match('/^(Online|Hotseat|\w+ AI).*(Mini|Small|Medium|Large|Jumbo).*$/', $roomName)) {
        return $roomName;
    }
    
    // Generate descriptive name based on game settings
    $details = [];
    
    // Game type and AI difficulty  
    if ($gameMode === 'ai') {
        $aiDifficulty = $gameData['ai_difficulty'] ?? 'basic';
        $aiName = ucfirst($aiDifficulty);
        $details[] = "{$aiName} AI";
    } else if ($gameMode === 'hotseat') {
        $details[] = "Hotseat";
    } else {
        $details[] = "Online";
    }
    
    // Player count (for non-AI or multiplayer)
    if ($players && ($gameMode !== 'ai' || count($players) > 2)) {
        $playerCount = count($players);
        if ($playerCount > 2) {
            $details[] = "{$playerCount}P";
        }
    }
    
    // Board size
    if (method_exists($game, 'getBoardSize')) {
        $boardSize = $game->getBoardSize();
        $sizeNames = [4 => 'Mini', 5 => 'Small', 6 => 'Medium', 7 => 'Large', 8 => 'Jumbo'];
        $sizeName = $sizeNames[$boardSize] ?? 'Medium';
        $details[] = $sizeName;
    }
    
    return implode(' ', $details);
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>HexChess Online</title>
  <link rel="stylesheet" href="assets/styles.css?x=7">
</head>
<body>
<div class="header">
    <div class="header-title-section">
        <h1>🏰 HexChess Online</h1>
 <?php if ($page === 'game' && isset($gameData['game_name'])): ?>
    <div style="font-size: 18px; background: linear-gradient(45deg, #fff, #e0e0e0); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-top: 6px;">
        <!?php echo htmlspecialchars($gameData['game_name']); ?>
    </div>
<?php endif; ?>
    </div>
    
    <div class="user-info">
        <?php if ($isLoggedIn): ?>
            <span>Welcome, <?php echo htmlspecialchars($currentUser['username']); echo " " . date('Y-m-d H:i:s');?>!</span>
            <span>(<?php echo $currentUser['wins']; ?>W-<?php echo $currentUser['losses']; ?>L)</span>
            <button class="btn btn-secondary" onclick="logout()">Logout</button>
        <?php else: ?>
            <button class="btn btn-secondary" onclick="window.location.href='?page=demo'">📚 Demo</button>
            <button class="btn" onclick="showAuth('login')">Login</button>
            <button class="btn btn-secondary" onclick="showAuth('register')">Register</button>
        <?php endif; ?>
    </div>
</div>

  <?php if (!$isLoggedIn && $page !== 'demo'): ?>
    <!-- Auth forms -->
    <div class="auth-container" id="loginForm" style="display:none;">
      <h2>Login</h2>
      <form onsubmit="login(event)">
        <div class="form-group">
          <label>Username:</label>
          <input type="text" name="username" required>
        </div>
        <div class="form-group">
          <label>Password:</label>
          <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn" style="width:100%;">Login</button>
      </form>
      <p style="text-align:center;margin-top:1rem;">
        <a href="#" onclick="showAuth('register')" style="color:#fff;">Need an account? Register</a>
      </p>
      <p style="text-align:center;margin-top:0.5rem;">
        <a href="?page=demo" style="color:#2ecc71;">📚 Try the Piece Movement Demo</a>
      </p>
    </div>

    <div class="auth-container" id="registerForm" style="display:none;">
      <h2>Register</h2>
      <form onsubmit="register(event)">
        <div class="form-group">
          <label>Username (3+ characters):</label>
          <input type="text" name="username" minlength="3" required>
        </div>
        <div class="form-group">
          <label>Password (6+ characters):</label>
          <input type="password" name="password" minlength="6" required>
        </div>
        <div class="form-group">
          <label>Email (optional):</label>
          <input type="email" name="email">
        </div>
        <button type="submit" class="btn" style="width:100%;">Register</button>
      </form>
      <p style="text-align:center;margin-top:1rem;">
        <a href="#" onclick="showAuth('login')" style="color:#fff;">Have an account? Login</a>
      </p>
      <p style="text-align:center;margin-top:0.5rem;">
        <a href="?page=demo" style="color:#2ecc71;">📚 Try the Piece Movement Demo</a>
      </p>
    </div>

  <?php elseif ($page === 'lobby'): ?>
    <!-- Lobby -->
    <div class="container">
      <div class="lobby">

        <div class="panel">
          <h2>🎮 Create New Hexchess Game</h2>
          <form onsubmit="createEnhancedGame(event)">
            <div class="form-group">
              <label>Game Type:</label>
              <select name="game_type" id="game_type">
                <option value="online">👥 Online Multiplayer</option>
                <option value="vs_ai" selected>🤖 vs AI</option>
                <option value="hotseat">🏠 Local Hotseat</option>
              </select>
            </div>

            <div class="form-group" id="players-group">
              <label>Players:</label>
              <select name="player_count" id="player_count">
                <option value="2">2 Players</option>
                <option value="3">3 Players</option>
                <option value="4">4 Players</option>
                <option value="5">5 Players</option>
                <option value="6">6 Players</option>
              </select>
            </div>

<div class="form-group" id="ai-difficulty-group" style="display:none;">
  <label>AI Difficulty:</label>
  <select name="ai_difficulty" id="ai_difficulty">
    <option value="easy">🟢 Easy</option>
    <option value="basic">🟡 Basic</option>
    <option value="player" selected>🟠 Player</option>
    <option value="hard">🔴 Hard</option>
  </select>
</div>

            <div class="form-group">
              <label>Board Size:</label>
              <select name="board_size" id="board_size">
                <option value="4">Mini Board</option>
                <option value="5">Small Board</option>
                <option value="6">Medium Board</option>
                <option value="7">Large Board</option>
                <option value="8">Jumbo Board</option>
              </select>
            </div>

            <div id="timer-settings" class="timer-settings" style="display:none;">
              <div class="timer-info"><strong>⏱️ Turn Timer (for 4-6 player games)</strong></div>
              <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="form-group">
                  <label>Turn Time Limit:</label>
                  <select name="turn_timer">
                    <option value="30">30 seconds</option>
                    <option value="60">1 minute</option>
                    <option value="120">2 minutes</option>
                    <option value="300">5 minutes</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>When Timer Expires:</label>
                  <select name="skip_action">
                    <option value="skip_turn">Skip Turn</option>
                    <option value="eliminate">Eliminate Player</option>
                  </select>
                </div>
              </div>
            </div>


<!-- NEW: Enhanced game options section with fog of war -->
<div class="form-group">
  <label>Game Options:</label>
  <div class="hexchess-options">
    <div class="option-item">
      <input type="checkbox" name="fog_of_war" id="fog_of_war" onchange="updateEnhancedDefaultGameName()">
      <label for="fog_of_war" class="option-label">
        <strong>🌫️ Fog of War</strong>
        <span class="option-description">
          <br>Only see opponent pieces that your pieces can attack or are adjacent to
        </span>
      </label>
    </div>
    
    <div class="option-item">
      <input type="checkbox" name="shogi_drops" id="shogi_drops" onchange="updateEnhancedDefaultGameName()">
      <label for="shogi_drops" class="option-label">
        <strong>♻️ Shogi Drops</strong>
        <span class="option-description">
          <br>Captured pieces join your army and can be dropped back into play
        </span>
      </label>
    </div>
  </div>
</div>
    

            <div class="form-group">
              <label>Game Name:</label>
              <input type="text" name="room_name" id="game_name"
                     placeholder="Enter custom name or use default"
                     value="2-Player Large Game" maxlength="100">
              <div class="form-helper">
                <small>Customize your room name</small>
                <button type="button" class="btn-reset" onclick="resetToEnhancedDefaultName()">Reset to Default</button>
              </div>
            </div>

            <button type="submit" class="btn" style="width:100%;">Create Game</button>
          </form>

          <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.2);">
            <button class="btn btn-secondary" onclick="window.location.href='?page=demo'" style="width:100%;">📚 Learn Piece Movements</button>
          </div>
        </div>

        <div class="panel">
          <h2>🎯 Your Active Games</h2>
          <div class="game-list" id="my-games"><p>Loading your games...</p></div>
          <button class="btn btn-secondary" onclick="loadMyGames()" style="width:100%;margin-top:1rem;">Refresh My Games</button>
        </div>

      </div>

      <div class="lobby" style="margin-top:2rem;">
        <div class="panel" style="grid-column:1 / -1;">
          <h2>🌐 Available Games</h2>
          <div class="game-list" id="available-games"><p>Loading games...</p></div>
          <button class="btn btn-secondary" onclick="loadAvailableGames()" style="width:100%;margin-top:1rem;">Refresh</button>
        </div>
      </div>
    </div>

  <?php elseif ($page === 'demo'): ?>
    <!-- Piece Movement Demo -->
    <div class="game-area">
      <div class="game-sidebar">
        <div class="game-status">
          <h3>📚 Piece Movement Demo</h3>
          <p>Select a piece type to see how it moves!</p>
        </div>

        <div class="player-list">
          <h3 style="margin-bottom:20px;">Choose Piece Type</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:15px;">
            <button class="piece-nav-btn active" onclick="showPiece('king')"   id="nav-king">   ♚ King</button>
            <button class="piece-nav-btn"         onclick="showPiece('queen')"  id="nav-queen">  ♛ Queen</button>
            <button class="piece-nav-btn"         onclick="showPiece('rook')"   id="nav-rook">   ♜ Rook</button>
            <button class="piece-nav-btn"         onclick="showPiece('bishop')" id="nav-bishop"> ♝ Bishop</button>
            <button class="piece-nav-btn"         onclick="showPiece('knight')" id="nav-knight"> ♞ Knight</button>
            <button class="piece-nav-btn"         onclick="showPiece('pawn')"   id="nav-pawn">   ♟ Pawn</button>
          </div>

          <div id="piece-description">
            <div id="desc-king" class="piece-desc active"><h4>♚ King Movement</h4><p>Moves one space in any direction (12 hex neighbors).</p></div>
            <div id="desc-queen" class="piece-desc"><h4>♛ Queen Movement</h4><p>Combines rook + bishop movement on hexes.</p></div>
            <div id="desc-rook"  class="piece-desc"><h4>♜ Rook Movement</h4><p>Any distance along three orthogonal axes.</p></div>
            <div id="desc-bishop"class="piece-desc"><h4>♝ Bishop Movement</h4><p>Any distance along the three diagonals.</p></div>
            <div id="desc-knight"class="piece-desc"><h4>♞ Knight Movement</h4><p>Jumps in hex "L"-shapes.</p></div>
            <div id="desc-pawn"  class="piece-desc"><h4>♟ Pawn Movement</h4><p>Forward one; capture forward diagonals.</p></div>
          </div>
        </div>

        <?php if ($isLoggedIn): ?>
          <div style="display:flex;gap:10px;flex-direction:column;">
            <button class="btn btn-secondary" onclick="window.location.href='?page=lobby'">Back to Lobby</button>
          </div>
        <?php else: ?>
          <button class="btn btn-secondary" onclick="window.location.href='?'">← Back to Login</button>
        <?php endif; ?>
      </div>

      <div class="game-main">
        <?php $demoGame = createDemoBoard(); echo renderDemoBoard($demoGame, 'king'); ?>
      </div>
    </div>

  <?php elseif ($page === 'game'): ?>
    <?php
      $gameId   = $_GET['id'] ?? '';
      $gameInfo = $gameManager->getGame($gameId);
      if (!$gameInfo):
    ?>


<div class="container">
        <div class="panel">
          <h2>Game Not Found</h2>
          <p>The requested game could not be found.</p>
          <button class="btn" onclick="window.location.href='?page=lobby'">Back to Lobby</button>
        </div>
      </div>
    <?php else:
        $game      = $gameInfo['game'];
        $players   = $gameInfo['players'];
        $gameData  = $gameInfo['data'];
        $gameState = $game->getGameState();

        $userPlayerSlot = null;
        foreach ($players as $p) {
            if ($p['user_id'] == $_SESSION['user_id']) { $userPlayerSlot = $p['player_slot']; break; }
        }

        $canMove    = $userPlayerSlot !== null && $game->canUserMove($_SESSION['user_id']);
        $gameActive = ($gameData['status'] === 'active');
        $userInGame = ($userPlayerSlot !== null);

        // Precompute (so we can put them in data-attributes cleanly)
        $gameMode          = $gameData['game_mode'] ?? 'multiplayer';
        $isAIGame          = ($gameMode === 'ai');
        $currentPlayerSlot = method_exists($game, 'getCurrentPlayerSlot') ? $game->getCurrentPlayerSlot() : 0;

        // NEW: Get fog of war and shogi drops settings
        $fogOfWarEnabled = isset($gameData['fog_of_war']) && $gameData['fog_of_war'] == 1;
        $shogiDropsEnabled = isset($gameData['shogi_drops']) && $gameData['shogi_drops'] == 1;

        $aiPlayerSlot = null;
        if ($isAIGame) {
            foreach ($players as $pl) {
                if ($pl['user_id'] != $_SESSION['user_id']) { $aiPlayerSlot = $pl['player_slot']; break; }
            }
            if ($aiPlayerSlot === null) { $aiPlayerSlot = ($userPlayerSlot == 0) ? 1 : 0; }
        }
    ?>

          <script>
// --- Globals used by assets/game.js ---
var gameId = <?php echo json_encode($gameId); ?>;

// game mode from server (multiplayer | ai | hotseat)
var gameMode = <?php echo json_encode($gameData['game_mode'] ?? 'multiplayer'); ?>;

// whose turn gating (in hotseat we allow clicks from this device)
var userCanMove = (gameMode === 'hotseat')
  ? true
  : <?php echo $canMove ? 'true' : 'false'; ?>;

// optional helpful context (not required, but fine to keep)
var currentPlayerSlot = <?php echo json_encode(method_exists($game, 'getCurrentPlayerSlot') ? $game->getCurrentPlayerSlot() : null); ?>;
var userPlayerSlot     = <?php echo json_encode($userPlayerSlot); ?>;
var isAIGame           = (gameMode === 'ai');
var aiPlayerSlot       = <?php
  $aiSlot = null;
  if (($gameData['game_mode'] ?? '') === 'ai') {
      foreach ($players as $p) { if ($p['user_id'] != $_SESSION['user_id']) { $aiSlot = $p['player_slot']; break; } }
      if ($aiSlot === null) { $aiSlot = ($userPlayerSlot == 0) ? 1 : 0; }
  }
  echo json_encode($aiSlot);
?>;

// NEW: Fog of war and shogi drops globals
var fogOfWarEnabled = <?php echo $fogOfWarEnabled ? 'true' : 'false'; ?>;
var shogiDropsEnabled = <?php echo $shogiDropsEnabled ? 'true' : 'false'; ?>;
</script>

    <div class="game-area">
      <div class="game-sidebar">
        <h2><?php echo htmlspecialchars($gameData['game_name'] ?? 'HexChess Game'); ?></h2>
        
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="64" height="64">
  <polygon points="64,8 112,36 112,92 64,120 16,92 16,36" 
           fill="black" stroke="white" stroke-width="3"/>
  <text x="64" y="96" font-family="Georgia, serif" font-size="110" 
        text-anchor="middle" fill="white">♘</text>
</svg>

        <!-- NEW: Game options display -->
        <?php if ($fogOfWarEnabled || $shogiDropsEnabled): ?>
        <div class="game-options">
          <?php if ($fogOfWarEnabled): ?>
            <span class="game-option-badge fog-of-war">🌫️ Fog of War</span>
          <?php endif; ?>
          <?php if ($shogiDropsEnabled): ?>
            <span class="game-option-badge shogi-drops">♻️ Shogi Drops</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

<?php
// Replace the game status section in index.php (around line 850-900)

if ($gameMode === 'hotseat'): ?>
  <!-- For hotseat, check if game is over first -->
  <?php if ($gameState['gameStatus']['gameOver']): ?>
    <div style="background: rgba(255,255,255,0.15); border-radius: 8px; padding: 15px; margin-bottom: 15px; text-align: center;">
      <div style="font-size: 24px; padding: 10px; background: rgba(255,255,255,0.2); border-radius: 6px; border: 2px solid #e74c3c;">
        <?php 
          // Show proper end game message
          if (isset($gameState['gameStatus']['winner'])) {
            $winnerSlot = null;
            foreach ($players as $pl) {
              if ($pl['user_id'] == $gameState['gameStatus']['winner']) {
                $winnerSlot = $pl['player_slot'];
                break;
              }
            }
            if ($winnerSlot !== null) {
              $playerEmojis = ['🔴', '🔵', '🟢', '🟡', '🟣', '🟠'];
              $playerNames = ['Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Bronze'];
              $reason = $gameState['gameStatus']['reason'] ?? 'checkmate';
              if ($reason === 'checkmate') {
                echo '♛ CHECKMATE! ' . $playerEmojis[$winnerSlot] . ' ' . $playerNames[$winnerSlot] . ' Wins!';
              } else if ($reason === 'resignation') {
                echo '🏳️ ' . $playerEmojis[$winnerSlot] . ' ' . $playerNames[$winnerSlot] . ' Wins by Resignation!';
              } else {
                echo '🎉 ' . $playerEmojis[$winnerSlot] . ' ' . $playerNames[$winnerSlot] . ' Wins!';
              }
            } else {
              echo '🎉 Game Complete!';
            }
          } else {
            $reason = $gameState['gameStatus']['reason'] ?? 'draw';
            if ($reason === 'stalemate') {
              echo '🤝 STALEMATE - Draw!';
            } else if ($reason === 'insufficient_material') {
              echo '🤝 DRAW - Insufficient Material!';
            } else if ($reason === 'repetition') {
              echo '🤝 DRAW - Repetition!';
            } else {
              echo '🤝 DRAW - Game Ended in Tie!';
            }
          }
        ?>
      </div>
    </div>
  <?php else: ?>
    <!-- Normal turn indicator for active game -->
    <div style="background: rgba(255,255,255,0.1); border-radius: 8px; padding: 15px; margin-bottom: 15px; text-align: center;">
      <?php 
        $currentSlot = $game->getCurrentPlayerSlot();
        $playerEmojis = ['🔴', '🔵', '🟢', '🟡', '🟣', '🟠'];
        $playerNames = ['Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Bronze'];
      ?>
      <div style="font-size: 24px; padding: 10px; background: rgba(255,255,255,0.2); border-radius: 6px; border: 2px solid #2ecc71;">
        <?php echo $playerEmojis[$currentSlot] . ' ' . $playerNames[$currentSlot] . '\'s Turn'; ?>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <!-- Regular status for online/AI games -->
  <div class="game-status">
    <h3 id="gameStatus">
      <?php
        if ($gameState['gameStatus']['gameOver']) {
          // Show specific end game reason instead of generic "Game Over"
          if (isset($gameState['gameStatus']['winner'])) {
            $reason = $gameState['gameStatus']['reason'] ?? 'checkmate';
            if ($reason === 'checkmate') {
              echo "♛ Checkmate!";
            } else if ($reason === 'resignation') {
              echo "🏳️ Resignation!";
            } else {
              echo "🎉 Victory!";
            }
          } else {
            $reason = $gameState['gameStatus']['reason'] ?? 'draw';
            if ($reason === 'stalemate') {
              echo "🤝 Stalemate!";
            } else if ($reason === 'insufficient_material') {
              echo "🤝 Draw - Insufficient Material!";
            } else if ($reason === 'repetition') {
              echo "🤝 Draw - Repetition!";
            } else {
              echo "🤝 Draw!";
            }
          }
        } elseif ($gameData['status'] === 'waiting') {
          echo "Waiting for Players";
        } else {
          echo "Game Active";
        }
      ?>
    </h3>
    <p id="gameStatusText">
      <?php
        if ($gameState['gameStatus']['gameOver']) {
          if (isset($gameState['gameStatus']['winner'])) {
            $winnerName = "Unknown";
            foreach ($players as $pl) {
              if ($pl['user_id'] == $gameState['gameStatus']['winner']) { 
                $winnerName = $pl['username']; 
                break; 
              }
            }
            $reason = $gameState['gameStatus']['reason'] ?? 'checkmate';
            if ($reason === 'checkmate') {
              echo '<div style="font-size:18px;font-weight:bold;color:#e74c3c;">🏆 ' . htmlspecialchars($winnerName) . ' wins by checkmate!</div>';
            } else if ($reason === 'resignation') {
              echo '<div style="font-size:18px;font-weight:bold;color:#3498db;">🏆 ' . htmlspecialchars($winnerName) . ' wins by resignation!</div>';
            } else {
              echo '<div style="font-size:18px;font-weight:bold;color:#2ecc71;">🏆 Winner: ' . htmlspecialchars($winnerName) . '</div>';
            }
          } else {
            $reason = $gameState['gameStatus']['reason'] ?? 'draw';
            if ($reason === 'stalemate') {
              echo '<div style="font-size:18px;font-weight:bold;color:#f39c12;">🤝 STALEMATE - No legal moves available</div>';
            } else if ($reason === 'insufficient_material') {
              echo '<div style="font-size:18px;font-weight:bold;color:#f39c12;">🤝 DRAW - Insufficient material to checkmate</div>';
            } else if ($reason === 'repetition') {
              echo '<div style="font-size:18px;font-weight:bold;color:#f39c12;">🤝 DRAW - Position repeated three times</div>';
            } else {
              echo '<div style="font-size:18px;font-weight:bold;color:#f39c12;">🤝 DRAW - Game ended in a tie</div>';
            }
          }
        } elseif ($gameData['status'] === 'waiting') {
          echo "Need " . ($game->getPlayerCount() - count($players)) . " more player(s)";
        } elseif ($canMove) {
          echo "Your turn!";
        } else {
          // Show whose turn it is only if game is still active
          $currentPlayerName = "Unknown";
          $currentSlot = $game->getCurrentPlayerSlot();
          $playerNames = ['Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Bronze'];
          $currentPlayerName = $playerNames[$currentSlot] ?? 'Player ' . ($currentSlot + 1);
          echo "Waiting for " . $currentPlayerName . " player";
        }
      ?>
    </p>
    <?php if (!$gameState['gameStatus']['gameOver'] && $gameState['isInCheck']): ?>
      <div class="check-warning"><strong>⚠️ Your King is in Check!</strong></div>
    <?php endif; ?>
    
    <!-- NEW: Fog of war status indicator (only show during active game) -->
    <?php if (!$gameState['gameStatus']['gameOver'] && $fogOfWarEnabled): ?>
    <div class="fog-status active">
      <small>👁️ Limited visibility - only see pieces you can attack or that are adjacent</small>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>


<div class="player-list">
  <?php if ($gameMode === 'hotseat'): ?>
    <h3>🎮 Hotseat Players</h3>
   <?php else: ?>
    <h3>Players</h3>
  <?php endif; ?>
  
  <div id="playerList">
  <?php foreach ($players as $pl): ?>
    <div class="player-item <?php echo $pl['player_slot'] == $game->getCurrentPlayerSlot() ? 'current' : ''; ?>">
      <?php 
        $playerEmojis = ['🔴', '🔵', '🟢', '🟡', '🟣', '🟠'];
        $playerNames = ['Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Bronze'];
        $slotEmoji = $playerEmojis[$pl['player_slot']] ?? '';
        $slotName = $playerNames[$pl['player_slot']] ?? ucfirst($game->getPlayers()[$pl['player_slot']]);
      ?>
      <strong><?php echo $slotEmoji . ' ' . $slotName; ?></strong><br>
      <?php if ($gameMode === 'hotseat'): ?>
        <small>Player <?php echo $pl['player_slot'] + 1; ?></small>
      <?php elseif ($pl['user_id'] == $_SESSION['user_id']): ?>
        <?php echo htmlspecialchars($pl['username']); ?> <small>(You)</small>
      <?php elseif ($gameData['game_mode'] === 'ai'): ?>
        <!-- AI display logic -->
      <?php else: ?>
        <?php echo htmlspecialchars($pl['username']); ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>

        <div class="game-controls">
          <button class="btn btn-secondary" onclick="window.open('?page=demo','_blank')" style="width:100%;margin-bottom:10px;">📚 Piece Movements</button>
          <?php if ($gameActive && $userInGame && !$gameState['gameStatus']['gameOver']): ?>
            <button class="btn btn-danger" onclick="resignGame()" style="width:100%;margin-bottom:10px;">🏳️ Resign Game</button>
          <?php endif; ?>
          <button class="btn btn-secondary" onclick="window.location.href='?page=lobby'" style="width:100%;">Back to Lobby</button>
        </div>
      </div>

      <!-- Data root for client JS -->
      <div id="hexchess-root"
           data-game-id="<?php echo htmlspecialchars($gameId); ?>"
           data-user-can-move="<?php echo $canMove ? '1' : '0'; ?>"
           data-game-mode="<?php echo htmlspecialchars($gameMode); ?>"
           data-current-player-slot="<?php echo (int)$currentPlayerSlot; ?>"
           data-user-player-slot="<?php echo (int)($userPlayerSlot ?? -1); ?>"
           data-ai-player-slot="<?php echo ($aiPlayerSlot === null ? '' : (int)$aiPlayerSlot); ?>"
           data-fog-of-war="<?php echo $fogOfWarEnabled ? '1' : '0'; ?>"
           data-shogi-drops="<?php echo $shogiDropsEnabled ? '1' : '0'; ?>">
      </div>

<script>
(function () {
  var root = document.getElementById('hexchess-root');
  if (!root) return;

  // ONLY data initialization - NO AI triggers here
  window.gameId = root.dataset.gameId || null;
  window.gameMode = root.dataset.gameMode || 'multiplayer';
  window.isAIGame = (window.gameMode === 'ai');

  var cps = root.dataset.currentPlayerSlot;
  var ups = root.dataset.userPlayerSlot;
  var ais = root.dataset.aiPlayerSlot;

  window.currentPlayerSlot = cps !== '' ? parseInt(cps, 10) : null;
  window.userPlayerSlot    = ups !== '' ? parseInt(ups, 10) : null;
  window.aiPlayerSlot      = ais === '' ? null : parseInt(ais, 10);

  window.fogOfWarEnabled   = root.dataset.fogOfWar === '1';
  window.shogiDropsEnabled = root.dataset.shogiDrops === '1';
  window.userCanMove = (root.dataset.userCanMove === '1');
  
  // Remove all AI trigger logic from this section
})();
</script>

      <!-- Actual board -->
      <div class="game-main <?php echo $fogOfWarEnabled ? 'fog-of-war-enabled' : ''; ?>">
        <?php 
        // NEW: Render board with fog of war support
        echo renderBoard($game, $gameId, $canMove, $userPlayerSlot); 
        ?>
      </div>
    </div>

<script>
  // ==== GAME GLOBALS (added) ====
  var gameId = <?php echo json_encode($gameId); ?>;
  var gameMode = <?php echo json_encode($gameData['game_mode'] ?? 'multiplayer'); ?>;
  var isAIGame = (gameMode === 'ai');
  var currentPlayerSlot = <?php
    $currentPlayerSlot = method_exists($game, 'getCurrentPlayerSlot') ? $game->getCurrentPlayerSlot() : null;
    echo json_encode($currentPlayerSlot);
  ?>;
  var userPlayerSlot = <?php echo json_encode($userPlayerSlot); ?>;

  // Find AI slot if needed
  <?php
    $aiPlayerSlot = null;
    if (($gameData['game_mode'] ?? '') === 'ai') {
        foreach ($players as $p) {
            if ($p['user_id'] != $_SESSION['user_id']) { $aiPlayerSlot = $p['player_slot']; break; }
        }
        if ($aiPlayerSlot === null) { $aiPlayerSlot = ($userPlayerSlot == 0) ? 1 : 0; }
    }
  ?>
  var aiPlayerSlot = <?php echo json_encode($aiPlayerSlot); ?>;

  var userCanMove = <?php echo $canMove ? 'true' : 'false'; ?>;
  if (gameMode === 'hotseat') { userCanMove = true; }

  // NEW: Game options globals
  var fogOfWarEnabled = <?php echo $fogOfWarEnabled ? 'true' : 'false'; ?>;
  var shogiDropsEnabled = <?php echo $shogiDropsEnabled ? 'true' : 'false'; ?>;
</script>

<script>
// Single AI trigger - no conflicts
(function() {
    if (typeof isAIGame === 'undefined' || !isAIGame) return;
    
    let triggered = false;
    
    function triggerAI() {
        if (triggered) return;
        if (!gameId || currentPlayerSlot !== aiPlayerSlot) return;
        
        triggered = true;
        
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ai_move&game_id=' + encodeURIComponent(gameId)
        })
        .then(r => r.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data && data.success) {
                    location.reload();
                } else {
                    console.warn('AI move failed:', data);
                    triggered = false;
                }
            } catch (e) {
                console.error('JSON parse error:', e, 'Response:', text);
                triggered = false;
            }
        })
        .catch(err => {
            console.error('AI move error:', err);
            triggered = false;
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', triggerAI);
    } else {
        triggerAI();
    }
})();
</script>


    <?php if (!empty($gameState['kingsInCheck'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        <?php foreach ($gameState['kingsInCheck'] as $king): ?>
        var cell = document.querySelector('[data-q="<?php echo $king['q']; ?>"][data-r="<?php echo $king['r']; ?>"]');
        if (cell) cell.classList.add('king-in-check');
        <?php endforeach; ?>
      });
    </script>
    <?php endif; ?>

    <?php endif; // game found ?>
  <?php endif; // page switch ?>


<?php if ($page === 'lobby'): ?>
<!-- FIXED: Single lobby JavaScript section -->
<script>
console.log('INDEX.PHP LOBBY SCRIPT DISABLED FOR TESTING');
// Early exit - disable this entire script for testing
if (true) {
    console.log('Exiting index.php lobby script');
    // Don't return here, just skip to the end
} else {
    

console.log('✅ Loading enhanced room creation (conflict-free version)');

/*
const BOARD_PLAYER_LIMITS = {
    4: { min: 2, max: 3, name: "Mini" },
    5: { min: 2, max: 3, name: "Small" },
    6: { min: 2, max: 4, name: "Medium" },
    7: { min: 2, max: 6, name: "Large" },
    8: { min: 2, max: 6, name: "Jumbo" }
};
*/

function updateEnhancedGameModeOptions() {
    console.log('🔄 updateEnhancedGameModeOptions called');
    
    const gameType = document.getElementById('game_type');
    const playersGroup = document.getElementById('players-group');
    const aiDifficultyGroup = document.getElementById('ai-difficulty-group');
    const playerCount = document.getElementById('player_count');
    
    if (!gameType || !playersGroup || !aiDifficultyGroup || !playerCount) {
        console.log('❌ Missing form elements');
        return;
    }
    
    const gameTypeValue = gameType.value;
    console.log('🔄 Game type changed to:', gameTypeValue);
    
    if (gameTypeValue === 'vs_ai') {
        aiDifficultyGroup.style.display = 'block';
        playersGroup.style.display = 'none';
        playerCount.innerHTML = '<option value="2">2 Players</option>';
        playerCount.value = '2';
    } else {
        aiDifficultyGroup.style.display = 'none';
        playersGroup.style.display = 'block';
        updatePlayerOptions();
    }
    
    updateTimerVisibility();
    updateEnhancedDefaultGameName();
}

function updatePlayerOptions() {
    const boardSizeSelect = document.getElementById('board_size');
    const playerCountSelect = document.getElementById('player_count');
    
    if (!boardSizeSelect || !playerCountSelect) {
        console.log('❌ Missing board/player elements');
        return;
    }
    
    const boardSize = parseInt(boardSizeSelect.value);
    const limits = BOARD_PLAYER_LIMITS[boardSize] || BOARD_PLAYER_LIMITS[6];
    const currentValue = parseInt(playerCountSelect.value);
    
    console.log('🔄 Board size:', boardSize, 'Limits:', limits);
    
    // Clear and rebuild options
    playerCountSelect.innerHTML = '';
    for (let i = limits.min; i <= limits.max; i++) {
        const option = document.createElement('option');
        option.value = i;
        option.textContent = `${i} Players`;
        playerCountSelect.appendChild(option);
    }
    
    // Set appropriate value
    if (currentValue >= limits.min && currentValue <= limits.max) {
        playerCountSelect.value = currentValue;
    } else {
        playerCountSelect.value = limits.min;
    }
    
    updateTimerVisibility();
    updateEnhancedDefaultGameName();
}

function updateTimerVisibility() {
    const playerCountSelect = document.getElementById('player_count');
    const timerSettings = document.getElementById('timer-settings');
    
    if (!playerCountSelect || !timerSettings) return;
    
    const playerCount = parseInt(playerCountSelect.value);
    timerSettings.style.display = (playerCount >= 4) ? 'block' : 'none';
}

function updateEnhancedDefaultGameName() {
    console.log('🔄 updateEnhancedDefaultGameName called');
    
    const gameType = document.getElementById('game_type');
    const playerCount = document.getElementById('player_count');
    const boardSize = document.getElementById('board_size');
    const aiDifficulty = document.getElementById('ai_difficulty');
    const gameNameField = document.getElementById('game_name');
    const fogOfWarCheckbox = document.getElementById('fog_of_war');
    const shogiDropsCheckbox = document.getElementById('shogi_drops');
    
    console.log('Elements found:', {
        gameType: !!gameType,
        playerCount: !!playerCount,
        boardSize: !!boardSize,
        gameNameField: !!gameNameField,
        fogOfWar: !!fogOfWarCheckbox,
        shogiDrops: !!shogiDropsCheckbox
    });
    
    if (!gameType || !playerCount || !boardSize || !gameNameField) {
        console.log('❌ Missing required form elements');
        return;
    }

    const currentValue = gameNameField.value.trim();
    console.log('Current room name value:', currentValue);
    
    // Check if current value is a default name (should be replaced)
    const isDefaultName = currentValue === '' ||
        currentValue.match(/^(Online|vs AI|Hotseat|\w+ AI).*(Mini|Small|Medium|Large|Jumbo).*$/) ||
        currentValue.match(/^\d+-Player (Mini|Small|Medium|Large|Jumbo) Game.*$/) ||
        currentValue.match(/^(Online|Hotseat) \d+P (Mini|Small|Medium|Large|Jumbo).*$/);

    console.log('Should update name?', isDefaultName);

    if (!isDefaultName) {
        console.log('Keeping custom name:', currentValue);
        return;
    }
    
    const gameTypeValue = gameType.value;
    const playerCountValue = playerCount.value;
    const boardSizeValue = boardSize.value;
    const aiDifficultyValue = aiDifficulty ? aiDifficulty.value : 'player';
    
    console.log('Form values:', {
        gameType: gameTypeValue,
        playerCount: playerCountValue,
        boardSize: boardSizeValue,
        aiDifficulty: aiDifficultyValue
    });
    
    const sizeName = (BOARD_PLAYER_LIMITS[boardSizeValue] || {}).name || 'Large';
    let defaultName = '';
    
    if (gameTypeValue === 'vs_ai') {
        const difficultyName = aiDifficultyValue.charAt(0).toUpperCase() + aiDifficultyValue.slice(1);
        defaultName = `${difficultyName} AI ${sizeName}`;
    } else {
        const typeName = (gameTypeValue === 'hotseat') ? 'Hotseat' : 'Online';
        defaultName = `${typeName} ${playerCountValue}P ${sizeName}`;
    }
    
    // Add hexchess option indicators
    const options = [];
    if (fogOfWarCheckbox && fogOfWarCheckbox.checked) {
        options.push('Fog');
    }
    if (shogiDropsCheckbox && shogiDropsCheckbox.checked) {
        options.push('Drops');
    }
    
    if (options.length > 0) {
        defaultName += ` (${options.join(', ')})`;
    }
    
    console.log('Generated name:', defaultName);
    gameNameField.value = defaultName;
    console.log('Room name field updated to:', gameNameField.value);
}

function resetToEnhancedDefaultName() {
    const gameNameField = document.getElementById('game_name');
    if (!gameNameField) return;
    
    gameNameField.value = '';
    updateEnhancedDefaultGameName();
}

function showValidationError(message) {
    console.error('Validation error:', message);
    
    // Remove existing error
    const existing = document.querySelector('.validation-error');
    if (existing) existing.remove();
    
    // Create new error element
    const div = document.createElement('div');
    div.className = 'validation-error';
    div.style.cssText = `
        background: rgba(231, 76, 60, 0.2);
        border: 2px solid #e74c3c;
        border-radius: 8px;
        padding: 12px;
        margin: 10px 0;
        color: #fff;
        font-size: 14px;
        text-align: center;
        animation: errorShake 0.5s ease-in-out;
    `;
    div.textContent = message;
    
    // Insert before submit button
    const form = document.querySelector('form');
    const submitBtn = form?.querySelector('button[type="submit"]');
    if (form && submitBtn) {
        form.insertBefore(div, submitBtn);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (div.parentElement) div.remove();
        }, 5000);
    }
}

function createEnhancedGame(event) {
    event.preventDefault();
    console.log('🎮 Creating enhanced game...');
    
    const formData = new FormData(event.target);
    const boardSize = parseInt(formData.get('board_size'));
    const playerCount = parseInt(formData.get('player_count'));
    const gameType = formData.get('game_type');
    
    // Validation
    if (gameType !== 'vs_ai') {
        const limits = BOARD_PLAYER_LIMITS[boardSize];
        if (limits && playerCount > limits.max) {
            showValidationError(`${limits.name} board supports maximum ${limits.max} players. Selected: ${playerCount} players.`);
            return;
        }
    }
    
    const payload = {
        action: 'create_game',
        room_name: formData.get('room_name') || `${playerCount}-Player Game`,
        player_count: formData.get('player_count'),
        board_size: formData.get('board_size'),
        ai_difficulty: formData.get('ai_difficulty') || 'player',
        game_type: formData.get('game_type'),
        turn_timer: formData.get('turn_timer') || '30',
        skip_action: formData.get('skip_action') || 'skip_turn',
        fog_of_war: formData.get('fog_of_war') ? '1' : '0',
        shogi_drops: formData.get('shogi_drops') ? '1' : '0'
    };
    
    console.log('📤 Sending payload:', payload);
    
    const btn = event.target.querySelector('button[type="submit"]');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Creating...';
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload)
    })
    .then(response => response.json())
    .then(data => {
        console.log('📥 Server response:', data);
        
        if (data.success) {
            window.location.href = `?page=game&id=${data.game_id}`;
        } else {
            showValidationError(data.error || 'Failed to create game');
        }
    })
    .catch(error => {
        console.error('❌ Network error:', error);
        showValidationError('Network error: Could not connect to server');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = originalText;
    });
}

// Make functions globally available
window.updateEnhancedGameModeOptions = updateEnhancedGameModeOptions;
window.updatePlayerOptions = updatePlayerOptions;
window.updateTimerVisibility = updateTimerVisibility;
window.updateEnhancedDefaultGameName = updateEnhancedDefaultGameName;
window.resetToEnhancedDefaultName = resetToEnhancedDefaultName;
window.createEnhancedGame = createEnhancedGame;

// MAIN INITIALIZATION - No conflicts
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Initializing lobby functionality...');
    
    // Get all form elements
    const gameTypeSelect = document.getElementById('game_type');
    const playerCountSelect = document.getElementById('player_count');
    const boardSizeSelect = document.getElementById('board_size');
    const aiDifficultySelect = document.getElementById('ai_difficulty');
    const fogOfWarCheckbox = document.getElementById('fog_of_war');
    const shogiDropsCheckbox = document.getElementById('shogi_drops');

    // Attach event listeners directly (don't replace elements)
    if (gameTypeSelect) {
        gameTypeSelect.onchange = null; // Remove inline handler
        gameTypeSelect.addEventListener('change', updateEnhancedGameModeOptions);
        console.log('✓ Game type listener attached');
    }
    
    if (playerCountSelect) {
        playerCountSelect.onchange = null;
        playerCountSelect.addEventListener('change', function() {
            console.log('🔄 Player count changed to:', this.value);
            updateTimerVisibility();
            updateEnhancedDefaultGameName();
        });
        console.log('✓ Player count listener attached');
    }
    
    if (boardSizeSelect) {
        boardSizeSelect.onchange = null;
        boardSizeSelect.addEventListener('change', function() {
            console.log('🔄 Board size changed to:', this.value);
            updatePlayerOptions();
        });
        console.log('✓ Board size listener attached');
    }
    
    if (aiDifficultySelect) {
        aiDifficultySelect.onchange = null;
        aiDifficultySelect.addEventListener('change', function() {
            console.log('🔄 AI difficulty changed to:', this.value);
            updateEnhancedDefaultGameName();
        });
        console.log('✓ AI difficulty listener attached');
    }
    
    if (fogOfWarCheckbox) {
        fogOfWarCheckbox.addEventListener('change', function() {
            console.log('🌫️ Fog of War changed to:', this.checked);
            updateEnhancedDefaultGameName();
        });
        console.log('✓ Fog of War listener attached');
    }

    if (shogiDropsCheckbox) {
        shogiDropsCheckbox.addEventListener('change', function() {
            console.log('♻️ Shogi Drops changed to:', this.checked);
            updateEnhancedDefaultGameName();
        });
        console.log('✓ Shogi Drops listener attached');
    }

    // Initialize form state
    updateEnhancedGameModeOptions();
    
    // Force initial room name update after short delay
    setTimeout(() => {
        updateEnhancedDefaultGameName();
        console.log('✅ Initial room name set');
    }, 200);
    
    console.log('✅ Lobby initialization complete!');
});
}
</script>
<?php endif; ?>
<!-- END LOBBY -->


  <!-- Core JS bundle -->
  <script src="assets/game.js?v=71"></script>

  <script>
    // Page bootstrap
    <?php if (!$isLoggedIn && $page !== 'demo'): ?>
      showAuth('login');
    <?php elseif ($page === 'lobby'): ?>
      loadAvailableGames();
      if (document.getElementById('game_name')) { updateEnhancedDefaultGameName(); }
    <?php endif; ?>
  </script>


</body>
</html>
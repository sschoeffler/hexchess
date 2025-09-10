<?php
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Clear any output that might interfere with JSON
ob_clean();

require_once '../config/database.php';
require_once '../classes/GameManager.php';
require_once '../classes/User.php';
require_once '../classes/HexChess.php';
require_once '../utils/render.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Unknown action'];

try {
    $pdo = getDBConnection();
    $gameManager = new GameManager($pdo);
    
    switch ($action) {
        case 'test_db':
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM games");
                $count = $stmt->fetchColumn();
                $response = [
                    'success' => true,
                    'message' => "Database connected successfully. Found {$count} games.",
                    'game_count' => $count
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Database error: ' . $e->getMessage()
                ];
            }
            break;
            
        case 'get_available_games':
            try {
                $games = $gameManager->getAvailableGames();
                $response = [
                    'success' => true,
                    'games' => $games,
                    'count' => count($games)
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Failed to load games: ' . $e->getMessage(),
                    'error_details' => $e->getTraceAsString()
                ];
            }
            break;
            
        case 'join_game':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => 'Please log in to join games'];
                break;
            }
            
            $gameId = $_POST['game_id'] ?? '';
            $userId = $_SESSION['user_id'];
            
            if (empty($gameId)) {
                $response = ['success' => false, 'message' => 'Game ID required'];
                break;
            }
            
            try {
                $result = $gameManager->joinGame($gameId, $userId);
                if ($result) {
                    $response = [
                        'success' => true,
                        'message' => 'Successfully joined game!',
                        'game_id' => $gameId
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Could not join game (full or not found)'];
                }
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Error joining game: ' . $e->getMessage()
                ];
            }
            break;
            
        case 'create_game':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => 'Please log in to create games'];
                break;
            }
            
            $gameName = $_POST['game_name'] ?? '';
            $playerCount = (int)($_POST['player_count'] ?? 2);
            $boardSize = (int)($_POST['board_size'] ?? 8);
            $userId = $_SESSION['user_id'];
            
            // Validate inputs
            if (empty($gameName)) {
                $response = ['success' => false, 'message' => 'Game name is required'];
                break;
            }
            
            if ($playerCount < 2 || $playerCount > 4) {
                $response = ['success' => false, 'message' => 'Player count must be 2-4'];
                break;
            }
            
            if ($boardSize < 6 || $boardSize > 12) {
                $response = ['success' => false, 'message' => 'Board size must be 6-12'];
                break;
            }
            
            try {
                $gameId = $gameManager->createGame($userId, $gameName, $playerCount, $boardSize);
                $response = [
                    'success' => true,
                    'message' => 'Game created successfully!',
                    'game_id' => $gameId
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Error creating game: ' . $e->getMessage()
                ];
            }
            break;
            
case 'register':
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';
    
    // Clear any output that might cause HTML errors
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        // Validate input
        if (strlen($username) < 3) {
            echo json_encode(['success' => false, 'error' => 'Username must be at least 3 characters']);
            exit;
        }
        
        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
            exit;
        }
        
        // Create user (assuming you have a User class method)
        $user = new User($pdo);
        $result = $user->createUser($username, $password, $email);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Registration successful']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Username already exists']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Registration failed: ' . $e->getMessage()]);
    }
    exit; // CRITICAL: Must exit here
    break;
    
            case 'get_user_games':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => 'Please log in'];
                break;
            }
            
            try {
                $games = $gameManager->getUserGames($_SESSION['user_id']);
                $response = [
                    'success' => true,
                    'games' => $games,
                    'count' => count($games)
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Error loading your games: ' . $e->getMessage()
                ];
            }
            break;

        case 'get_game_state':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => 'Please log in'];
                break;
            }
            
            $gameId = $_POST['game_id'] ?? $_GET['game_id'] ?? '';
            if (empty($gameId)) {
                $response = ['success' => false, 'message' => 'Game ID required'];
                break;
            }
            
            try {
                $gameData = $gameManager->getGame($gameId);
                if (!$gameData) {
                    $response = ['success' => false, 'message' => 'Game not found'];
                    break;
                }
                
                $game = $gameData['game'];
                $boardHtml = renderGameBoard($game);
                
                $response = [
                    'success' => true,
                    'game_status' => $gameData['data']['status'],
                    'current_player' => $game->getCurrentPlayer(),
                    'board_html' => $boardHtml,
                    'game_info' => [
                        'name' => $gameData['data']['game_name'],
                        'player_count' => $gameData['data']['player_count'],
                        'board_size' => $gameData['data']['board_size'],
                        'status' => $gameData['data']['status']
                    ]
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Error loading game: ' . $e->getMessage()
                ];
            }
            break;


//old again
        case 'make_move':
            if (!isset($_SESSION['user_id'])) {
                $response = ['success' => false, 'message' => 'Please log in'];
                break;
            }
            
            $gameId = $_POST['game_id'] ?? '';
            $fromQ = (int)($_POST['from_q'] ?? 0);
            $fromR = (int)($_POST['from_r'] ?? 0);
            $toQ = (int)($_POST['to_q'] ?? 0);
            $toR = (int)($_POST['to_r'] ?? 0);
            $userId = $_SESSION['user_id'];
            
            try {
                $gameData = $gameManager->getGame($gameId);
                if (!$gameData) {
                    $response = ['success' => false, 'message' => 'Game not found'];
                    break;
                }
                
                $game = $gameData['game'];
                
                // Check if it's this player's turn
                $currentPlayer = $game->getCurrentPlayer();
                $playerUsers = [];
                foreach ($gameData['players'] as $player) {
                    $playerUsers[$player['player_slot']] = $player['user_id'];
                }
                
                if (!isset($playerUsers[$currentPlayer]) || $playerUsers[$currentPlayer] != $userId) {
                    $response = ['success' => false, 'message' => 'Not your turn'];
                    break;
                }
                
                // Attempt the move
                $result = $game->makeMove($fromQ, $fromR, $toQ, $toR);
                
                if ($result['success']) {
                    // Save the updated game state
                    $gameManager->saveGame($gameId, $game);
                    
                    // Check if game is over
                    if ($game->isGameOver()) {
                        $winner = $game->getWinner();
                        $winnerId = $winner !== null ? $playerUsers[$winner] : null;
                        $gameManager->finishGame($gameId, $winnerId);
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => $result['message'],
                        'board_html' => renderGameBoard($game),
                        'current_player' => $game->getCurrentPlayer(),
                        'game_over' => $game->isGameOver(),
                        'winner' => $game->getWinner()
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'message' => $result['message']
                    ];
                }
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Error making move: ' . $e->getMessage()
                ];
            }
            break;

        case 'show_demo_piece':
            $pieceType = $_POST['piece_type'] ?? 'pawn';
            
            try {
                $demoGame = new HexChess('demo', 2, 8);
                $demoBoard = renderDemoBoard($demoGame, $pieceType);
                
                // Get a sample position for the piece
                $piecePosition = null;
                for ($q = -4; $q <= 4; $q++) {
                    for ($r = max(-4, -$q-4); $r <= min(4, -$q+4); $r++) {
                        $piece = $demoGame->getPieceAt($q, $r);
                        if ($piece && $piece->getType() === $pieceType && $piece->getColor() === 0) {
                            $piecePosition = ['q' => $q, 'r' => $r];
                            break 2;
                        }
                    }
                }
                
                $response = [
                    'success' => true,
                    'boardHtml' => $demoBoard,
                    'piecePosition' => $piecePosition
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'message' => 'Error showing demo: ' . $e->getMessage()
                ];
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
    }
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'error_details' => $e->getTraceAsString()
    ];
}

echo json_encode($response);
exit;
?>
<?php
// trace_index.php - Step through actual index.php execution to find crash
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<h2>🚨 FATAL ERROR</h2>";
        echo "<p><strong>Error:</strong> " . $error['message'] . "</p>";
        echo "<p><strong>File:</strong> " . $error['file'] . "</p>";
        echo "<p><strong>Line:</strong> " . $error['line'] . "</p>";
    }
});

function debugEcho($message) {
    echo "<p style='background: #f0f0f0; padding: 5px; margin: 2px; font-family: monospace;'>$message</p>";
    flush();
}

echo "<h1>🔍 Tracing Real index.php Execution</h1>";

try {
    debugEcho("✅ Starting session...");
    session_start();
    
    debugEcho("✅ Loading includes...");
    require_once 'config/database.php';
    require_once 'classes/Piece.php';
    require_once 'classes/User.php';
    require_once 'classes/GameManager.php';
    require_once 'classes/BaseChess.php';
    require_once 'classes/HexChess_v2.php';
    require_once 'classes/HexChessAI.php';
    require_once 'utils/render.php';
    
    debugEcho("✅ Creating core objects...");
    $user = new User($pdo);
    $gameManager = new GameManager($pdo);
    
    debugEcho("✅ Getting page parameter...");
    $page = $_GET['page'] ?? 'lobby';
    debugEcho("📍 Page: $page");
    
    // Initialize game variables for header display (like index.php does)
    debugEcho("✅ Initializing game variables...");
    $gameData = null;
    $game = null;
    $players = null;
    
    // Load game data if we're on a game page (like index.php does)
    if ($page === 'game' && !empty($_GET['id'])) {
        debugEcho("🔄 Loading game data...");
        $gameId = $_GET['id'];
        debugEcho("📍 Game ID: $gameId");
        
        $gameInfo = $gameManager->getGame($gameId);
        debugEcho("📍 getGame() result: " . (is_array($gameInfo) ? "array with " . count($gameInfo) . " keys" : gettype($gameInfo)));
        
        if ($gameInfo) {
            $game = $gameInfo['game'];
            $players = $gameInfo['players'];
            $gameData = $gameInfo['data'];
            debugEcho("✅ Game data loaded successfully");
            
            // Test that the game object works
            if ($game && method_exists($game, 'getBoard')) {
                debugEcho("🔄 Testing game->getBoard()...");
                $board = $game->getBoard();
                debugEcho("✅ getBoard() successful: " . (is_array($board) ? count($board) . " columns" : gettype($board)));
            }
            
            if ($game && method_exists($game, 'getGameState')) {
                debugEcho("🔄 Testing game->getGameState()...");
                $gameState = $game->getGameState();
                debugEcho("✅ getGameState() successful: " . (is_array($gameState) ? count($gameState) . " keys" : gettype($gameState)));
            }
        }
    } else {
        debugEcho("ℹ️ Not a game page or no game ID");
    }
    
    // Test database update function (index.php has this)
    debugEcho("🔄 Testing database update function...");
    function updateDatabaseForFogOfWar($pdo) {
        try {
            // Check if fog_of_war column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'fog_of_war'");
            if ($stmt->rowCount() == 0) {
                debugEcho("ℹ️ fog_of_war column missing (this might cause issues)");
            } else {
                debugEcho("✅ fog_of_war column exists");
            }
            
            // Check if shogi_drops column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'shogi_drops'");
            if ($stmt->rowCount() == 0) {
                debugEcho("ℹ️ shogi_drops column missing (this might cause issues)");
            } else {
                debugEcho("✅ shogi_drops column exists");
            }
            
            return true;
        } catch (Exception $e) {
            debugEcho("❌ Database update error: " . $e->getMessage());
            return false;
        }
    }
    
    updateDatabaseForFogOfWar($pdo);
    
    // Simulate POST request handling (major crash source)
    debugEcho("🔄 Testing POST request handling...");
    
    if (!empty($_POST)) {
        debugEcho("📍 POST data detected: " . json_encode($_POST));
        
        // Test common POST actions that might crash
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            debugEcho("📍 POST action: $action");
            
            switch ($action) {
                case 'create_game':
                    debugEcho("🔄 Testing create_game action...");
                    // This might be where it crashes
                    break;
                    
                case 'join_game':
                    debugEcho("🔄 Testing join_game action...");
                    break;
                    
                case 'make_move':
                    debugEcho("🔄 Testing make_move action...");
                    break;
                    
                default:
                    debugEcho("ℹ️ Unknown POST action: $action");
            }
        }
    } else {
        debugEcho("ℹ️ No POST data (normal for page loads)");
    }
    
    // Test header rendering (common crash point)
    debugEcho("🔄 Testing header template logic...");
    
    // Simulate what index.php does for header
    $headerData = [
        'user' => $user,
        'page' => $page,
        'game' => $game,
        'gameData' => $gameData,
        'players' => $players
    ];
    
    debugEcho("✅ Header data prepared");
    
    // Test page-specific logic
    debugEcho("🔄 Testing page-specific logic...");
    
    switch ($page) {
        case 'lobby':
            debugEcho("📍 Processing lobby page...");
            // Test getting available games
            $availableGames = $gameManager->getAvailableGames();
            debugEcho("✅ Available games loaded: " . (is_array($availableGames) ? count($availableGames) . " games" : gettype($availableGames)));
            break;
            
        case 'game':
            debugEcho("📍 Processing game page...");
            if ($game) {
                debugEcho("✅ Game object available for rendering");
            } else {
                debugEcho("⚠️ No game object for game page");
            }
            break;
            
        default:
            debugEcho("📍 Processing $page page...");
    }
    
    debugEcho("🎉 All index.php logic simulation successful!");
    debugEcho("💡 The crash must be in template rendering or specific POST actions not covered here.");
    
    echo "<h2>🛠️ Next Debugging Steps</h2>";
    echo "<ul>";
    echo "<li>Try: <a href='index.php?page=lobby'>index.php?page=lobby</a></li>";
    echo "<li>If that works, try creating a game through the UI</li>";
    echo "<li>The crash might be in HTML template rendering</li>";
    echo "<li>Or in specific POST action handlers</li>";
    echo "</ul>";
    
} catch (Error $e) {
    echo "<h2>🚨 FATAL ERROR CAUGHT</h2>";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
} catch (Exception $e) {
    echo "<h2>🚨 EXCEPTION CAUGHT</h2>";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
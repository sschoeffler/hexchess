<?php
// error_viewer.php - Create this file to easily view PHP errors in your browser

session_start();

// Only allow logged-in users to view errors (security)
if (!isset($_SESSION['user_id'])) {
    die("Login required to view error log");
}

echo "<h1>🚨 Live Error Viewer</h1>";
echo "<p><a href='?' onclick='location.reload()'>🔄 Refresh</a> | <a href='?page=lobby'>← Back to Lobby</a></p>";

// Function to capture errors in real-time
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/debug.log';
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Start capturing errors for this session
set_error_handler(function($severity, $message, $file, $line) {
    $error = "ERROR: $message in $file on line $line";
    logError($error);
    echo "<div style='background: #fee; border: 1px solid #f00; padding: 10px; margin: 5px 0; border-radius: 5px;'>";
    echo "<strong>⚠️ PHP Error:</strong> $error";
    echo "</div>";
    return true;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] == E_ERROR) {
        $msg = "FATAL: {$error['message']} in {$error['file']} on line {$error['line']}";
        logError($msg);
        echo "<div style='background: #fdd; border: 2px solid #d00; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<strong>💥 FATAL ERROR:</strong> $msg";
        echo "</div>";
    }
});

echo "<h2>🧪 Test AI Game Creation</h2>";

if (isset($_POST['test_ai'])) {
    echo "<div style='background: #eff; border: 1px solid #00f; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
    echo "<strong>🎮 Testing AI Game Creation...</strong><br>";
    
    try {
        require_once 'config/database.php';
        require_once 'classes/Piece.php';          // <-- MISSING! Add this
        require_once 'classes/User.php';
        require_once 'classes/GameManager.php';
        require_once 'classes/HexChess.php';
        require_once 'classes/HexChessAI.php';
        
        echo "✅ All classes loaded<br>";
        
        $gameManager = new GameManager($pdo);
        echo "✅ GameManager created<br>";
        
        $gameId = $gameManager->createGame(
            $_SESSION['user_id'], 
            'Error Test AI Game', 
            2, 
            7, 
            'ai', 
            'medium'
        );
        
        if ($gameId) {
            echo "✅ <strong>SUCCESS!</strong> AI Game created: $gameId<br>";
            echo "<a href='?page=game&id=$gameId' target='_blank'>🎮 Open Game</a>";
        } else {
            echo "❌ <strong>FAILED:</strong> createGame returned false";
        }
        
    } catch (Exception $e) {
        echo "💥 <strong>EXCEPTION:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "<strong>File:</strong> " . $e->getFile() . "<br>";
        echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    }
    echo "</div>";
}

?>

<form method="post" style="margin: 20px 0;">
    <button type="submit" name="test_ai" value="1" style="background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
        🚀 Test AI Game Creation
    </button>
</form>

<h2>📋 Recent Debug Log</h2>
<?php
$debugLog = __DIR__ . '/debug.log';
if (file_exists($debugLog)) {
    $logContent = file_get_contents($debugLog);
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;'>";
    echo htmlspecialchars($logContent);
    echo "</pre>";
    
    echo "<form method='post' style='margin: 10px 0;'>";
    echo "<button type='submit' name='clear_log' style='background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px;'>🗑️ Clear Log</button>";
    echo "</form>";
    
    if (isset($_POST['clear_log'])) {
        file_put_contents($debugLog, '');
        echo "<p style='color: green;'>✅ Log cleared!</p>";
        echo "<meta http-equiv='refresh' content='1'>";
    }
} else {
    echo "<p>No debug log yet. Create an AI game to generate log entries.</p>";
}
?>
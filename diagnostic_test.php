<?php
// diagnostic_test.php - Comprehensive debugging for refactoring issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>HexChess Refactoring Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f0f0f0; }
        .test-section { background: white; margin: 10px 0; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .test-title { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px; margin-bottom: 10px; }
        .success { color: #27ae60; font-weight: bold; }
        .error { color: #e74c3c; font-weight: bold; background: #fdf2f2; padding: 3px; }
        .warning { color: #f39c12; font-weight: bold; }
        .info { color: #2980b9; }
        .code { background: #f8f9fa; border: 1px solid #e9ecef; padding: 10px; margin: 5px 0; border-radius: 3px; font-family: monospace; }
        pre { margin: 0; }
    </style>
</head>
<body>";

function testSection($title, $callback) {
    echo "<div class='test-section'>";
    echo "<h3 class='test-title'>$title</h3>";
    
    try {
        $result = $callback();
        if ($result === true) {
            echo "<span class='success'>✅ PASSED</span>";
        } else {
            echo "<div class='info'>$result</div>";
        }
    } catch (Exception $e) {
        echo "<span class='error'>❌ FAILED: " . $e->getMessage() . "</span>";
        echo "<div class='code'><pre>" . $e->getTraceAsString() . "</pre></div>";
    } catch (Error $e) {
        echo "<span class='error'>❌ FATAL ERROR: " . $e->getMessage() . "</span>";
        echo "<div class='code'><pre>File: " . $e->getFile() . " Line: " . $e->getLine() . "</pre></div>";
    }
    
    echo "</div>";
}

echo "<h1>🔍 HexChess Refactoring Diagnostic</h1>";

// Test 1: File Existence
testSection("1. File Existence Check", function() {
    $files = [
        'BaseChess.php' => 'classes/BaseChess.php',
        'HexChess_v2.php' => 'classes/HexChess_v2.php', 
        'Piece.php' => 'classes/Piece.php'
    ];
    
    $results = [];
    foreach ($files as $name => $path) {
        if (file_exists($path)) {
            $results[] = "<span class='success'>✅ $name found at $path</span>";
        } else {
            // Try alternative paths
            $altPaths = [$name, "classes/$name", "../classes/$name"];
            $found = false;
            foreach ($altPaths as $altPath) {
                if (file_exists($altPath)) {
                    $results[] = "<span class='warning'>⚠️ $name found at $altPath (not expected path)</span>";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $results[] = "<span class='error'>❌ $name not found</span>";
            }
        }
    }
    
    return implode("<br>", $results);
});

// Test 2: BaseChess Loading
testSection("2. BaseChess Class Loading", function() {
    // Try multiple include paths
    $basePaths = ['classes/BaseChess.php', 'BaseChess.php', '../classes/BaseChess.php'];
    $loaded = false;
    
    foreach ($basePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $loaded = true;
            break;
        }
    }
    
    if (!$loaded) {
        throw new Exception("BaseChess.php not found in any expected location");
    }
    
    if (!class_exists('BaseChess')) {
        throw new Exception("BaseChess class not defined after include");
    }
    
    // Check if it's abstract
    $reflection = new ReflectionClass('BaseChess');
    if (!$reflection->isAbstract()) {
        throw new Exception("BaseChess should be abstract but isn't");
    }
    
    // List abstract methods
    $abstractMethods = [];
    foreach ($reflection->getMethods() as $method) {
        if ($method->isAbstract()) {
            $abstractMethods[] = $method->getName();
        }
    }
    
    return "BaseChess loaded successfully<br>Abstract methods: " . implode(", ", $abstractMethods);
});

// Test 3: Piece Class Loading
testSection("3. Piece Class Loading", function() {
    $piecePaths = ['classes/Piece.php', 'Piece.php', '../classes/Piece.php'];
    $loaded = false;
    
    foreach ($piecePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $loaded = true;
            break;
        }
    }
    
    if (!$loaded) {
        throw new Exception("Piece.php not found in any expected location");
    }
    
    if (!class_exists('Piece')) {
        throw new Exception("Piece class not defined after include");
    }
    
    // Test piece creation
    $piece = new Piece('king', 0);
    return "Piece class loaded successfully<br>Test piece: {$piece->type}, player {$piece->player}";
});

// Test 4: HexChess_v2 Loading
testSection("4. HexChess_v2 Class Loading", function() {
    $hexPaths = ['classes/HexChess_v2.php', 'HexChess_v2.php', '../classes/HexChess_v2.php'];
    $loaded = false;
    
    foreach ($hexPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $loaded = true;
            break;
        }
    }
    
    if (!$loaded) {
        throw new Exception("HexChess_v2.php not found in any expected location");
    }
    
    if (!class_exists('HexChess')) {
        throw new Exception("HexChess class not defined after include");
    }
    
    // Check inheritance
    $reflection = new ReflectionClass('HexChess');
    $parent = $reflection->getParentClass();
    
    if (!$parent || $parent->getName() !== 'BaseChess') {
        throw new Exception("HexChess does not extend BaseChess properly");
    }
    
    return "HexChess_v2 loaded successfully<br>Extends: " . $parent->getName();
});

// Test 5: Basic Game Creation
testSection("5. Basic Game Creation", function() {
    try {
        $game = new HexChess('test-game', 2, 8, false);
        return "HexChess instance created successfully<br>Game ID: " . $game->getGameId() . "<br>Player count: " . $game->getPlayerCount();
    } catch (ArgumentCountError $e) {
        throw new Exception("Constructor parameter mismatch: " . $e->getMessage());
    }
});

// Test 6: Method Availability
testSection("6. Required Method Check", function() {
    $game = new HexChess('test-game', 2, 8, false);
    
    $requiredMethods = [
        'getBoard', 'getPiece', 'isValidMove', 'getValidMoves', 
        'movePiece', 'isGameOver', 'getCurrentPlayerSlot'
    ];
    
    $results = [];
    foreach ($requiredMethods as $method) {
        if (method_exists($game, $method)) {
            $results[] = "<span class='success'>✅ $method()</span>";
        } else {
            $results[] = "<span class='error'>❌ $method() missing</span>";
        }
    }
    
    return implode("<br>", $results);
});

// Test 7: Board Initialization
testSection("7. Board Initialization", function() {
    $game = new HexChess('test-game', 2, 8, false);
    $board = $game->getBoard();
    
    if (!is_array($board)) {
        throw new Exception("getBoard() did not return an array");
    }
    
    $pieceCount = 0;
    foreach ($board as $q => $column) {
        foreach ($column as $r => $piece) {
            if ($piece !== null) {
                $pieceCount++;
            }
        }
    }
    
    return "Board initialized successfully<br>Pieces placed: $pieceCount<br>Board structure: " . count($board) . " columns";
});

// Test 8: Piece Movement Test
testSection("8. Basic Piece Movement", function() {
    $game = new HexChess('test-game', 2, 8, false);
    
    // Find a piece to test with
    $board = $game->getBoard();
    $testPiece = null;
    $testPos = null;
    
    foreach ($board as $q => $column) {
        foreach ($column as $r => $piece) {
            if ($piece !== null) {
                $testPiece = $piece;
                $testPos = ['q' => $q, 'r' => $r];
                break 2;
            }
        }
    }
    
    if (!$testPiece) {
        throw new Exception("No pieces found on board");
    }
    
    $validMoves = $game->getValidMoves($testPos['q'], $testPos['r']);
    
    return "Found test piece: {$testPiece->type} at ({$testPos['q']}, {$testPos['r']})<br>Valid moves: " . count($validMoves);
});

// Test 9: Feature System
testSection("9. Feature System Test", function() {
    $game = new HexChess('test-game', 2, 8, true); // Enable fog of war
    
    $fogEnabled = $game->isFogOfWarEnabled();
    $shogiEnabled = $game->isShogiDropsEnabled();
    
    $game->enableShogiDrops();
    $shogiEnabledAfter = $game->isShogiDropsEnabled();
    
    return "Fog of War: " . ($fogEnabled ? "✅ Enabled" : "❌ Disabled") . "<br>" .
           "Shogi Drops: " . ($shogiEnabled ? "✅ Initially Enabled" : "❌ Initially Disabled") . 
           " → " . ($shogiEnabledAfter ? "✅ Enabled After" : "❌ Still Disabled");
});

// Test 10: Game State
testSection("10. Game State Test", function() {
    $game = new HexChess('test-game', 2, 8, false);
    $gameState = $game->getGameState();
    
    $expectedKeys = ['currentPlayer', 'playerCount', 'gameStatus', 'moveCount', 'features'];
    $results = [];
    
    foreach ($expectedKeys as $key) {
        if (array_key_exists($key, $gameState)) {
            $results[] = "<span class='success'>✅ $key</span>";
        } else {
            $results[] = "<span class='error'>❌ $key missing</span>";
        }
    }
    
    return "Game State Keys: " . implode(", ", $results) . "<br>Current Player: " . $gameState['currentPlayer'];
});

echo "<div class='test-section'>";
echo "<h3 class='test-title'>🏁 Test Summary</h3>";
echo "<p>If all tests pass, your refactoring is working correctly!</p>";
echo "<p>If any tests fail, the error details above will help identify the issue.</p>";
echo "</div>";

echo "</body></html>";
?>
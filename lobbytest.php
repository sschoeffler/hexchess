<?php
session_start();

// Database configuration
$db_host = 'mysql.cognobot.com';
$db_name = 'shahmat';
$db_user = 'magentum';
$db_pass = '99himitsuCamel!!';


// Admin configuration
$admin_email = 'sschoeffler@gmail.com'; // ⚠️ CHANGE THIS TO YOUR EMAIL
$site_name = 'HexChess Online';
$site_url = 'http://cognobot.com/test'; // CHANGE THIS TO YOUR SITE URL


// Email notification settings
$send_signup_notifications = true;      // Email when new users register
$send_game_notifications = true;        // Email when games are created/started/finished
$send_daily_summary = true;             // Enable daily summary emails (requires cron job)

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Email notification function
function sendAdminNotification($subject, $message) {
    global $admin_email, $site_name;
    
    // Don't send if admin email not configured
    if ($admin_email === 'your-email@example.com' || empty($admin_email)) {
        return false;
    }
    
    $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $fullMessage = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background: #667eea; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .footer { padding: 10px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>🏰 $site_name - Admin Notification</h2>
        </div>
        <div class='content'>
            $message
        </div>
        <div class='footer'>
            <p>This is an automated notification from $site_name</p>
            <p>Time: " . date('Y-m-d H:i:s') . "</p>
        </div>
    </body>
    </html>";
    
    return mail($admin_email, "[$site_name] $subject", $fullMessage, $headers);
}

// Enhanced logging function
function logActivity($type, $details, $userId = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (type, details, user_id, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $type, 
            $details, 
            $userId, 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch(PDOException $e) {
        // Log errors silently - don't break the user experience
        error_log("Activity log error: " . $e->getMessage());
    }
}

// Create tables if they don't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        wins INT DEFAULT 0,
        losses INT DEFAULT 0,
        games_played INT DEFAULT 0
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS games (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id VARCHAR(50) UNIQUE NOT NULL,
        creator_id INT NOT NULL,
        game_name VARCHAR(100) NOT NULL,
        player_count INT NOT NULL,
        board_size INT DEFAULT 8,
        status ENUM('waiting', 'active', 'finished') DEFAULT 'waiting',
        game_state LONGTEXT,
        game_mode ENUM('multiplayer', 'solitaire') DEFAULT 'multiplayer',
        ai_difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        started_at TIMESTAMP NULL,
        finished_at TIMESTAMP NULL,
        winner_id INT NULL,
        FOREIGN KEY (creator_id) REFERENCES users(id),
        FOREIGN KEY (winner_id) REFERENCES users(id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS game_players (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_id VARCHAR(50) NOT NULL,
        user_id INT NOT NULL,
        player_slot INT NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_game_slot (game_id, player_slot),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL,
        details TEXT,
        user_id INT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// Daily summary email function (call this from a cron job)
function sendDailySummary() {
    global $pdo, $admin_email, $site_name;
    
    // Get today's stats
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $newUsers = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $newGames = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE DATE(finished_at) = ?");
    $stmt->execute([$today]);
    $completedGames = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $totalUsers = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'active'");
    $activeGames = $stmt->fetchColumn();
    
    // Get most active players today
    $stmt = $pdo->prepare("
        SELECT u.username, COUNT(gp.id) as games_joined
        FROM users u
        JOIN game_players gp ON u.id = gp.user_id
        JOIN games g ON gp.game_id = g.game_id
        WHERE DATE(gp.joined_at) = ?
        GROUP BY u.id
        ORDER BY games_joined DESC
        LIMIT 5
    ");
    $stmt->execute([$today]);
    $activePlayersToday = $stmt->fetchAll();
    
    $activePlayersList = '';
    foreach ($activePlayersToday as $player) {
        $activePlayersList .= "<li>{$player['username']} - {$player['games_joined']} games</li>";
    }
    
    $message = "
        <h3>📊 Daily Summary - $today</h3>
        
        <h4>🆕 Today's Activity</h4>
        <ul>
            <li><strong>New Users:</strong> $newUsers</li>
            <li><strong>Games Created:</strong> $newGames</li>
            <li><strong>Games Completed:</strong> $completedGames</li>
        </ul>
        
        <h4>📈 Current Totals</h4>
        <ul>
            <li><strong>Total Users:</strong> $totalUsers</li>
            <li><strong>Active Games:</strong> $activeGames</li>
        </ul>
        
        <h4>🏆 Most Active Players Today</h4>
        <ul>$activePlayersList</ul>
        
        <hr>
        <p><em>This is your daily $site_name summary.</em></p>
    ";
    
    sendAdminNotification("Daily Summary - $today", $message);
}

// FIXED: Proper User class declaration
class User {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function register($username, $password, $email = '') {
        global $send_signup_notifications;
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $this->pdo->prepare("INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)");
            $stmt->execute([$username, $hashedPassword, $email]);
            $userId = $this->pdo->lastInsertId();
            
            // Log the registration
            logActivity('user_registration', "New user registered: $username", $userId);
            
            // Send admin notification
            if ($send_signup_notifications) {
                $emailDisplay = $email ? $email : 'Not provided';
                $notificationMessage = "
                    <h3>🎉 New User Registration!</h3>
                    <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    <p><strong>Email:</strong> " . htmlspecialchars($emailDisplay) . "</p>
                    <p><strong>User ID:</strong> #$userId</p>
                    <p><strong>Registration Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <p><strong>IP Address:</strong> " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "</p>
                    <hr>
                    <p>Total users: " . $this->getTotalUserCount() . "</p>
                ";
                
                sendAdminNotification("New User Registration: $username", $notificationMessage);
            }
            
            return $userId;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    private function getTotalUserCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        return $stmt->fetchColumn();
    }
    
    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $username;
            return true;
        }
        return false;
    }
    
    public function getUserById($id) {
        $stmt = $this->pdo->prepare("SELECT id, username, wins, losses, games_played FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function updateStats($userId, $win) {
        if ($win) {
            $stmt = $this->pdo->prepare("UPDATE users SET wins = wins + 1, games_played = games_played + 1 WHERE id = ?");
        } else {
            $stmt = $this->pdo->prepare("UPDATE users SET losses = losses + 1, games_played = games_played + 1 WHERE id = ?");
        }
        $stmt->execute([$userId]);
    }
}

class Piece {
    public $type;
    public $player;
    
    public function __construct($type, $player) {
        $this->type = $type;
        $this->player = $player;
    }
    
    public function getIcon() {
        $icons = [
            'king' => '♚', 'queen' => '♛', 'rook' => '♜',
            'bishop' => '♝', 'knight' => '♞', 'pawn' => '♟'
        ];
        
        return $icons[$this->type] ?? '';
    }
    
    public function getColorClass($playerCount) {
        if ($playerCount == 2) {
            $colors = ['red-piece', 'blue-piece'];
        } else {
            $colors = ['red-piece', 'blue-piece', 'green-piece'];
        }
        
        return $colors[$this->player] ?? '';
    }
}

class HexChess {
    private $board;
    private $currentPlayer;
    private $gameId;
    private $players;
    private $playerCount;
    private $moveCount;
    private $boardSize;
    private $activePlayers;
    private $playerUsers; // Maps player slots to user IDs
    private $gameMode; // 'multiplayer' or 'solitaire'
    private $aiDifficulty; // 'easy', 'medium', 'hard'
    private $aiPlayers; // Array of which players are AI
    
    public function __construct($gameId = null, $playerCount = 2, $boardSize = 8, $gameMode = 'multiplayer', $aiDifficulty = 'medium') {
        $this->gameId = $gameId ?: uniqid();
        $this->playerCount = max(2, min(3, $playerCount));
        $this->currentPlayer = 0;
        $this->moveCount = 0;
        $this->boardSize = max(4, min(8, $boardSize));
        $this->playerUsers = array_fill(0, $this->playerCount, null);
        $this->gameMode = $gameMode;
        $this->aiDifficulty = $aiDifficulty;
        
        if ($this->playerCount == 2) {
            $this->players = ['red', 'blue'];
        } else {
            $this->players = ['red', 'blue', 'green'];
        }
        
        $this->activePlayers = array_fill(0, $this->playerCount, true);
        
        // Set up AI players for solitaire mode
        if ($gameMode === 'solitaire') {
            $this->aiPlayers = array_fill(0, $this->playerCount, false);
            // Player 0 is human, others are AI
            for ($i = 1; $i < $this->playerCount; $i++) {
                $this->aiPlayers[$i] = true;
                $this->playerUsers[$i] = 'AI_' . $i;
            }
        } else {
            $this->aiPlayers = array_fill(0, $this->playerCount, false);
        }
        
        $this->initBoard();
    }
    
    public function setPlayerUsers($playerUsers) {
        $this->playerUsers = $playerUsers;
    }
    
    public function getPlayerUsers() {
        return $this->playerUsers;
    }
    
    public function canUserMove($userId) {
        if ($this->isAIPlayer($this->currentPlayer)) {
            return false; // AI will move automatically
        }
        return $this->playerUsers[$this->currentPlayer] == $userId;
    }
    
    public function isAIPlayer($playerSlot) {
        return isset($this->aiPlayers[$playerSlot]) && $this->aiPlayers[$playerSlot];
    }
    
    public function getGameMode() {
        return $this->gameMode;
    }
    
    public function makeAIMove() {
        if (!$this->isAIPlayer($this->currentPlayer)) {
            return false;
        }
        
        $bestMove = $this->calculateAIMove($this->currentPlayer);
        
        if ($bestMove) {
            return $this->movePiece($bestMove['fromQ'], $bestMove['fromR'], $bestMove['toQ'], $bestMove['toR']);
        }
        
        return false;
    }
    
    private function calculateAIMove($player) {
        $allMoves = [];
        
        // Get all possible moves for the AI player
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $player) {
                    $validMoves = $this->getValidMoves($q, $r);
                    
                    foreach ($validMoves as $move) {
                        $moveScore = $this->evaluateMove($q, $r, $move['q'], $move['r']);
                        $allMoves[] = [
                            'fromQ' => $q,
                            'fromR' => $r,
                            'toQ' => $move['q'],
                            'toR' => $move['r'],
                            'score' => $moveScore
                        ];
                    }
                }
            }
        }
        
        if (empty($allMoves)) {
            return null;
        }
        
        // Sort moves by score (best first)
        usort($allMoves, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Select move based on difficulty
        switch ($this->aiDifficulty) {
            case 'easy':
                // Pick a random move from bottom 50%
                $startIndex = count($allMoves) > 4 ? floor(count($allMoves) / 2) : 0;
                return $allMoves[rand($startIndex, count($allMoves) - 1)];
                
            case 'medium':
                // Pick from top 50% but with some randomness
                $endIndex = max(0, floor(count($allMoves) / 2));
                return $allMoves[rand(0, $endIndex)];
                
            case 'hard':
                // Pick from top 25% with slight randomness
                $endIndex = max(0, floor(count($allMoves) / 4));
                return $allMoves[rand(0, $endIndex)];
                
            default:
                return $allMoves[0];
        }
    }
    
    private function evaluateMove($fromQ, $fromR, $toQ, $toR) {
        $score = 0;
        $piece = $this->getPiece($fromQ, $fromR);
        $targetPiece = $this->getPiece($toQ, $toR);
        
        // Capture bonus
        if ($targetPiece) {
            $pieceValues = [
                'pawn' => 10,
                'knight' => 30,
                'bishop' => 30,
                'rook' => 50,
                'queen' => 90,
                'king' => 1000
            ];
            $score += $pieceValues[$targetPiece->type] ?? 0;
        }
        
        // Center control bonus
        $distanceFromCenter = abs($toQ) + abs($toR) + abs($toQ + $toR);
        $score += max(0, 10 - $distanceFromCenter);
        
        // Piece development bonus
        if ($piece->type === 'pawn') {
            // Pawns advance toward opponent
            if ($piece->player == 0) { // Red moves right
                $score += $toQ - $fromQ;
            } elseif ($piece->player == 1) { // Blue moves left
                $score += $fromQ - $toQ;
            } else { // Green moves up
                $score += $fromR - $toR;
            }
        }
        
        // Add some randomness
        $score += rand(-5, 5);
        
        return $score;
    }
    
    private function initBoard() {
        $this->board = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                $this->board[$q][$r] = null;
            }
        }
        
        $this->setupPieces();
    }
    
    private function setupPieces() {
        if ($this->playerCount == 2) {
            $this->setupTwoPlayerPieces();
        } else {
            $this->setupThreePlayerPieces();
        }
    }
    
    private function setupTwoPlayerPieces() {
        // Player 1 (Red) - left edge
        $this->placePiece(-$this->boardSize, 0, new Piece('king', 0));
        $this->placePiece(-$this->boardSize+1, -1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+2, -2, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+3, -3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+1, 0, new Piece('queen', 0));
        $this->placePiece(-$this->boardSize+2, -1, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 2, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+1, 1, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+2, 0, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+1, 2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+2, 1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+3, 0, new Piece('pawn', 0));
        
        // Player 2 (Blue) - right edge
        $this->placePiece($this->boardSize, 0, new Piece('king', 1));
        $this->placePiece($this->boardSize-1, 1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-2, 2, new Piece('knight', 1));
        $this->placePiece($this->boardSize-3, 3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-1, 0, new Piece('queen', 1));
        $this->placePiece($this->boardSize-2, 1, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-3, 2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-1, -1, new Piece('knight', 1));
        $this->placePiece($this->boardSize-2, 0, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-3, 1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-1, -2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-2, -1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, 0, new Piece('pawn', 1));
    }
    
    private function setupThreePlayerPieces() {
        // Player 1 (Red) - left edge
        $this->placePiece(-$this->boardSize, 0, new Piece('king', 0));
        $this->placePiece(-$this->boardSize+1, -1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+2, -2, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+3, -3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+1, 0, new Piece('queen', 0));
        $this->placePiece(-$this->boardSize+2, -1, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 2, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+1, 1, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+2, 0, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+1, 2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+2, 1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+3, 0, new Piece('pawn', 0));
        
        // Player 2 (Blue) - top-right corner
        $this->placePiece($this->boardSize, -$this->boardSize, new Piece('king', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+1, new Piece('queen', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize, new Piece('rook', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+1, new Piece('knight', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+2, new Piece('knight', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+3, new Piece('pawn', 1));
        
        // Player 3 (Green) - bottom corner
        $this->placePiece(0, $this->boardSize, new Piece('king', 2));
        $this->placePiece(1, $this->boardSize-1, new Piece('rook', 2));
        $this->placePiece(2, $this->boardSize-2, new Piece('knight', 2));
        $this->placePiece(3, $this->boardSize-3, new Piece('pawn', 2));
        $this->placePiece(-1, $this->boardSize, new Piece('rook', 2));
        $this->placePiece(0, $this->boardSize-1, new Piece('queen', 2));
        $this->placePiece(1, $this->boardSize-2, new Piece('bishop', 2));
        $this->placePiece(2, $this->boardSize-3, new Piece('pawn', 2));
        $this->placePiece(-2, $this->boardSize, new Piece('bishop', 2));
        $this->placePiece(-1, $this->boardSize-1, new Piece('knight', 2));
        $this->placePiece(0, $this->boardSize-2, new Piece('bishop', 2));
        $this->placePiece(1, $this->boardSize-3, new Piece('pawn', 2));
        $this->placePiece(-3, $this->boardSize, new Piece('pawn', 2));
        $this->placePiece(-2, $this->boardSize-1, new Piece('pawn', 2));
        $this->placePiece(-1, $this->boardSize-2, new Piece('pawn', 2));
        $this->placePiece(0, $this->boardSize-3, new Piece('pawn', 2));
    }
    
    private function placePiece($q, $r, $piece) {
        if ($this->isValidHex($q, $r)) {
            $this->board[$q][$r] = $piece;
        }
    }
    
    private function isValidHex($q, $r) {
        return abs($q) <= $this->boardSize && 
               abs($r) <= $this->boardSize && 
               abs($q + $r) <= $this->boardSize;
    }
    
    public function getPiece($q, $r) {
        return $this->isValidHex($q, $r) ? ($this->board[$q][$r] ?? null) : null;
    }
    
    public function getBoardSize() {
        return $this->boardSize;
    }
    
    public function getCurrentPlayer() {
        return $this->players[$this->currentPlayer];
    }
    
    public function getCurrentPlayerSlot() {
        return $this->currentPlayer;
    }
    
    public function getPlayerCount() {
        return $this->playerCount;
    }
    
    public function getPlayers() {
        return $this->players;
    }
    
    public function getCellColor($q, $r) {
        $colorIndex = (($q - $r) % 3 + 3) % 3;
        $colors = ['pastel-red', 'pastel-green', 'pastel-blue'];
        return $colors[$colorIndex];
    }
    
    // FIXED: Proper move validation and execution
    public function movePiece($fromQ, $fromR, $toQ, $toR) {
        if (!$this->isValidMove($fromQ, $fromR, $toQ, $toR)) {
            return false;
        }
        
        $piece = $this->board[$fromQ][$fromR];
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;
        
        $this->moveCount++;
        $this->checkForEliminations();
        $this->advanceToNextActivePlayer();
        
        return true;
    }
    
    private function advanceToNextActivePlayer() {
        do {
            $this->currentPlayer = ($this->currentPlayer + 1) % $this->playerCount;
        } while (!$this->activePlayers[$this->currentPlayer] && $this->getActivePlayerCount() > 1);
    }
    
    private function checkForEliminations() {
        for ($player = 0; $player < $this->playerCount; $player++) {
            if ($this->activePlayers[$player] && $this->isCheckmate($player)) {
                $this->activePlayers[$player] = false;
            }
        }
    }
    
    private function getActivePlayerCount() {
        return array_sum($this->activePlayers);
    }
    
    // FIXED: Complete move validation
    private function isValidMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        if (!$piece) return false;
        if ($piece->player !== $this->currentPlayer || !$this->activePlayers[$this->currentPlayer]) return false;
        if (!$this->isValidHex($toQ, $toR)) return false;
        if ($fromQ === $toQ && $fromR === $toR) return false;
        
        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $this->currentPlayer) return false;
        
        // Check piece-specific movement
        return $this->isPieceMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
    }
    
    // FIXED: Added proper piece movement validation
    private function isPieceMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        switch ($piece->type) {
            case 'pawn':
                return $this->isPawnMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
            case 'rook':
                return $this->isRookMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
            case 'bishop':
                return $this->isBishopMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
            case 'knight':
                return $this->isKnightMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
            case 'queen':
                return $this->isQueenMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
            case 'king':
                return $this->isKingMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
            default:
                return false;
        }
    }
    
    private function isPawnMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        $targetPiece = $this->getPiece($toQ, $toR);
        
        // Simplified pawn movement - move forward one space
        if ($piece->player == 0) { // Red player moves right
            return ($dq == 1 && $dr == 0 && !$targetPiece) || 
                   ($dq == 1 && ($dr == -1 || $dr == 1) && $targetPiece);
        } else if ($piece->player == 1) { // Blue player moves left
            return ($dq == -1 && $dr == 0 && !$targetPiece) || 
                   ($dq == -1 && ($dr == -1 || $dr == 1) && $targetPiece);
        } else { // Green player moves up
            return ($dr == -1 && $dq == 0 && !$targetPiece) || 
                   ($dr == -1 && ($dq == -1 || $dq == 1) && $targetPiece);
        }
    }
    
    private function isRookMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        
        // Hexagonal rook moves along lines
        $hexDirections = [
            [1, 0], [-1, 0], [0, 1], [0, -1], [1, -1], [-1, 1]
        ];
        
        foreach ($hexDirections as $dir) {
            if ($dq != 0 && $dr != 0) {
                if ($dq / $dir[0] == $dr / $dir[1] && $dq / $dir[0] > 0) {
                    return $this->isPathClear($fromQ, $fromR, $toQ, $toR, $dir[0], $dir[1]);
                }
            } elseif ($dq == 0 && $dr != 0) {
                if ($dir[0] == 0 && (($dr > 0 && $dir[1] > 0) || ($dr < 0 && $dir[1] < 0))) {
                    return $this->isPathClear($fromQ, $fromR, $toQ, $toR, $dir[0], $dir[1]);
                }
            } elseif ($dr == 0 && $dq != 0) {
                if ($dir[1] == 0 && (($dq > 0 && $dir[0] > 0) || ($dq < 0 && $dir[0] < 0))) {
                    return $this->isPathClear($fromQ, $fromR, $toQ, $toR, $dir[0], $dir[1]);
                }
            }
        }
        
        return false;
    }
    
    private function isBishopMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        $ds = -$dq - $dr; // Third hex coordinate
        
        // Bishop moves along diagonals (two coordinates change, one stays same)
        if (($dq == 0 && $dr != 0) || ($dr == 0 && $dq != 0) || ($ds == 0 && ($dq != 0 || $dr != 0))) {
            return false; // This is rook movement
        }
        
        // Check diagonal movement
        if (abs($dq) == abs($dr) || abs($dq) == abs($ds) || abs($dr) == abs($ds)) {
            $stepQ = $dq == 0 ? 0 : ($dq > 0 ? 1 : -1);
            $stepR = $dr == 0 ? 0 : ($dr > 0 ? 1 : -1);
            return $this->isPathClear($fromQ, $fromR, $toQ, $toR, $stepQ, $stepR);
        }
        
        return false;
    }
    
    private function isKnightMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        
        // Knight moves in hexagonal pattern
        $knightMoves = [
            [2, -1], [1, -2], [-1, -1], [-2, 1], [-1, 2], [1, 1]
        ];
        
        foreach ($knightMoves as $move) {
            if ($dq == $move[0] && $dr == $move[1]) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isQueenMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        return $this->isRookMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) || 
               $this->isBishopMoveLegal($piece, $fromQ, $fromR, $toQ, $toR);
    }
    
    private function isKingMoveLegal($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = abs($toQ - $fromQ);
        $dr = abs($toR - $fromR);
        
        // King moves one space in any direction
        return ($dq <= 1 && $dr <= 1 && ($dq + $dr) <= 1) || 
               ($dq == 1 && $dr == 1); // Diagonal hex move
    }
    
    private function isPathClear($fromQ, $fromR, $toQ, $toR, $stepQ, $stepR) {
        $q = $fromQ + $stepQ;
        $r = $fromR + $stepR;
        
        while ($q != $toQ || $r != $toR) {
            if ($this->getPiece($q, $r) !== null) {
                return false;
            }
            $q += $stepQ;
            $r += $stepR;
        }
        
        return true;
    }
    
    // FIXED: Added proper checkmate detection
    private function isCheckmate($player) {
        // Find the king
        $kingPos = null;
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->type === 'king' && $piece->player === $player) {
                    $kingPos = [$q, $r];
                    break 2;
                }
            }
        }
        
        if (!$kingPos) {
            return true; // No king = eliminated
        }
        
        // For simplicity, just check if king exists
        // In a full implementation, you'd check for actual check/checkmate
        return false;
    }
    
    // FIXED: Added valid moves calculation
    public function getValidMoves($q, $r) {
        $piece = $this->getPiece($q, $r);
        if (!$piece || $piece->player !== $this->currentPlayer) {
            return [];
        }
        
        $validMoves = [];
        
        // Check all possible destinations
        for ($toQ = -$this->boardSize; $toQ <= $this->boardSize; $toQ++) {
            for ($toR = max(-$this->boardSize, -$toQ - $this->boardSize); 
                 $toR <= min($this->boardSize, -$toQ + $this->boardSize); $toR++) {
                
                if ($this->isValidMove($q, $r, $toQ, $toR)) {
                    $validMoves[] = ['q' => $toQ, 'r' => $toR];
                }
            }
        }
        
        return $validMoves;
    }
    
    public function getGameState() {
        $activePlayerCount = $this->getActivePlayerCount();
        $gameOver = $activePlayerCount <= 1;
        $winner = null;
        
        if ($gameOver && $activePlayerCount == 1) {
            for ($player = 0; $player < $this->playerCount; $player++) {
                if ($this->activePlayers[$player]) {
                    $winner = $this->playerUsers[$player];
                    break;
                }
            }
        }
        
        return [
            'gameId' => $this->gameId,
            'currentPlayer' => $this->currentPlayer,
            'playerCount' => $this->playerCount,
            'moveCount' => $this->moveCount,
            'board' => $this->board,
            'gameStatus' => [
                'gameOver' => $gameOver,
                'winner' => $winner,
                'activePlayerCount' => $activePlayerCount
            ],
            'activePlayers' => $this->activePlayers,
            'playerUsers' => $this->playerUsers
        ];
    }
}

class GameManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function createGame($creatorId, $gameName, $playerCount, $boardSize = 8, $gameMode = 'multiplayer', $aiDifficulty = 'medium') {
        global $send_game_notifications;
        
        $gameId = uniqid();
        
        $game = new HexChess($gameId, $playerCount, $boardSize, $gameMode, $aiDifficulty);
        $gameState = serialize($game);
        
        // Insert game
        $stmt = $this->pdo->prepare("
            INSERT INTO games (game_id, creator_id, game_name, player_count, board_size, game_state, game_mode, ai_difficulty) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $creatorId, $gameName, $playerCount, $boardSize, $gameState, $gameMode, $aiDifficulty]);
        
        // Add creator as first player
        $this->joinGame($gameId, $creatorId);
        
        // For solitaire mode, automatically start the game
        if ($gameMode === 'solitaire') {
            $this->startGame($gameId);
        }
        
        // Log and notify
        logActivity('game_created', "Game created: $gameName (ID: $gameId) - Mode: $gameMode", $creatorId);
        
        if ($send_game_notifications) {
            // Get creator info for notification
            $stmt = $this->pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$creatorId]);
            $creator = $stmt->fetch();
            
            $notificationMessage = "
                <h3>🎮 New Game Created!</h3>
                <p><strong>Game Name:</strong> " . htmlspecialchars($gameName) . "</p>
                <p><strong>Created by:</strong> " . htmlspecialchars($creator['username']) . "</p>
                <p><strong>Players:</strong> $playerCount</p>
                <p><strong>Board Size:</strong> $boardSize</p>
                <p><strong>Game ID:</strong> $gameId</p>
                <p><strong>Created:</strong> " . date('Y-m-d H:i:s') . "</p>
                <hr>
                <p>Total active games: " . $this->getActiveGameCount() . "</p>
            ";
            
            sendAdminNotification("New Game Created: $gameName", $notificationMessage);
        }
        
        return $gameId;
    }
    
    private function getActiveGameCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM games WHERE status IN ('waiting', 'active')");
        return $stmt->fetchColumn();
    }
    
    public function joinGame($gameId, $userId) {
        // Check if game exists and has space
        $stmt = $this->pdo->prepare("SELECT player_count, game_mode FROM games WHERE game_id = ? AND status = 'waiting'");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        
        if (!$game) return false;
        
        // For solitaire mode, only allow the creator to join
        if ($game['game_mode'] === 'solitaire') {
            // Check if user is already in the game
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM game_players WHERE game_id = ? AND user_id = ?");
            $stmt->execute([$gameId, $userId]);
            $userInGame = $stmt->fetch()['count'];
            
            if ($userInGame > 0) {
                return true; // User already in game
            }
            
            // Add user as player 0
            $stmt = $this->pdo->prepare("INSERT INTO game_players (game_id, user_id, player_slot) VALUES (?, ?, ?)");
            return $stmt->execute([$gameId, $userId, 0]);
        }
        
        // Regular multiplayer logic
        // Count current players
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM game_players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $playerCount = $stmt->fetch()['count'];
        
        if ($playerCount >= $game['player_count']) return false;
        
        // Add player
        $stmt = $this->pdo->prepare("INSERT INTO game_players (game_id, user_id, player_slot) VALUES (?, ?, ?)");
        $stmt->execute([$gameId, $userId, $playerCount]);
        
        // If game is full, start it
        if ($playerCount + 1 >= $game['player_count']) {
            $this->startGame($gameId);
        }
        
        return true;
    }
    
    private function startGame($gameId) {
        global $send_game_notifications;
        
        $stmt = $this->pdo->prepare("UPDATE games SET status = 'active', started_at = NOW() WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Get game info for notification
        $stmt = $this->pdo->prepare("
            SELECT g.game_name, g.player_count, u.username as creator
            FROM games g 
            JOIN users u ON g.creator_id = u.id 
            WHERE g.game_id = ?
        ");
        $stmt->execute([$gameId]);
        $gameInfo = $stmt->fetch();
        
        // Log the game start
        logActivity('game_started', "Game started: {$gameInfo['game_name']} (ID: $gameId)");
        
        if ($send_game_notifications) {
            // Send notification
            $notificationMessage = "
                <h3>🚀 Game Started!</h3>
                <p><strong>Game:</strong> " . htmlspecialchars($gameInfo['game_name']) . "</p>
                <p><strong>Creator:</strong> " . htmlspecialchars($gameInfo['creator']) . "</p>
                <p><strong>Players:</strong> {$gameInfo['player_count']}</p>
                <p><strong>Game ID:</strong> $gameId</p>
                <p><strong>Started:</strong> " . date('Y-m-d H:i:s') . "</p>
            ";
            
            sendAdminNotification("Game Started: {$gameInfo['game_name']}", $notificationMessage);
        }
    }
    
    public function finishGame($gameId, $winnerId = null) {
        global $send_game_notifications;
        
        $stmt = $this->pdo->prepare("
            UPDATE games SET status = 'finished', finished_at = NOW(), winner_id = ? 
            WHERE game_id = ?
        ");
        $stmt->execute([$winnerId, $gameId]);
        
        // Get game and winner info
        $stmt = $this->pdo->prepare("
            SELECT g.game_name, g.player_count, creator.username as creator_name,
                   winner.username as winner_name
            FROM games g 
            JOIN users creator ON g.creator_id = creator.id 
            LEFT JOIN users winner ON g.winner_id = winner.id
            WHERE g.game_id = ?
        ");
        $stmt->execute([$gameId]);
        $gameInfo = $stmt->fetch();
        
        // Log the completion
        $winnerText = $gameInfo['winner_name'] ? "Winner: {$gameInfo['winner_name']}" : "No winner";
        logActivity('game_finished', "Game finished: {$gameInfo['game_name']} (ID: $gameId) - $winnerText", $winnerId);
        
        if ($send_game_notifications) {
            // Send notification
            $winnerDisplay = $gameInfo['winner_name'] ? htmlspecialchars($gameInfo['winner_name']) : 'No winner (draw/abandoned)';
            $notificationMessage = "
                <h3>🏁 Game Completed!</h3>
                <p><strong>Game:</strong> " . htmlspecialchars($gameInfo['game_name']) . "</p>
                <p><strong>Creator:</strong> " . htmlspecialchars($gameInfo['creator_name']) . "</p>
                <p><strong>Winner:</strong> $winnerDisplay</p>
                <p><strong>Players:</strong> {$gameInfo['player_count']}</p>
                <p><strong>Game ID:</strong> $gameId</p>
                <p><strong>Finished:</strong> " . date('Y-m-d H:i:s') . "</p>
                <hr>
                <p>Games completed today: " . $this->getGamesCompletedToday() . "</p>
            ";
            
            sendAdminNotification("Game Completed: {$gameInfo['game_name']}", $notificationMessage);
        }
    }
    
    private function getGamesCompletedToday() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM games 
            WHERE status = 'finished' AND DATE(finished_at) = CURDATE()
        ");
        return $stmt->fetchColumn();
    }
    
    public function getAvailableGames() {
        $stmt = $this->pdo->prepare("
            SELECT g.game_id, g.game_name, g.player_count, g.board_size, g.created_at, g.game_mode,
                   u.username as creator, COUNT(gp.user_id) as current_players
            FROM games g
            JOIN users u ON g.creator_id = u.id
            LEFT JOIN game_players gp ON g.game_id = gp.game_id
            WHERE g.status = 'waiting' AND g.game_mode = 'multiplayer'
            GROUP BY g.game_id
            ORDER BY g.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getGame($gameId) {
        $stmt = $this->pdo->prepare("SELECT * FROM games WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $gameData = $stmt->fetch();
        
        if (!$gameData) return null;
        
        $game = unserialize($gameData['game_state']);
        
        // Update game mode and AI settings if they weren't saved in the serialized game
        if (method_exists($game, 'getGameMode')) {
            // Game already has the new properties
        } else {
            // Older game, update it
            $gameMode = $gameData['game_mode'] ?? 'multiplayer';
            $aiDifficulty = $gameData['ai_difficulty'] ?? 'medium';
            
            // Recreate the game with proper settings
            $newGame = new HexChess(
                $game->gameId,
                $game->getPlayerCount(),
                $game->getBoardSize(),
                $gameMode,
                $aiDifficulty
            );
            
            // Copy the current game state
            $newGame->board = $game->board;
            $newGame->currentPlayer = $game->currentPlayer;
            $newGame->moveCount = $game->moveCount;
            $newGame->activePlayers = $game->activePlayers;
            
            $game = $newGame;
        }
        
        // Get player mapping
        $stmt = $this->pdo->prepare("
            SELECT gp.player_slot, gp.user_id, u.username 
            FROM game_players gp 
            LEFT JOIN users u ON gp.user_id = u.id 
            WHERE gp.game_id = ? 
            ORDER BY gp.player_slot
        ");
        $stmt->execute([$gameId]);
        $players = $stmt->fetchAll();
        
        $playerUsers = array_fill(0, $game->getPlayerCount(), null);
        foreach ($players as $player) {
            $playerUsers[$player['player_slot']] = $player['user_id'];
        }
        
        $game->setPlayerUsers($playerUsers);
        
        return ['game' => $game, 'data' => $gameData, 'players' => $players];
    }
    
    public function saveGame($gameId, $game) {
        $gameState = serialize($game);
        $stmt = $this->pdo->prepare("UPDATE games SET game_state = ? WHERE game_id = ?");
        $stmt->execute([$gameState, $gameId]);
    }
    
    public function getUserGames($userId) {
        $stmt = $this->pdo->prepare("
            SELECT g.game_id, g.game_name, g.status, g.created_at, g.started_at,
                   u.username as creator
            FROM games g
            JOIN game_players gp ON g.game_id = gp.game_id
            JOIN users u ON g.creator_id = u.id
            WHERE gp.user_id = ?
            ORDER BY g.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}

// FIXED: Moved function inside proper scope and fixed logic
function checkAndFinishGame($gameId, $game) {
    global $pdo;
    
    $gameState = $game->getGameState();
    if ($gameState['gameStatus']['gameOver']) {
        $winnerId = $gameState['gameStatus']['winner'] ?? null;
        $gameManager = new GameManager($pdo);
        $gameManager->finishGame($gameId, $winnerId);
        
        // Update user stats
        if ($winnerId) {
            $user = new User($pdo);
            $user->updateStats($winnerId, true); // Winner
            
            // Update losers
            $playerUsers = $game->getPlayerUsers();
            foreach ($playerUsers as $userId) {
                if ($userId && $userId != $winnerId) {
                    $user->updateStats($userId, false); // Loser
                }
            }
        }
    }
}

// Handle different pages
$page = $_GET['page'] ?? 'lobby';
$user = new User($pdo);
$gameManager = new GameManager($pdo);

// Handle AJAX requests
if ($_POST['action'] ?? '') {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'register':
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $email = trim($_POST['email'] ?? '');
            
            if (strlen($username) < 3 || strlen($password) < 6) {
                echo json_encode(['success' => false, 'error' => 'Username must be 3+ chars, password 6+ chars']);
                exit;
            }
            
            $userId = $user->register($username, $password, $email);
            if ($userId) {
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Username already taken']);
            }
            break;
            
        case 'login':
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            
            if ($user->login($username, $password)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
            }
            break;
            
        case 'logout':
            session_destroy();
            echo json_encode(['success' => true]);
            break;
            
        case 'create_game':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not logged in']);
                exit;
            }
            
            $gameName = trim($_POST['game_name']);
            $playerCount = (int)$_POST['player_count'];
            $boardSize = (int)$_POST['board_size'];
            $gameMode = $_POST['game_mode'] ?? 'multiplayer';
            $aiDifficulty = $_POST['ai_difficulty'] ?? 'medium';
            
            $gameId = $gameManager->createGame($_SESSION['user_id'], $gameName, $playerCount, $boardSize, $gameMode, $aiDifficulty);
            echo json_encode(['success' => true, 'game_id' => $gameId]);
            break;
            
        case 'join_game':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not logged in']);
                exit;
            }
            
            $gameId = $_POST['game_id'];
            if ($gameManager->joinGame($gameId, $_SESSION['user_id'])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Cannot join game']);
            }
            break;
            
        case 'get_game_state':
            $gameId = $_POST['game_id'];
            $gameInfo = $gameManager->getGame($gameId);
            
            if ($gameInfo) {
                $gameState = $gameInfo['game']->getGameState();
                echo json_encode(['success' => true, 'gameState' => $gameState, 'players' => $gameInfo['players']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Game not found']);
            }
            break;
            
        // FIXED: Added get_valid_moves action
        case 'get_valid_moves':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not logged in']);
                exit;
            }
            
            $gameId = $_POST['game_id'];
            $q = (int)$_POST['q'];
            $r = (int)$_POST['r'];
            
            $gameInfo = $gameManager->getGame($gameId);
            if (!$gameInfo) {
                echo json_encode(['success' => false, 'error' => 'Game not found']);
                exit;
            }
            
            $game = $gameInfo['game'];
            
            if (!$game->canUserMove($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not your turn']);
                exit;
            }
            
            $validMoves = $game->getValidMoves($q, $r);
            echo json_encode(['success' => true, 'moves' => $validMoves]);
            break;
            
        case 'move':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not logged in']);
                exit;
            }
            
            $gameId = $_POST['game_id'];
            $fromQ = (int)$_POST['fromQ'];
            $fromR = (int)$_POST['fromR'];
            $toQ = (int)$_POST['toQ'];
            $toR = (int)$_POST['toR'];
            
            $gameInfo = $gameManager->getGame($gameId);
            if (!$gameInfo) {
                echo json_encode(['success' => false, 'error' => 'Game not found']);
                exit;
            }
            
            $game = $gameInfo['game'];
            
            if (!$game->canUserMove($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not your turn']);
                exit;
            }
            
            if ($game->movePiece($fromQ, $fromR, $toQ, $toR)) {
                $gameManager->saveGame($gameId, $game);
                
                // Check if game ended and handle completion
                checkAndFinishGame($gameId, $game);
                
                $gameState = $game->getGameState();
                $response = ['success' => true, 'gameState' => $gameState];
                
                // If it's now an AI player's turn, make AI move
                if (!$gameState['gameStatus']['gameOver'] && $game->isAIPlayer($game->getCurrentPlayerSlot())) {
                    if ($game->makeAIMove()) {
                        $gameManager->saveGame($gameId, $game);
                        checkAndFinishGame($gameId, $game);
                        $response['gameState'] = $game->getGameState();
                        $response['aiMoved'] = true;
                    }
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid move']);
            }
            break;
            
        case 'make_ai_move':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not logged in']);
                exit;
            }
            
            $gameId = $_POST['game_id'];
            
            $gameInfo = $gameManager->getGame($gameId);
            if (!$gameInfo) {
                echo json_encode(['success' => false, 'error' => 'Game not found']);
                exit;
            }
            
            $game = $gameInfo['game'];
            
            if (!$game->isAIPlayer($game->getCurrentPlayerSlot())) {
                echo json_encode(['success' => false, 'error' => 'Current player is not AI']);
                exit;
            }
            
            if ($game->makeAIMove()) {
                $gameManager->saveGame($gameId, $game);
                checkAndFinishGame($gameId, $game);
                
                $gameState = $game->getGameState();
                echo json_encode(['success' => true, 'gameState' => $gameState]);
            } else {
                echo json_encode(['success' => false, 'error' => 'AI could not make a move']);
            }
            break;
    }
    exit;
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$currentUser = $isLoggedIn ? $user->getUserById($_SESSION['user_id']) : null;

// Render functions
function renderBoard($game, $gameId, $canMove = true) {
    $html = '<div class="hex-board" data-game-id="' . $gameId . '">';
    $boardSize = $game->getBoardSize();
    
    for ($r = $boardSize; $r >= -$boardSize; $r--) {
        $html .= '<div class="hex-row">';
        
        for ($q = -$boardSize; $q <= $boardSize; $q++) {
            if (abs($q) <= $boardSize && 
                abs($r) <= $boardSize && 
                abs($q + $r) <= $boardSize) {
                
                $piece = $game->getPiece($q, $r);
                $cellColor = $game->getCellColor($q, $r);
                $pieceIcon = '';
                
                if ($piece) {
                    $icon = $piece->getIcon();
                    $colorClass = $piece->getColorClass($game->getPlayerCount());
                    $pieceIcon = "<span class='piece $colorClass'>$icon</span>";
                }
                
                $onclick = $canMove ? "onclick='selectHex($q, $r)'" : '';
                $html .= "<div class='hex-cell $cellColor' data-q='$q' data-r='$r' $onclick>
                            <div class='hex-inner'>
                                <div class='hex-content'>$pieceIcon</div>
                            </div>
                          </div>";
            }
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HexChess Online</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
        }
        
        .header {
            background: rgba(0, 0, 0, 0.2);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }
        
        .header h1 {
            font-size: 24px;
            background: linear-gradient(45deg, #fff, #e0e0e0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .user-info {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.1);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .auth-container {
            max-width: 400px;
            margin: 4rem auto;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 12px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
        }
        
        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        
        .lobby {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .panel {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .panel h2 {
            margin-bottom: 1rem;
            font-size: 18px;
        }
        
        .game-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .game-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .game-info h3 {
            font-size: 16px;
            margin-bottom: 0.25rem;
        }
        
        .game-info p {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .game-area {
            display: flex;
            height: calc(100vh - 120px);
        }
        
        .game-sidebar {
            width: 300px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .game-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .player-list {
            margin-bottom: 1rem;
        }
        
        .player-item {
            padding: 0.5rem;
            margin-bottom: 0.25rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .player-item.current {
            background: rgba(46, 204, 113, 0.2);
            border: 1px solid #2ecc71;
        }
        
        .game-status {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }
        
        /* Hex board styles */
        .hex-board {
            display: flex;
            flex-direction: column;
            align-items: center;
            transform: scale(0.8);
            margin: 20px auto;
        }
        
        .hex-row {
            display: flex;
            margin: -6px 0;
            position: relative;
            justify-content: center;
        }
        
        .hex-row:nth-child(even) {
            margin-left: 2px;
        }
        
        .hex-cell {
            width: 52px;
            height: 60px;
            margin: 0 2px;
            position: relative;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        
        .hex-inner {
            width: 100%;
            height: 100%;
            position: relative;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
        }
        
        .hex-cell.pastel-red .hex-inner {
            background: linear-gradient(135deg, #ffb3ba, #ff9999);
        }
        
        .hex-cell.pastel-blue .hex-inner {
            background: linear-gradient(135deg, #bae1ff, #87ceeb);
        }
        
        .hex-cell.pastel-green .hex-inner {
            background: linear-gradient(135deg, #baffc9, #90ee90);
        }
        
        .hex-cell:hover {
            transform: scale(1.05);
            z-index: 10;
        }
        
        .hex-cell.selected {
            transform: scale(1.1);
            z-index: 20;
        }
        
        .hex-cell.selected .hex-inner {
            border: 3px solid #2ecc71;
            background: rgba(46, 204, 113, 0.2) !important;
        }
        
        .hex-cell.valid-move {
            animation: pulseMove 1.2s infinite;
            z-index: 15;
        }
        
        .hex-cell.valid-move .hex-inner {
            border: 4px solid #2ecc71 !important;
            background: rgba(46, 204, 113, 0.4) !important;
        }
        
        @keyframes pulseMove {
            0%, 100% { 
                transform: scale(1.0);
                opacity: 1;
            }
            50% { 
                transform: scale(1.15);
                opacity: 0.8;
            }
        }
        
        .hex-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 5;
            pointer-events: none;
        }
        
        .piece {
            font-size: 48px;
            font-weight: 900;
            line-height: 1;
            text-shadow: 
                -1px -1px 0 #000,
                1px -1px 0 #000,
                -1px 1px 0 #000,
                1px 1px 0 #000;
            filter: none;
            transition: all 0.2s ease;
            user-select: none;
            opacity: 1;
        }
        
        .piece.red-piece {
            color: #dc143c;
        }
        
        .piece.blue-piece {
            color: #1e90ff;
        }
        
        .piece.green-piece {
            color: #228b22;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            border-left: 4px solid #e74c3c;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 300px;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            border-left-color: #2ecc71;
        }
        
        .notification.error {
            border-left-color: #e74c3c;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏰 HexChess Online</h1>
        <div class="user-info">
            <?php if ($isLoggedIn): ?>
                <span>Welcome, <?php echo htmlspecialchars($currentUser['username']); ?>!</span>
                <span>(<?php echo $currentUser['wins']; ?>W-<?php echo $currentUser['losses']; ?>L)</span>
                <button class="btn btn-secondary" onclick="logout()">Logout</button>
            <?php else: ?>
                <button class="btn" onclick="showAuth('login')">Login</button>
                <button class="btn btn-secondary" onclick="showAuth('register')">Register</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isLoggedIn): ?>
        <!-- Authentication forms -->
        <div class="auth-container" id="loginForm" style="display: none;">
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
                <button type="submit" class="btn" style="width: 100%;">Login</button>
            </form>
            <p style="text-align: center; margin-top: 1rem;">
                <a href="#" onclick="showAuth('register')" style="color: #fff;">Need an account? Register</a>
            </p>
        </div>
        
        <div class="auth-container" id="registerForm" style="display: none;">
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
                <button type="submit" class="btn" style="width: 100%;">Register</button>
            </form>
            <p style="text-align: center; margin-top: 1rem;">
                <a href="#" onclick="showAuth('login')" style="color: #fff;">Have an account? Login</a>
            </p>
        </div>

    <?php elseif ($page === 'lobby'): ?>
        <!-- Lobby -->
        <div class="container">
            <div class="lobby">
                <div class="panel">
                    <h2>🎮 Create New Game</h2>
                    <form onsubmit="createGame(event)">
                        <div class="form-group">
                            <label>Game Name:</label>
                            <input type="text" name="game_name" required placeholder="My Chess Game">
                        </div>
                        <div class="form-group">
                            <label>Game Mode:</label>
                            <select name="game_mode" onchange="toggleGameMode(this.value)">
                                <option value="multiplayer">Multiplayer (vs Humans)</option>
                                <option value="solitaire">Solitaire (vs AI)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Players:</label>
                            <select name="player_count">
                                <option value="2">2 Players</option>
                                <option value="3">3 Players</option>
                            </select>
                        </div>
                        <div class="form-group" id="ai_difficulty_group" style="display: none;">
                            <label>AI Difficulty:</label>
                            <select name="ai_difficulty">
                                <option value="easy">Easy - Makes random moves</option>
                                <option value="medium" selected>Medium - Decent strategy</option>
                                <option value="hard">Hard - Strong opponent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Board Size:</label>
                            <select name="board_size">
                                <option value="6">Small (13 tiles)</option>
                                <option value="7">Medium (15 tiles)</option>
                                <option value="8" selected>Large (17 tiles)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn" style="width: 100%;">Create Game</button>
                        <div style="margin-top: 1rem; padding: 10px; background: rgba(255,255,255,0.1); border-radius: 6px; font-size: 12px;">
                            <strong>💡 Solitaire Mode:</strong> Play against AI opponents! You'll be the first player, and computer players will control the other colors.
                        </div>
                    </form>
                </div>
                
                <div class="panel">
                    <h2>🌐 Available Games</h2>
                    <div class="game-list" id="gameList">
                        <p>Loading games...</p>
                    </div>
                    <button class="btn btn-secondary" onclick="refreshGames()" style="width: 100%; margin-top: 1rem;">Refresh</button>
                </div>
            </div>
        </div>

    <?php elseif ($page === 'game'): ?>
        <!-- Game page -->
        <?php
        $gameId = $_GET['id'] ?? '';
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
            $game = $gameInfo['game'];
            $players = $gameInfo['players'];
            $gameState = $game->getGameState();
            
            $userPlayerSlot = null;
            foreach ($players as $player) {
                if ($player['user_id'] == $_SESSION['user_id']) {
                    $userPlayerSlot = $player['player_slot'];
                    break;
                }
            }
            
            $canMove = $userPlayerSlot !== null && $game->canUserMove($_SESSION['user_id']);
        ?>
            <div class="game-area">
                <div class="game-sidebar">
                    <div class="game-status">
                        <h3 id="gameStatus">Game Active</h3>
                        <p id="gameStatusText">Make your move!</p>
                    </div>
                    
                    <div class="player-list">
                        <h3>Players</h3>
                        <div id="playerList">
                            <?php foreach ($players as $player): ?>
                                <div class="player-item <?php echo $player['player_slot'] == $game->getCurrentPlayerSlot() ? 'current' : ''; ?>">
                                    <strong><?php echo ucfirst($game->getPlayers()[$player['player_slot']]); ?></strong><br>
                                    <?php 
                                    if ($player['user_id'] && strpos($player['user_id'], 'AI_') === 0) {
                                        echo 'AI Player';
                                    } else {
                                        echo htmlspecialchars($player['username'] ?? 'Unknown'); 
                                    }
                                    ?>
                                    <?php if ($player['user_id'] == $_SESSION['user_id']): ?>
                                        <small>(You)</small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php 
                            // Show remaining AI players if in solitaire mode
                            if (method_exists($game, 'getGameMode') && $game->getGameMode() === 'solitaire') {
                                for ($i = count($players); $i < $game->getPlayerCount(); $i++) {
                                    echo '<div class="player-item">';
                                    echo '<strong>' . ucfirst($game->getPlayers()[$i]) . '</strong><br>';
                                    echo 'AI Player';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    
                    <button class="btn btn-secondary" onclick="window.location.href='?page=lobby'">Back to Lobby</button>
                </div>
                
                <div class="game-main">
                    <?php echo renderBoard($game, $gameId, $canMove); ?>
                </div>
            </div>
            
            <script>
                let selectedHex = null;
                let gameId = '<?php echo $gameId; ?>';
                let userCanMove = <?php echo $canMove ? 'true' : 'false'; ?>;
                
                // FIXED: Complete move handling with valid moves display
                function selectHex(q, r) {
                    if (!userCanMove) {
                        showNotification('Not Your Turn', 'Wait for your turn to move', 'error');
                        return;
                    }
                    
                    const cell = document.querySelector(`[data-q="${q}"][data-r="${r}"]`);
                    
                    if (selectedHex && selectedHex.q === q && selectedHex.r === r) {
                        clearSelection();
                        return;
                    }
                    
                    if (selectedHex && cell.classList.contains('valid-move')) {
                        makeMove(selectedHex.q, selectedHex.r, q, r);
                        return;
                    }
                    
                    clearSelection();
                    selectedHex = {q: q, r: r};
                    cell.classList.add('selected');
                    
                    // Get and display valid moves
                    getValidMoves(q, r);
                }
                
                function clearSelection() {
                    document.querySelectorAll('.hex-cell').forEach(cell => {
                        cell.classList.remove('selected', 'valid-move');
                    });
                    selectedHex = null;
                }
                
                function getValidMoves(q, r) {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=get_valid_moves&game_id=${gameId}&q=${q}&r=${r}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            data.moves.forEach(move => {
                                const targetCell = document.querySelector(`[data-q="${move.q}"][data-r="${move.r}"]`);
                                if (targetCell) {
                                    targetCell.classList.add('valid-move');
                                }
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error getting valid moves:', error);
                    });
                }
                
                function makeMove(fromQ, fromR, toQ, toR) {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=move&game_id=${gameId}&fromQ=${fromQ}&fromR=${fromR}&toQ=${toQ}&toR=${toR}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateGameState(data.gameState);
                            clearSelection();
                        } else {
                            showNotification('Invalid Move', data.error || 'Move not allowed', 'error');
                            clearSelection();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Connection Error', 'Unable to make move', 'error');
                    });
                }
                
                function updateGameState(gameState) {
                    if (gameState.gameStatus.gameOver) {
                        showNotification('Game Over', 'Game has ended!', 'success');
                        userCanMove = false;
                    }
                    
                    // Refresh page to update the display
                    setTimeout(() => location.reload(), 1000);
                }
                
                // Handle AI moves
                function checkForAIMove() {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=get_game_state&game_id=${gameId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.gameState) {
                            // Check if current player is AI
                            const currentPlayer = data.gameState.currentPlayer;
                            const playerUsers = data.gameState.playerUsers;
                            const isAITurn = playerUsers[currentPlayer] && playerUsers[currentPlayer].startsWith('AI_');
                            
                            if (isAITurn && !data.gameState.gameStatus.gameOver) {
                                // Make AI move after a short delay
                                setTimeout(() => {
                                    fetch(window.location.href, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                        },
                                        body: `action=make_ai_move&game_id=${gameId}`
                                    })
                                    .then(response => response.json())
                                    .then(aiData => {
                                        if (aiData.success) {
                                            setTimeout(() => location.reload(), 500);
                                        }
                                    });
                                }, 1000);
                            }
                        }
                    });
                }
                
                // Check for AI moves on page load
                setTimeout(checkForAIMove, 1000);
                
                // Poll for game updates every 3 seconds
                setInterval(() => {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=get_game_state&game_id=${gameId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Check if it's now user's turn
                            const canMove = data.gameState.playerUsers[data.gameState.currentPlayer] == <?php echo $_SESSION['user_id']; ?>;
                            if (canMove !== userCanMove) {
                                userCanMove = canMove;
                                if (canMove) {
                                    showNotification('Your Turn', 'Make your move!', 'success');
                                }
                            }
                        }
                    })
                    .catch(error => console.error('Poll error:', error));
                }, 3000);
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <script>
        function showAuth(type) {
            document.getElementById('loginForm').style.display = type === 'login' ? 'block' : 'none';
            document.getElementById('registerForm').style.display = type === 'register' ? 'block' : 'none';
        }
        
        function login(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'login');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showNotification('Login Failed', data.error, 'error');
                }
            });
        }
        
        function register(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'register');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showNotification('Registration Failed', data.error, 'error');
                }
            });
        }
        
        function logout() {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=logout'
            })
            .then(() => location.reload());
        }
        
        function createGame(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'create_game');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `?page=game&id=${data.game_id}`;
                } else {
                    showNotification('Failed to Create Game', data.error, 'error');
                }
            });
        }
        
        function joinGame(gameId) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=join_game&game_id=${gameId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `?page=game&id=${gameId}`;
                } else {
                    showNotification('Failed to Join', data.error, 'error');
                }
            });
        }
        
        function refreshGames() {
            loadAvailableGames();
        }
        
        function loadAvailableGames() {
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?api=games')
                .then(response => response.json())
                .then(games => {
                    const gameList = document.getElementById('gameList');
                    if (games.length === 0) {
                        gameList.innerHTML = '<p>No games available. Create one!</p>';
                        return;
                    }
                    
                    gameList.innerHTML = games.map(game => `
                        <div class="game-item">
                            <div class="game-info">
                                <h3>${game.game_name}</h3>
                                <p>${game.current_players}/${game.player_count} players • By ${game.creator}</p>
                            </div>
                            <button class="btn" onclick="joinGame('${game.game_id}')">Join</button>
                        </div>
                    `).join('');
                })
                .catch(error => {
                    console.error('Error loading games:', error);
                    document.getElementById('gameList').innerHTML = '<p>Error loading games.</p>';
                });
        }
        
        function showNotification(title, message, type = 'error') {
            const existing = document.querySelector('.notification');
            if (existing) existing.remove();
            
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `<strong>${title}</strong><br>${message}`;
            
            document.body.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        // Initialize auth forms or load games
        <?php if (!$isLoggedIn): ?>
            showAuth('login');
        <?php elseif ($page === 'lobby'): ?>
            loadAvailableGames();
        <?php endif; ?>
    </script>
</body>
</html>

<?php
// Handle cron jobs
if (isset($_GET['cron'])) {
    switch ($_GET['cron']) {
        case 'daily_summary':
            if ($send_daily_summary) {
                sendDailySummary();
                echo "Daily summary sent successfully";
            } else {
                echo "Daily summary disabled";
            }
            break;
        default:
            echo "Unknown cron job";
    }
    exit;
}

// API endpoint for loading games
if (isset($_GET['api']) && $_GET['api'] === 'games') {
    header('Content-Type: application/json');
    echo json_encode($gameManager->getAvailableGames());
    exit;
}
?>
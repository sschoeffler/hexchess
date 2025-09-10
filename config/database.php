<?php

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

// Function to check and finish games when they end
function checkAndFinishGame($gameId, $game) {
    global $gameManager, $user;
    
    $gameState = $game->getGameState();
    if ($gameState['gameStatus']['gameOver']) {
        $winnerId = $gameState['gameStatus']['winner'] ?? null;
        $gameManager->finishGame($gameId, $winnerId);
        
        // Update user stats
        if ($winnerId) {
            $userObj = new User($GLOBALS['pdo']);
            $userObj->updateStats($winnerId, true); // Winner
            
            // Update losers
            $playerUsers = $game->getPlayerUsers();
            foreach ($playerUsers as $userId) {
                if ($userId && $userId != $winnerId) {
                    $userObj->updateStats($userId, false); // Loser
                }
            }
        }
    }
}
<?php

class User {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function register($username, $password, $email = null) {
        global $send_signup_notifications;
        
        // Check if username already exists
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return false; // Username already exists
        }
        
        // Hash password and insert user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, password_hash, email, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        try {
            $stmt->execute([$username, $hashedPassword, $email]);
            $userId = $this->pdo->lastInsertId();
            
            logActivity('user_registered', "New user registered: $username", $userId);
            
            if ($send_signup_notifications) {
                $totalUsers = $this->getTotalUserCount();
                $notificationMessage = "
                    <h3>👤 New User Registration!</h3>
                    <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    <p><strong>Email:</strong> " . ($email ? htmlspecialchars($email) : 'Not provided') . "</p>
                    <p><strong>User ID:</strong> $userId</p>
                    <p><strong>Registration Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <hr>
                    <p>Total users: $totalUsers</p>
                ";
                
                sendAdminNotification("New User: $username", $notificationMessage);
            }
            
            return $userId;
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            return false;
        }
    }
    
    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            logActivity('user_login', "User logged in: $username", $user['id']);
            return $user['id'];
        }
        
        logActivity('login_failed', "Failed login attempt: $username");
        return false;
    }
    
    public function getUserById($userId) {
        $stmt = $this->pdo->prepare("
            SELECT id, username, email, created_at, wins, losses, games_played,
                   (wins + losses) as completed_games,
                   CASE 
                       WHEN (wins + losses) > 0 THEN ROUND((wins / (wins + losses)) * 100, 1)
                       ELSE 0 
                   END as win_rate
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    public function getUserByUsername($username) {
        $stmt = $this->pdo->prepare("
            SELECT id, username, email, created_at, wins, losses, games_played,
                   (wins + losses) as completed_games,
                   CASE 
                       WHEN (wins + losses) > 0 THEN ROUND((wins / (wins + losses)) * 100, 1)
                       ELSE 0 
                   END as win_rate
            FROM users 
            WHERE username = ?
        ");
        $stmt->execute([$username]);
        return $stmt->fetch();
    }
    
    // NEW: Update user statistics when games end
    public function updateStats($userId, $won) {
        if ($won) {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET wins = wins + 1, games_played = games_played + 1 
                WHERE id = ?
            ");
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET losses = losses + 1, games_played = games_played + 1 
                WHERE id = ?
            ");
        }
        
        try {
            $stmt->execute([$userId]);
            
            // Log the stat update
            $result = $won ? 'win' : 'loss';
            logActivity('stats_updated', "User stats updated: $result", $userId);
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to update user stats for user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Increment games played without affecting win/loss (for draws, etc.)
    public function incrementGamesPlayed($userId) {
        $stmt = $this->pdo->prepare("UPDATE users SET games_played = games_played + 1 WHERE id = ?");
        
        try {
            $stmt->execute([$userId]);
            logActivity('games_played_updated', "Games played incremented", $userId);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to increment games played for user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Reset user statistics (admin function)
    public function resetStats($userId) {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET wins = 0, losses = 0, games_played = 0 
            WHERE id = ?
        ");
        
        try {
            $stmt->execute([$userId]);
            logActivity('stats_reset', "User stats reset", $userId);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to reset stats for user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    public function getTotalUserCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        return $stmt->fetchColumn();
    }
    
    // NEW: Get leaderboard of top players
    public function getLeaderboard($limit = 10) {
        $stmt = $this->pdo->prepare("
            SELECT username, wins, losses, games_played,
                   (wins + losses) as completed_games,
                   CASE 
                       WHEN (wins + losses) > 0 THEN ROUND((wins / (wins + losses)) * 100, 1)
                       ELSE 0 
                   END as win_rate,
                   created_at
            FROM users 
            WHERE games_played > 0
            ORDER BY wins DESC, win_rate DESC, games_played DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    // NEW: Get recently active players
    public function getRecentlyActivePlayers($days = 7, $limit = 10) {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT u.username, u.wins, u.losses, u.games_played,
                   MAX(al.created_at) as last_activity
            FROM users u
            JOIN activity_log al ON u.id = al.user_id
            WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND al.type IN ('user_login', 'game_created', 'game_finished')
            GROUP BY u.id
            ORDER BY last_activity DESC
            LIMIT ?
        ");
        $stmt->execute([$days, $limit]);
        return $stmt->fetchAll();
    }
    
    // NEW: Update user profile information
    public function updateProfile($userId, $email = null) {
        $stmt = $this->pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        
        try {
            $stmt->execute([$email, $userId]);
            logActivity('profile_updated', "Profile updated", $userId);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to update profile for user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Change user password
    public function changePassword($userId, $currentPassword, $newPassword) {
        // First verify current password
        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false; // Current password incorrect
        }
        
        // Update to new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        
        try {
            $stmt->execute([$hashedPassword, $userId]);
            logActivity('password_changed', "Password changed", $userId);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to change password for user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Check if user exists
    public function userExists($username) {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch() !== false;
    }
    
    // NEW: Get user's game history
    public function getUserGameHistory($userId, $limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT g.game_id, g.game_name, g.status, g.created_at, g.started_at, g.finished_at,
                   g.player_count, creator.username as creator_name,
                   winner.username as winner_name,
                   CASE WHEN g.winner_id = ? THEN 'won'
                        WHEN g.winner_id IS NOT NULL THEN 'lost'
                        ELSE 'draw' END as result
            FROM games g
            JOIN game_players gp ON g.game_id = gp.game_id
            JOIN users creator ON g.creator_id = creator.id
            LEFT JOIN users winner ON g.winner_id = winner.id
            WHERE gp.user_id = ?
            ORDER BY g.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $userId, $limit]);
        return $stmt->fetchAll();
    }
    
    // NEW: Delete user account (admin function)
    public function deleteUser($userId) {
        try {
            $this->pdo->beginTransaction();
            
            // Remove from active games first
            $stmt = $this->pdo->prepare("DELETE FROM game_players WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Remove from activity log
            $stmt = $this->pdo->prepare("DELETE FROM activity_log WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Finally remove user
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            $this->pdo->commit();
            logActivity('user_deleted', "User account deleted", $userId);
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Failed to delete user $userId: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Get user statistics summary
    public function getUserStatsSummary($userId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                u.username,
                u.wins,
                u.losses,
                u.games_played,
                (u.wins + u.losses) as completed_games,
                CASE 
                    WHEN (u.wins + u.losses) > 0 THEN ROUND((u.wins / (u.wins + u.losses)) * 100, 1)
                    ELSE 0 
                END as win_rate,
                u.created_at as member_since,
                COUNT(DISTINCT g.game_id) as total_games_joined,
                COUNT(DISTINCT CASE WHEN g.status = 'active' THEN g.game_id END) as active_games,
                MAX(al.created_at) as last_activity
            FROM users u
            LEFT JOIN game_players gp ON u.id = gp.user_id
            LEFT JOIN games g ON gp.game_id = g.game_id
            LEFT JOIN activity_log al ON u.id = al.user_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
}
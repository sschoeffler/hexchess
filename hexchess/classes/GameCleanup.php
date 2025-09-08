<?php
/**
 * Automatic Game Cleanup System
 * Cleans up abandoned games based on various criteria
 */

class GameCleanup {
    private $pdo;
    private $cleanupRules;
    
    public function __construct($database) {
        $this->pdo = $database;
        $this->cleanupRules = [
            // Rule 1: Delete games older than 24 hours with less than 2 moves
            'abandoned_new_games' => [
                'age_hours' => 24,
                'max_moves' => 1,
                'description' => 'Games with no second move after 24 hours'
            ],
            
            // Rule 2: Delete games older than 7 days with no activity
            'inactive_games' => [
                'age_hours' => 168, // 7 days
                'no_activity_hours' => 72, // 3 days of no moves
                'description' => 'Games with no activity for 3+ days'
            ],
            
            // Rule 3: Delete finished games older than 30 days
            'old_finished_games' => [
                'age_hours' => 720, // 30 days
                'status' => 'finished',
                'description' => 'Finished games older than 30 days'
            ],
            
            // Rule 4: Delete AI games older than 7 days (usually single session)
            'old_ai_games' => [
                'age_hours' => 168, // 7 days
                'game_type' => 'ai',
                'description' => 'AI games older than 7 days'
            ]
        ];
    }
    
    /**
     * Run all cleanup rules
     * @param bool $dryRun - If true, only count what would be deleted
     * @return array Results of cleanup
     */
    public function runCleanup($dryRun = false) {
        $results = [];
        
        foreach ($this->cleanupRules as $ruleName => $rule) {
            $result = $this->executeCleanupRule($ruleName, $rule, $dryRun);
            $results[$ruleName] = $result;
        }
        
        return $results;
    }
    
    /**
     * Execute a specific cleanup rule
     */
    private function executeCleanupRule($ruleName, $rule, $dryRun = false) {
        try {
            $query = $this->buildCleanupQuery($rule);
            
            if ($dryRun) {
                // Count what would be deleted
                $countQuery = str_replace('DELETE g, gp', 'SELECT COUNT(DISTINCT g.game_id)', $query);
                $stmt = $this->pdo->prepare($countQuery);
                $this->bindCleanupParams($stmt, $rule);
                $stmt->execute();
                $count = $stmt->fetchColumn();
                
                return [
                    'rule' => $ruleName,
                    'description' => $rule['description'],
                    'would_delete' => $count,
                    'dry_run' => true
                ];
            } else {
                // Actually delete
                $stmt = $this->pdo->prepare($query);
                $this->bindCleanupParams($stmt, $rule);
                $stmt->execute();
                $deletedCount = $stmt->rowCount();
                
                $this->logCleanup($ruleName, $rule['description'], $deletedCount);
                
                return [
                    'rule' => $ruleName,
                    'description' => $rule['description'],
                    'deleted' => $deletedCount,
                    'dry_run' => false
                ];
            }
        } catch (Exception $e) {
            error_log("Cleanup rule '$ruleName' failed: " . $e->getMessage());
            return [
                'rule' => $ruleName,
                'error' => $e->getMessage(),
                'deleted' => 0
            ];
        }
    }
    
    /**
     * Build SQL query for cleanup rule
     */
    private function buildCleanupQuery($rule) {
        $query = "
            DELETE g, gp 
            FROM games g
            LEFT JOIN game_players gp ON g.game_id = gp.game_id
            WHERE 1=1
        ";
        
        // Add age condition
        if (isset($rule['age_hours'])) {
            $query .= " AND g.created_at < NOW() - INTERVAL :age_hours HOUR";
        }
        
        // Add move count condition
        if (isset($rule['max_moves'])) {
            $query .= " AND (
                SELECT COUNT(*) FROM game_moves gm WHERE gm.game_id = g.game_id
            ) <= :max_moves";
        }
        
        // Add inactivity condition
        if (isset($rule['no_activity_hours'])) {
            $query .= " AND (
                g.updated_at IS NULL OR 
                g.updated_at < NOW() - INTERVAL :no_activity_hours HOUR
            )";
        }
        
        // Add status condition
        if (isset($rule['status'])) {
            $query .= " AND g.status = :status";
        }
        
        // Add game type condition
        if (isset($rule['game_type'])) {
            $query .= " AND g.game_type = :game_type";
        }
        
        return $query;
    }
    
    /**
     * Bind parameters for cleanup query
     */
    private function bindCleanupParams($stmt, $rule) {
        if (isset($rule['age_hours'])) {
            $stmt->bindValue(':age_hours', $rule['age_hours'], PDO::PARAM_INT);
        }
        if (isset($rule['max_moves'])) {
            $stmt->bindValue(':max_moves', $rule['max_moves'], PDO::PARAM_INT);
        }
        if (isset($rule['no_activity_hours'])) {
            $stmt->bindValue(':no_activity_hours', $rule['no_activity_hours'], PDO::PARAM_INT);
        }
        if (isset($rule['status'])) {
            $stmt->bindValue(':status', $rule['status'], PDO::PARAM_STR);
        }
        if (isset($rule['game_type'])) {
            $stmt->bindValue(':game_type', $rule['game_type'], PDO::PARAM_STR);
        }
    }
    
    /**
     * Log cleanup activity
     */
    private function logCleanup($ruleName, $description, $deletedCount) {
        $logMessage = "Game cleanup - Rule: $ruleName, Description: $description, Deleted: $deletedCount games";
        error_log($logMessage);
        
        // Optionally store in database log table
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cleanup_log (rule_name, description, games_deleted, executed_at) 
                VALUES (:rule, :description, :count, NOW())
            ");
            $stmt->execute([
                ':rule' => $ruleName,
                ':description' => $description,
                ':count' => $deletedCount
            ]);
        } catch (Exception $e) {
            // Ignore if log table doesn't exist
        }
    }
    
    /**
     * Get cleanup statistics
     */
    public function getCleanupStats() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    rule_name,
                    description,
                    SUM(games_deleted) as total_deleted,
                    COUNT(*) as executions,
                    MAX(executed_at) as last_run
                FROM cleanup_log 
                WHERE executed_at > NOW() - INTERVAL 30 DAY
                GROUP BY rule_name, description
                ORDER BY last_run DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}

/**
 * Cron job script to run cleanup automatically
 * Add to crontab: 0 2 * * * php /path/to/cleanup_cron.php
 */
function runScheduledCleanup() {
    require_once 'config/database.php';
    
    $cleanup = new GameCleanup($pdo);
    
    // Run cleanup (not dry run)
    $results = $cleanup->runCleanup(false);
    
    // Log summary
    $totalDeleted = array_sum(array_column($results, 'deleted'));
    error_log("Scheduled game cleanup completed. Total games deleted: $totalDeleted");
    
    // Optional: Send email summary to admin
    $summary = "Game Cleanup Summary:\n\n";
    foreach ($results as $result) {
        if (isset($result['deleted'])) {
            $summary .= "- {$result['description']}: {$result['deleted']} games\n";
        }
    }
    
    // Uncomment to email admin
    // mail('admin@yoursite.com', 'Game Cleanup Summary', $summary);
    
    return $results;
}

/**
 * API endpoint for manual cleanup (admin only)
 */
if (isset($_GET['action']) && $_GET['action'] === 'cleanup' && isset($_SESSION['is_admin'])) {
    require_once 'config/database.php';
    
    $cleanup = new GameCleanup($pdo);
    $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === 'true';
    
    $results = $cleanup->runCleanup($dryRun);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'dry_run' => $dryRun,
        'results' => $results,
        'total_deleted' => array_sum(array_column($results, $dryRun ? 'would_delete' : 'deleted'))
    ]);
    exit;
}

/**
 * Create cleanup log table if it doesn't exist
 */
function createCleanupLogTable($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS cleanup_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(50) NOT NULL,
            description TEXT,
            games_deleted INT DEFAULT 0,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_executed_at (executed_at),
            INDEX idx_rule_name (rule_name)
        )
    ";
    
    try {
        $pdo->exec($sql);
        return true;
    } catch (Exception $e) {
        error_log("Failed to create cleanup_log table: " . $e->getMessage());
        return false;
    }
}

// If running as cron job
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    runScheduledCleanup();
}
?>
<?php
// check_db.php - Quick database check
require_once 'config/database.php';

echo "<h2>🔍 Recent Games in Database</h2>";

try {
    $stmt = $pdo->query("SELECT game_id, creator_id, status, created_at FROM games ORDER BY created_at DESC LIMIT 5");
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($games) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Game ID</th><th>Creator</th><th>Status</th><th>Created</th></tr>";
        foreach ($games as $game) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($game['game_id']) . "</td>";
            echo "<td>" . htmlspecialchars($game['creator_id']) . "</td>";
            echo "<td>" . htmlspecialchars($game['status']) . "</td>";
            echo "<td>" . htmlspecialchars($game['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ No games found in database</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
}
?>
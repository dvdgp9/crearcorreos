<?php
require 'includes/db.php';

try {
    // Delete passwords older than 7 days
    $stmt = $pdo->prepare("DELETE FROM passwords WHERE created_at < NOW() - INTERVAL 7 DAY");
    $stmt->execute();
    echo "Old passwords cleaned up successfully.";
} catch (PDOException $e) {
    die("Error cleaning up old passwords: " . $e->getMessage());
}
?>

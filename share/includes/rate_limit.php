<?php
function isRateLimited($ip, $max_attempts = 5, $timeframe = 300) { // 5 attempts per 5 minutes
    try {
        global $pdo;
        
        // Clean up old entries first
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE timestamp < (NOW() - INTERVAL ? SECOND)");
        $stmt->execute([$timeframe]);
        
        // Count attempts for this IP
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip = ?");
        $stmt->execute([$ip]);
        $attempts = $stmt->fetchColumn();
        
        if ($attempts >= $max_attempts) {
            return true; // Rate limited
        }
        
        // Log this attempt
        $stmt = $pdo->prepare("INSERT INTO rate_limits (ip, timestamp) VALUES (?, NOW())");
        $stmt->execute([$ip]);
        
        return false; // Not rate limited
    } catch (PDOException $e) {
        error_log("Rate limit error: " . $e->getMessage());
        return false; // On error, allow the request
    }
} 
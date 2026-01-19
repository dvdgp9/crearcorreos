<?php
/**
 * Clase para registrar logs de correos creados
 */

class EmailLog {
    
    public static function log(int $userId, string $emailAddress, string $domain, string $status, ?string $errorMessage = null): void {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO email_logs (created_by, email_address, domain, status, error_message) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$userId, $emailAddress, $domain, $status, $errorMessage]);
    }
    
    public static function getRecent(int $limit = 20): array {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            SELECT el.*, u.email as created_by_email 
            FROM email_logs el 
            JOIN users u ON el.created_by = u.id 
            ORDER BY el.created_at DESC 
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public static function getByUser(int $userId, int $limit = 50): array {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            SELECT * FROM email_logs 
            WHERE created_by = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}

<?php
/**
 * Clase para registrar logs de correos creados
 */

class EmailLog {
    private static ?bool $hasActionTypeColumn = null;
    
    public static function log(
        int $userId,
        string $emailAddress,
        string $domain,
        string $status,
        ?string $errorMessage = null,
        string $actionType = 'create'
    ): void {
        $db = Database::getConnection();

        if (self::hasActionTypeColumn($db)) {
            $stmt = $db->prepare("
                INSERT INTO email_logs (created_by, email_address, domain, status, error_message, action_type)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([$userId, $emailAddress, $domain, $status, $errorMessage, $actionType]);
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO email_logs (created_by, email_address, domain, status, error_message)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([$userId, $emailAddress, $domain, $status, $errorMessage]);
    }
    
    public static function getRecent(int $limit = 20): array {
        $db = Database::getConnection();

        $actionTypeSelect = self::hasActionTypeColumn($db)
            ? 'el.action_type'
            : "'create' AS action_type";

        $stmt = $db->prepare("
            SELECT el.*, {$actionTypeSelect}, u.email as created_by_email
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

        $actionTypeSelect = self::hasActionTypeColumn($db)
            ? 'action_type'
            : "'create' AS action_type";

        $stmt = $db->prepare("
            SELECT *, {$actionTypeSelect}
            FROM email_logs
            WHERE created_by = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    private static function hasActionTypeColumn(PDO $db): bool {
        if (self::$hasActionTypeColumn !== null) {
            return self::$hasActionTypeColumn;
        }

        $stmt = $db->query("SHOW COLUMNS FROM email_logs LIKE 'action_type'");
        self::$hasActionTypeColumn = (bool) $stmt->fetch();

        return self::$hasActionTypeColumn;
    }
}

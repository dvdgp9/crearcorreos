<?php
/**
 * Clase de autenticación
 */

class Auth {
    
    public static function login(string $email, string $password): bool {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT id, email, password_hash, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false;
        }
        
        if (!$user['is_active']) {
            return false;
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        
        // Actualizar último login
        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Iniciar sesión
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        
        return true;
    }
    
    public static function logout(): void {
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public static function isLoggedIn(): bool {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // Verificar tiempo de sesión
        if (isset($_SESSION['login_time'])) {
            if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
                self::logout();
                return false;
            }
        }
        
        return true;
    }
    
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: index.php');
            exit;
        }
    }
    
    public static function getUserId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
    
    public static function getUserEmail(): ?string {
        return $_SESSION['user_email'] ?? null;
    }
    
    // ============================================
    // MÉTODOS DE GESTIÓN DE USUARIOS (ADMIN)
    // ============================================
    
    /**
     * Obtener todos los usuarios
     */
    public static function getAllUsers(): array {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, email, created_at, last_login, is_active FROM users ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener usuario por ID
     */
    public static function getUserById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, email, created_at, last_login, is_active FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
    
    /**
     * Crear nuevo usuario
     */
    public static function createUser(string $email, string $password): array {
        // Validar email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email no válido'];
        }
        
        // Validar contraseña
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        }
        
        $db = Database::getConnection();
        
        // Verificar si email ya existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'El email ya está registrado'];
        }
        
        // Crear usuario
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
        
        try {
            $stmt->execute([$email, $hash]);
            return ['success' => true, 'id' => $db->lastInsertId()];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Error al crear usuario: ' . $e->getMessage()];
        }
    }
    
    /**
     * Actualizar email de usuario
     */
    public static function updateUser(int $id, string $email): array {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email no válido'];
        }
        
        $db = Database::getConnection();
        
        // Verificar si email ya existe en otro usuario
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'El email ya está en uso por otro usuario'];
        }
        
        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        try {
            $stmt->execute([$email, $id]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()];
        }
    }
    
    /**
     * Cambiar contraseña de usuario
     */
    public static function updatePassword(int $id, string $password): array {
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        }
        
        $db = Database::getConnection();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        
        try {
            $stmt->execute([$hash, $id]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Error al cambiar contraseña: ' . $e->getMessage()];
        }
    }
    
    /**
     * Activar/Desactivar usuario
     */
    public static function toggleUserStatus(int $id): array {
        $db = Database::getConnection();
        
        // No permitir desactivarse a sí mismo
        if ($id === self::getUserId()) {
            return ['success' => false, 'error' => 'No puedes desactivar tu propio usuario'];
        }
        
        $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
        try {
            $stmt->execute([$id]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Error al cambiar estado: ' . $e->getMessage()];
        }
    }
    
    /**
     * Eliminar usuario permanentemente
     */
    public static function deleteUser(int $id): array {
        $db = Database::getConnection();
        
        // No permitir eliminarse a sí mismo
        if ($id === self::getUserId()) {
            return ['success' => false, 'error' => 'No puedes eliminar tu propio usuario'];
        }
        
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        try {
            $stmt->execute([$id]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()];
        }
    }
}


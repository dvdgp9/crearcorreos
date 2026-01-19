<?php
/**
 * Clase para generar enlaces seguros de compartir contraseñas
 * Integración con el sistema de passwords.ebone.es
 */

class PasswordShare {
    private PDO $pdo;
    private string $encryptionKey;
    private string $baseUrl = 'https://passwords.ebone.es/share/retrieve.php?hash=';
    
    // Configuración de la BD de passwords (separada de la BD principal)
    private const DB_HOST = 'localhost';
    private const DB_NAME = 'passworddb';
    private const DB_USER = 'passuser';
    private const DB_PASS = 'userpassdb';
    private const ENCRYPTION_KEY = 'j$4K36fu6!';
    
    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . self::DB_HOST . ";dbname=" . self::DB_NAME . ";charset=utf8mb4",
                self::DB_USER,
                self::DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->encryptionKey = self::ENCRYPTION_KEY;
        } catch (PDOException $e) {
            throw new Exception("Error conectando a BD de passwords: " . $e->getMessage());
        }
    }
    
    /**
     * Genera un enlace seguro para compartir una contraseña
     * 
     * @param string $password La contraseña a compartir
     * @param string $email Email opcional para enviar notificación
     * @return string El enlace generado
     */
    public function createShareLink(string $password, string $email = ''): string {
        // Generar hash único
        $linkHash = hash('sha256', uniqid(mt_rand(), true));
        
        // Encriptar contraseña
        $cipherMethod = 'AES-256-CBC';
        $ivLength = openssl_cipher_iv_length($cipherMethod);
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        $encryptedPassword = openssl_encrypt(
            $password,
            $cipherMethod,
            $this->encryptionKey,
            0,
            $iv
        );
        
        $ivEncoded = base64_encode($iv);
        
        // Insertar en BD
        $stmt = $this->pdo->prepare("
            INSERT INTO passwords (password, iv, link_hash, email)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $encryptedPassword,
            $ivEncoded,
            $linkHash,
            $email
        ]);
        
        return $this->baseUrl . $linkHash;
    }
    
    /**
     * Genera enlaces para múltiples contraseñas
     * 
     * @param array $passwords Array de ['email' => 'x@y.com', 'password' => 'abc123']
     * @return array Array con los enlaces generados
     */
    public function createBulkShareLinks(array $passwords): array {
        $results = [];
        
        foreach ($passwords as $item) {
            try {
                $link = $this->createShareLink($item['password'], '');
                $results[] = [
                    'email' => $item['email'],
                    'password' => $item['password'],
                    'link' => $link
                ];
            } catch (Exception $e) {
                $results[] = [
                    'email' => $item['email'],
                    'password' => $item['password'],
                    'link' => null,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
}

<?php
/**
 * Archivo de inicialización - incluir en todos los archivos públicos
 */

// Iniciar sesión
session_start();

// Cargar configuración
require_once __DIR__ . '/../config/config.php';

// Cargar clases
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/PleskApi.php';
require_once __DIR__ . '/EmailLog.php';
require_once __DIR__ . '/PasswordShare.php';

// Función helper para escapar HTML
function e(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Función para mostrar mensajes flash
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

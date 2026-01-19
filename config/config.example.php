<?php
/**
 * Ejemplo de configuración - Copiar a config.php y rellenar valores
 */

// Configuración de Base de Datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'tu_base_de_datos');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');

// Configuración de Plesk API
define('PLESK_HOST', 'https://tu-servidor-plesk:8443');
define('PLESK_API_KEY', 'tu-api-key-aqui');

// Configuración de la aplicación
define('APP_NAME', 'Generador de Correos');
define('APP_URL', 'https://tu-dominio.com');

// Configuración de sesión
define('SESSION_LIFETIME', 3600); // 1 hora

// Zona horaria
date_default_timezone_set('Europe/Madrid');

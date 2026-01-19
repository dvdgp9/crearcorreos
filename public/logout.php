<?php
/**
 * Cerrar sesión
 */

require_once __DIR__ . '/../includes/init.php';

Auth::logout();

header('Location: index.php');
exit;

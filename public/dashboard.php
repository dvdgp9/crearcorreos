<?php
/**
 * Dashboard - Panel principal para crear correos
 */

require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$plesk = new PleskApi();
$domains = [];
$error = '';
$success = '';

// Obtener dominios disponibles
try {
    $domains = $plesk->getDomains();
} catch (Exception $e) {
    $error = 'Error al conectar con Plesk: ' . $e->getMessage();
}

// Obtener mensaje flash
$flash = getFlash();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

// Procesar creación de correo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_email') {
    $username = trim($_POST['username'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validaciones
    if (empty($username) || empty($domain) || empty($password)) {
        $error = 'Todos los campos son obligatorios';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $error = 'El nombre de usuario solo puede contener letras, números, puntos, guiones y guiones bajos';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres';
    } elseif ($password !== $confirmPassword) {
        $error = 'Las contraseñas no coinciden';
    } else {
        $emailAddress = $username . '@' . $domain;
        
        try {
            $result = $plesk->createMailbox($emailAddress, $password);
            
            // Registrar en log
            EmailLog::log(
                Auth::getUserId(),
                $emailAddress,
                $domain,
                'success'
            );
            
            setFlash('success', "Correo <strong>{$emailAddress}</strong> creado correctamente");
            header('Location: dashboard.php');
            exit;
            
        } catch (Exception $e) {
            $error = 'Error al crear el correo: ' . $e->getMessage();
            
            // Registrar error en log
            EmailLog::log(
                Auth::getUserId(),
                $emailAddress,
                $domain,
                'error',
                $e->getMessage()
            );
        }
    }
}

// Obtener últimos correos creados
$recentEmails = [];
try {
    $recentEmails = EmailLog::getRecent(10);
} catch (Exception $e) {
    // Silenciar error de logs
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1><?= e(APP_NAME) ?></h1>
            <nav>
                <span class="user-info"><?= e(Auth::getUserEmail()) ?></span>
                <a href="logout.php" class="btn btn-sm btn-outline">Cerrar sesión</a>
            </nav>
        </div>
    </header>
    
    <main class="main-content">
        <div class="container">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= $error ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>
            
            <div class="grid">
                <!-- Formulario de creación -->
                <div class="card">
                    <h2>Crear nueva cuenta de correo</h2>
                    
                    <form method="POST" action="" class="form-create-email">
                        <input type="hidden" name="action" value="create_email">
                        
                        <div class="form-group">
                            <label for="username">Usuario</label>
                            <div class="input-group">
                                <input type="text" id="username" name="username" required 
                                       pattern="[a-zA-Z0-9._-]+"
                                       placeholder="nombre.usuario"
                                       value="<?= e($_POST['username'] ?? '') ?>">
                                <span class="input-addon">@</span>
                                <select name="domain" id="domain" required>
                                    <option value="">Selecciona dominio</option>
                                    <?php foreach ($domains as $domain): ?>
                                        <option value="<?= e($domain['name']) ?>" 
                                            <?= (($_POST['domain'] ?? '') === $domain['name']) ? 'selected' : '' ?>>
                                            <?= e($domain['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">Contraseña</label>
                                <input type="password" id="password" name="password" required 
                                       minlength="8" placeholder="Mínimo 8 caracteres">
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirmar contraseña</label>
                                <input type="password" id="confirm_password" name="confirm_password" required 
                                       minlength="8" placeholder="Repite la contraseña">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="button" class="btn btn-sm btn-outline" onclick="generatePassword()">
                                Generar contraseña segura
                            </button>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-block">
                            Crear cuenta de correo
                        </button>
                    </form>
                </div>
                
                <!-- Últimos correos creados -->
                <div class="card">
                    <h2>Últimos correos creados</h2>
                    
                    <?php if (empty($recentEmails)): ?>
                        <p class="text-muted">No hay correos creados todavía</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Correo</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEmails as $log): ?>
                                    <tr>
                                        <td><?= e($log['email_address']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= $log['status'] === 'success' ? 'success' : 'error' ?>">
                                                <?= $log['status'] === 'success' ? 'Creado' : 'Error' ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script>
        function generatePassword() {
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";
            for (let i = 0; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            document.getElementById('password').value = password;
            document.getElementById('confirm_password').value = password;
            
            // Mostrar contraseña temporalmente
            document.getElementById('password').type = 'text';
            document.getElementById('confirm_password').type = 'text';
            setTimeout(() => {
                document.getElementById('password').type = 'password';
                document.getElementById('confirm_password').type = 'password';
            }, 3000);
        }
    </script>
</body>
</html>

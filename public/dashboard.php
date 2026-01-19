<?php
/**
 * Dashboard - Panel principal para crear correos
 */

require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$plesk = new PleskApi();
$passwordShare = new PasswordShare();
$domains = [];
$error = '';
$success = '';
$createdEmails = []; // Para almacenar correos recién creados con contraseñas y enlaces

// Función para generar contraseña segura
function generateSecurePassword(int $length = 12): string {
    $lower = 'abcdefghijklmnopqrstuvwxyz';
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    $special = '!@#$%&*';
    
    $password = '';
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $upper[random_int(0, strlen($upper) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];
    
    $allChars = $lower . $upper . $numbers . $special;
    for ($i = 4; $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    
    return str_shuffle($password);
}

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

// Procesar creación masiva de correos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_bulk') {
    $usernames = trim($_POST['usernames'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $quota = trim($_POST['quota'] ?? '');
    $outgoingLimit = $_POST['outgoing_limit'] ?? '';
    
    if (empty($usernames) || empty($domain)) {
        $error = 'Debes especificar al menos un usuario y un dominio';
    } else {
        // Parsear usuarios (uno por línea o separados por coma)
        $userList = preg_split('/[\n,]+/', $usernames);
        $userList = array_map('trim', $userList);
        $userList = array_filter($userList); // Eliminar vacíos
        
        if (empty($userList)) {
            $error = 'No se encontraron usuarios válidos';
        } else {
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            // Convertir límite de salida a int o null
            $outgoingLimitInt = null;
            if ($outgoingLimit !== '' && $outgoingLimit !== 'default') {
                $outgoingLimitInt = (int) $outgoingLimit;
            }
            
            // Convertir cuota vacía a null
            $quotaValue = !empty($quota) ? $quota : null;
            
            foreach ($userList as $username) {
                // Validar nombre de usuario
                if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                    $errors[] = "Usuario inválido: {$username}";
                    $errorCount++;
                    continue;
                }
                
                $emailAddress = $username . '@' . $domain;
                $password = generateSecurePassword(12);
                
                try {
                    $result = $plesk->createMailbox($emailAddress, $password, $quotaValue, $outgoingLimitInt);
                    
                    // Generar enlace seguro para compartir
                    $shareLink = null;
                    try {
                        $shareLink = $passwordShare->createShareLink($password);
                    } catch (Exception $linkError) {
                        // Si falla el enlace, continuamos sin él
                    }
                    
                    // Guardar para mostrar al usuario
                    $createdEmails[] = [
                        'email' => $emailAddress,
                        'password' => $password,
                        'link' => $shareLink
                    ];
                    
                    // Registrar en log
                    EmailLog::log(
                        Auth::getUserId(),
                        $emailAddress,
                        $domain,
                        'success'
                    );
                    
                    $successCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "{$emailAddress}: " . $e->getMessage();
                    $errorCount++;
                    
                    EmailLog::log(
                        Auth::getUserId(),
                        $emailAddress,
                        $domain,
                        'error',
                        $e->getMessage()
                    );
                }
            }
            
            if ($successCount > 0) {
                $success = "Se crearon <strong>{$successCount}</strong> correo(s) correctamente.";
            }
            if ($errorCount > 0) {
                $error = "Hubo <strong>{$errorCount}</strong> error(es): " . implode('; ', $errors);
            }
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
            
            <?php if (!empty($createdEmails)): ?>
                <!-- Listado de correos creados con contraseñas y enlaces -->
                <div class="card card-highlight">
                    <h2>✅ Correos creados - ¡Comparte los enlaces!</h2>
                    <p class="text-muted">Los enlaces son de un solo uso. Una vez abiertos, la contraseña se elimina.</p>
                    
                    <div class="copy-all-container">
                        <button type="button" class="btn btn-sm btn-primary" onclick="copyAllToClipboard()">
                            📋 Copiar todo (email + enlace)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyAllWithPasswords()">
                            📋 Con contraseñas
                        </button>
                    </div>
                    
                    <table class="table table-passwords" id="passwords-table">
                        <thead>
                            <tr>
                                <th>Correo</th>
                                <th>Enlace seguro</th>
                                <th>Contraseña</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($createdEmails as $created): ?>
                                <tr data-email="<?= e($created['email']) ?>" 
                                    data-password="<?= e($created['password']) ?>" 
                                    data-link="<?= e($created['link'] ?? '') ?>">
                                    <td><code><?= e($created['email']) ?></code></td>
                                    <td>
                                        <?php if ($created['link']): ?>
                                            <a href="<?= e($created['link']) ?>" target="_blank" class="share-link">
                                                🔗 Abrir enlace
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No disponible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="password-text"><?= e($created['password']) ?></code></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline" 
                                                onclick="copyRow(this.closest('tr'))">
                                            Copiar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <div class="grid">
                <!-- Formulario de creación masiva -->
                <div class="card">
                    <h2>Crear cuentas de correo</h2>
                    <p class="text-muted">Introduce uno o más usuarios (uno por línea o separados por coma)</p>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create_bulk">
                        
                        <div class="form-group">
                            <label for="usernames">Usuarios</label>
                            <textarea id="usernames" name="usernames" rows="4" required 
                                      placeholder="usuario1&#10;usuario2&#10;nombre.apellido"><?= e($_POST['usernames'] ?? '') ?></textarea>
                            <small class="text-muted">Solo la parte antes del @. Se generará contraseña automática.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="domain">Dominio</label>
                            <select name="domain" id="domain" required>
                                <option value="">Selecciona dominio</option>
                                <?php foreach ($domains as $d): ?>
                                    <option value="<?= e($d['name']) ?>" 
                                        <?= (($_POST['domain'] ?? '') === $d['name']) ? 'selected' : '' ?>>
                                        @<?= e($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quota">Tamaño máximo buzón</label>
                                <select name="quota" id="quota">
                                    <option value="">Por defecto del servidor</option>
                                    <option value="100M" <?= (($_POST['quota'] ?? '') === '100M') ? 'selected' : '' ?>>100 MB</option>
                                    <option value="250M" <?= (($_POST['quota'] ?? '') === '250M') ? 'selected' : '' ?>>250 MB</option>
                                    <option value="500M" <?= (($_POST['quota'] ?? '') === '500M') ? 'selected' : '' ?>>500 MB</option>
                                    <option value="1G" <?= (($_POST['quota'] ?? '') === '1G') ? 'selected' : '' ?>>1 GB</option>
                                    <option value="2G" <?= (($_POST['quota'] ?? '') === '2G') ? 'selected' : '' ?>>2 GB</option>
                                    <option value="5G" <?= (($_POST['quota'] ?? '') === '5G') ? 'selected' : '' ?>>5 GB</option>
                                    <option value="-1" <?= (($_POST['quota'] ?? '') === '-1') ? 'selected' : '' ?>>Ilimitado</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="outgoing_limit">Límite emails salientes/hora</label>
                                <select name="outgoing_limit" id="outgoing_limit">
                                    <option value="">Por defecto del servidor</option>
                                    <option value="50" <?= (($_POST['outgoing_limit'] ?? '') === '50') ? 'selected' : '' ?>>50/hora</option>
                                    <option value="100" <?= (($_POST['outgoing_limit'] ?? '') === '100') ? 'selected' : '' ?>>100/hora</option>
                                    <option value="200" <?= (($_POST['outgoing_limit'] ?? '') === '200') ? 'selected' : '' ?>>200/hora</option>
                                    <option value="500" <?= (($_POST['outgoing_limit'] ?? '') === '500') ? 'selected' : '' ?>>500/hora</option>
                                    <option value="-1" <?= (($_POST['outgoing_limit'] ?? '') === '-1') ? 'selected' : '' ?>>Ilimitado</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-block">
                            Crear cuenta(s) de correo
                        </button>
                    </form>
                </div>
                
                <!-- Últimos correos creados -->
                <div class="card">
                    <h2>Historial reciente</h2>
                    
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
                                                <?= $log['status'] === 'success' ? 'OK' : 'Error' ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m H:i', strtotime($log['created_at'])) ?></td>
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
        function copyRow(row) {
            const email = row.dataset.email;
            const link = row.dataset.link;
            const text = email + '\t' + (link || 'Sin enlace');
            navigator.clipboard.writeText(text).then(() => {
                alert('Copiado: ' + email);
            });
        }
        
        function copyAllToClipboard() {
            const table = document.getElementById('passwords-table');
            const rows = table.querySelectorAll('tbody tr');
            let text = '';
            
            rows.forEach(row => {
                const email = row.dataset.email;
                const link = row.dataset.link;
                text += email + '\t' + (link || 'Sin enlace') + '\n';
            });
            
            navigator.clipboard.writeText(text.trim()).then(() => {
                alert('¡Copiados ' + rows.length + ' correos!\n\nFormato: email[TAB]enlace\n\nComparte directamente con tus compañeros.');
            });
        }
        
        function copyAllWithPasswords() {
            const table = document.getElementById('passwords-table');
            const rows = table.querySelectorAll('tbody tr');
            let text = '';
            
            rows.forEach(row => {
                const email = row.dataset.email;
                const password = row.dataset.password;
                const link = row.dataset.link;
                text += email + '\t' + password + '\t' + (link || '') + '\n';
            });
            
            navigator.clipboard.writeText(text.trim()).then(() => {
                alert('¡Copiados ' + rows.length + ' correos con contraseñas!\n\nFormato: email[TAB]contraseña[TAB]enlace');
            });
        }
    </script>
</body>
</html>

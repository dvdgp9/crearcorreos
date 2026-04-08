<?php
/**
 * Dashboard - Panel principal para crear y gestionar correos
 */

require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

/**
 * Función para generar contraseña segura
 */
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

/**
 * @return string[]
 */
function parseUsernames(string $usernames): array {
    $userList = preg_split('/[\n,]+/', $usernames);
    $userList = array_map('trim', $userList);
    return array_values(array_filter($userList));
}

function normalizeUsernameInput(string $value, string $selectedDomain): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (strpos($value, '@') === false) {
        return $value;
    }

    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return $value;
    }

    [$mailbox, $domain] = explode('@', strtolower($value), 2);
    if ($selectedDomain !== '' && strtolower($selectedDomain) !== $domain) {
        return $value;
    }

    return $mailbox;
}

/**
 * @return array<string, string>
 */
function getQuotaOptions(): array {
    return [
        '100M' => '100 MB',
        '250M' => '250 MB',
        '500M' => '500 MB',
        '1G' => '1 GB',
        '2G' => '2 GB',
        '5G' => '5 GB',
        '-1' => 'Ilimitado / valor por defecto'
    ];
}

/**
 * @return array<string, string>
 */
function getOutgoingLimitOptions(): array {
    return [
        '50' => '50/hora',
        '100' => '100/hora',
        '200' => '200/hora',
        '500' => '500/hora',
        '-1' => 'Ilimitado'
    ];
}

function formatMailboxValue(?string $value, string $fallback = 'No disponible'): string {
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    return trim($value);
}

function formatQuotaDisplay(?string $value): string {
    $value = formatMailboxValue($value);
    if ($value === 'No disponible') {
        return $value;
    }

    if ($value === '-1') {
        return 'Ilimitado';
    }

    if (!ctype_digit($value)) {
        return $value;
    }

    $bytes = (float) $value;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;

    while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
        $bytes /= 1024;
        $unitIndex++;
    }

    $formatted = $bytes >= 10 || $unitIndex === 0
        ? number_format($bytes, 0)
        : number_format($bytes, 1);

    return rtrim(rtrim($formatted, '0'), '.') . ' ' . $units[$unitIndex];
}

function formatOutgoingLimitDisplay(?string $value): string {
    $value = formatMailboxValue($value);
    if ($value === 'No disponible') {
        return $value;
    }

    return $value === '-1' ? 'Ilimitado' : $value;
}

function getActionLabel(string $actionType): string {
    $labels = [
        'create' => 'Creacion',
        'password_reset' => 'Reset',
        'update' => 'Edicion',
        'delete' => 'Borrado'
    ];

    return $labels[$actionType] ?? ucfirst($actionType);
}

function getActionBadgeClass(string $actionType): string {
    $classes = [
        'create' => 'badge-info',
        'password_reset' => 'badge-warning',
        'update' => 'badge-outline',
        'delete' => 'badge-error'
    ];

    return $classes[$actionType] ?? 'badge-outline';
}

function getRowId(string $email): string {
    return 'mailbox-' . substr(md5($email), 0, 12);
}

$plesk = new PleskApi();
$passwordShare = new PasswordShare();

$domains = [];
$error = '';
$success = '';
$createdEmails = [];
$validationResults = [];
$managedMailboxes = [];
$lastResetResult = null;
$manageDomain = trim($_POST['manage_domain'] ?? '');
$action = $_POST['action'] ?? '';
$activeTab = in_array($action, ['load_mailboxes', 'reset_password', 'update_mailbox', 'delete_mailbox'], true) || $manageDomain !== ''
    ? 'manage'
    : 'create';

try {
    $domains = $plesk->getDomains();
} catch (Exception $e) {
    $error = 'Error al conectar con Plesk: ' . $e->getMessage();
}

$flash = getFlash();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'validate') {
    $usernames = trim($_POST['usernames'] ?? '');
    $domain = trim($_POST['domain'] ?? '');

    if ($usernames === '' || $domain === '') {
        $error = 'Debes especificar al menos un usuario y un dominio';
    } else {
        $userList = parseUsernames($usernames);

        if (empty($userList)) {
            $error = 'No se encontraron usuarios válidos';
        } else {
            try {
                $existingEmails = $plesk->getExistingMailboxes($domain);

                foreach ($userList as $rawUsername) {
                    $username = normalizeUsernameInput($rawUsername, $domain);
                    $emailAddress = strtolower($username . '@' . $domain);

                    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                        $validationResults[] = [
                            'username' => $rawUsername,
                            'email' => strpos($rawUsername, '@') !== false ? strtolower($rawUsername) : $emailAddress,
                            'status' => 'invalid',
                            'message' => 'Caracteres no permitidos'
                        ];
                    } elseif (in_array($emailAddress, $existingEmails, true)) {
                        $validationResults[] = [
                            'username' => $username,
                            'email' => $emailAddress,
                            'status' => 'exists',
                            'message' => 'Ya existe en Plesk'
                        ];
                    } else {
                        $validationResults[] = [
                            'username' => $username,
                            'email' => $emailAddress,
                            'status' => 'new',
                            'message' => 'Disponible para crear'
                        ];
                    }
                }
            } catch (Exception $e) {
                $error = 'Error al consultar correos existentes: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_bulk') {
    $usernames = trim($_POST['usernames'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $quota = trim($_POST['quota'] ?? '');
    $outgoingLimit = $_POST['outgoing_limit'] ?? '';

    if ($usernames === '' || $domain === '') {
        $error = 'Debes especificar al menos un usuario y un dominio';
    } else {
        $userList = parseUsernames($usernames);

        if (empty($userList)) {
            $error = 'No se encontraron usuarios válidos';
        } else {
            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            $outgoingLimitInt = null;
            if ($outgoingLimit !== '' && $outgoingLimit !== 'default') {
                $outgoingLimitInt = (int) $outgoingLimit;
            }

            $quotaValue = $quota !== '' ? $quota : null;

            foreach ($userList as $rawUsername) {
                $username = normalizeUsernameInput($rawUsername, $domain);

                if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                    $errors[] = "Usuario inválido: {$rawUsername}";
                    $errorCount++;
                    continue;
                }

                $emailAddress = strtolower($username . '@' . $domain);
                $password = generateSecurePassword(12);

                try {
                    $plesk->createMailbox($emailAddress, $password, $quotaValue, $outgoingLimitInt);

                    $shareLink = null;
                    try {
                        $shareLink = $passwordShare->createShareLink($password);
                    } catch (Exception $linkError) {
                        $shareLink = null;
                    }

                    $createdEmails[] = [
                        'email' => $emailAddress,
                        'password' => $password,
                        'link' => $shareLink
                    ];

                    EmailLog::log(
                        Auth::getUserId(),
                        $emailAddress,
                        $domain,
                        'success',
                        null,
                        'create'
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
                        $e->getMessage(),
                        'create'
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['load_mailboxes', 'reset_password', 'update_mailbox', 'delete_mailbox'], true)) {
    if ($manageDomain === '') {
        $error = 'Debes seleccionar un dominio para gestionar cuentas';
    } else {
        try {
            if ($action === 'reset_password') {
                $email = strtolower(trim($_POST['email'] ?? ''));
                $lastResetResult = $plesk->resetMailboxPassword($email, $passwordShare);

                [$mailbox, $domain] = explode('@', $email, 2);
                unset($mailbox);

                EmailLog::log(
                    Auth::getUserId(),
                    $email,
                    $domain,
                    'success',
                    $lastResetResult['share_link'] ? null : 'Contraseña actualizada sin enlace seguro',
                    'password_reset'
                );

                $success = $lastResetResult['share_link']
                    ? 'Contraseña restablecida y enlace seguro generado.'
                    : 'Contraseña restablecida, pero no se pudo generar el enlace seguro.';
            }

            if ($action === 'update_mailbox') {
                $email = strtolower(trim($_POST['email'] ?? ''));
                $quotaSelection = $_POST['edit_quota'] ?? '__keep__';
                $outgoingSelection = $_POST['edit_outgoing_limit'] ?? '__keep__';
                $changes = [];

                if ($quotaSelection !== '__keep__') {
                    $changes['quota'] = $quotaSelection;
                }

                if ($outgoingSelection !== '__keep__') {
                    $changes['outgoing_limit'] = (int) $outgoingSelection;
                }

                if (empty($changes)) {
                    throw new Exception('Selecciona al menos un cambio antes de guardar');
                }

                $plesk->updateMailbox($email, $changes);
                [, $domain] = explode('@', $email, 2);

                EmailLog::log(
                    Auth::getUserId(),
                    $email,
                    $domain,
                    'success',
                    null,
                    'update'
                );

                $success = 'La cuenta se ha actualizado correctamente.';
            }

            if ($action === 'delete_mailbox') {
                $email = strtolower(trim($_POST['email'] ?? ''));
                $plesk->deleteMailbox($email);
                [, $domain] = explode('@', $email, 2);

                EmailLog::log(
                    Auth::getUserId(),
                    $email,
                    $domain,
                    'success',
                    null,
                    'delete'
                );

                $success = 'La cuenta se ha eliminado correctamente.';
            }
        } catch (Exception $e) {
            $emailForLog = strtolower(trim($_POST['email'] ?? ''));
            if ($emailForLog !== '' && strpos($emailForLog, '@') !== false) {
                [, $logDomain] = explode('@', $emailForLog, 2);
                $actionType = $action === 'reset_password' ? 'password_reset' : ($action === 'update_mailbox' ? 'update' : 'delete');

                try {
                    EmailLog::log(
                        Auth::getUserId(),
                        $emailForLog,
                        $logDomain,
                        'error',
                        $e->getMessage(),
                        $actionType
                    );
                } catch (Exception $logError) {
                    // No bloquear al usuario si falla el log
                }
            }

            $error = $e->getMessage();
        }

        try {
            $managedMailboxes = $plesk->getMailboxesByDomain($manageDomain);
        } catch (Exception $e) {
            $error = $error !== '' ? $error : 'No se pudo cargar el listado de correos: ' . $e->getMessage();
        }
    }
}

$recentCreatedEmails = [];
$recentManageActions = [];
try {
    $recentCreatedEmails = EmailLog::getRecentByActionTypes(['create'], 10);
    $recentManageActions = EmailLog::getRecentByActionTypes(['password_reset', 'update', 'delete'], 10);
} catch (Exception $e) {
    // Silenciar error de logs
}

$quotaOptions = getQuotaOptions();
$outgoingLimitOptions = getOutgoingLimitOptions();
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
                <a href="admin/users.php" class="btn btn-sm btn-outline">⚙️ Admin</a>
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

            <div id="ajax-feedback"></div>

            <?php if (!empty($validationResults)): ?>
                <?php
                    $newCount = count(array_filter($validationResults, fn($r) => $r['status'] === 'new'));
                    $existsCount = count(array_filter($validationResults, fn($r) => $r['status'] === 'exists'));
                    $invalidCount = count(array_filter($validationResults, fn($r) => $r['status'] === 'invalid'));
                    $validUsernames = array_map(fn($r) => $r['username'], array_filter($validationResults, fn($r) => $r['status'] === 'new'));
                ?>
                <div class="card card-highlight">
                    <h2>Resultado de la validación</h2>
                    <p class="text-muted">
                        <strong><?= $newCount ?></strong> disponible(s) ·
                        <strong style="color: #b45309;"><?= $existsCount ?></strong> ya existe(n) ·
                        <strong style="color: var(--error);"><?= $invalidCount ?></strong> inválido(s)
                    </p>

                    <table class="table">
                        <thead>
                            <tr>
                                <th>Correo</th>
                                <th>Estado</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($validationResults as $vr): ?>
                                <tr>
                                    <td><code><?= e($vr['email']) ?></code></td>
                                    <td>
                                        <?php if ($vr['status'] === 'new'): ?>
                                            <span class="badge badge-success">Nuevo</span>
                                        <?php elseif ($vr['status'] === 'exists'): ?>
                                            <span class="badge badge-warning">Ya existe</span>
                                        <?php else: ?>
                                            <span class="badge badge-error">Inválido</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?= e($vr['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($newCount > 0): ?>
                        <form method="POST" action="" style="margin-top: 1rem;">
                            <input type="hidden" name="action" value="create_bulk">
                            <input type="hidden" name="usernames" value="<?= e(implode("\n", $validUsernames)) ?>">
                            <input type="hidden" name="domain" value="<?= e($_POST['domain'] ?? '') ?>">
                            <input type="hidden" name="quota" value="<?= e($_POST['quota'] ?? '') ?>">
                            <input type="hidden" name="outgoing_limit" value="<?= e($_POST['outgoing_limit'] ?? '') ?>">
                            <button type="submit" class="btn btn-primary">
                                Crear <?= $newCount ?> correo(s) disponible(s)
                            </button>
                        </form>
                    <?php else: ?>
                        <p style="margin-top: 1rem;"><strong>No hay correos nuevos que crear.</strong></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($createdEmails)): ?>
                <div class="card card-highlight">
                    <h2>Correos creados</h2>
                    <p class="text-muted">Los enlaces son de un solo uso. Una vez abiertos, la contraseña se elimina.</p>

                    <div class="copy-all-container">
                        <button type="button" class="btn btn-sm btn-primary" onclick="copyAllRows('passwords-table', false)">
                            Copiar todo (email + enlace)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyAllRows('passwords-table', true)">
                            Copiar con contraseñas
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
                                            <a href="<?= e($created['link']) ?>" target="_blank" class="share-link" rel="noopener noreferrer">Abrir enlace</a>
                                        <?php else: ?>
                                            <span class="text-muted">No disponible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="password-text"><?= e($created['password']) ?></code></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline" onclick="copyRow(this.closest('tr'), true)">
                                            Copiar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div id="reset-result-region">
            <?php if ($lastResetResult): ?>
                <div class="card card-highlight management-highlight" id="reset-result-card">
                    <h2>Nuevo acceso generado</h2>
                    <p class="text-muted">Usa este bloque para copiar rápidamente el enlace o la contraseña recién creada.</p>

                    <div class="result-grid">
                        <div>
                            <span class="result-label">Correo</span>
                            <code><?= e($lastResetResult['email']) ?></code>
                        </div>
                        <div>
                            <span class="result-label">Contraseña</span>
                            <code><?= e($lastResetResult['password']) ?></code>
                        </div>
                        <div>
                            <span class="result-label">Enlace seguro</span>
                            <?php if ($lastResetResult['share_link']): ?>
                                <a href="<?= e($lastResetResult['share_link']) ?>" target="_blank" class="share-link" rel="noopener noreferrer">
                                    <?= e($lastResetResult['share_link']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">No disponible</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="copy-all-container">
                        <?php if ($lastResetResult['share_link']): ?>
                            <button type="button" class="btn btn-sm btn-primary" onclick="copyText('<?= e($lastResetResult['email'] . "\t" . $lastResetResult['share_link']) ?>', 'Correo y enlace copiados')">
                                Copiar email + enlace
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="copyText('<?= e($lastResetResult['share_link']) ?>', 'Enlace copiado')">
                                Copiar solo enlace
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyText('<?= e($lastResetResult['email'] . "\t" . $lastResetResult['password']) ?>', 'Correo y contraseña copiados')">
                            Copiar email + contraseña
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            </div>

            <div class="dashboard-tabs" role="tablist" aria-label="Secciones del dashboard">
                <button type="button" class="dashboard-tab <?= $activeTab === 'create' ? 'is-active' : '' ?>" data-tab-trigger="create">
                    Crear cuentas
                </button>
                <button type="button" class="dashboard-tab <?= $activeTab === 'manage' ? 'is-active' : '' ?>" data-tab-trigger="manage">
                    Gestionar cuentas
                </button>
            </div>

            <section class="dashboard-section tab-panel <?= $activeTab === 'create' ? 'is-active' : '' ?>" data-tab-panel="create">
                <div class="section-heading">
                    <span class="section-kicker">Alta masiva</span>
                    <h2>Crear cuentas nuevas</h2>
                    <p class="text-muted">Valida usuarios antes de crear y comparte los enlaces seguros al terminar.</p>
                </div>

                <div class="card">
                    <form method="POST" action="" id="emailForm">
                        <input type="hidden" name="action" id="formAction" value="validate">

                        <div class="form-group">
                            <label for="usernames">Usuarios</label>
                            <textarea id="usernames" name="usernames" rows="4" required placeholder="usuario1&#10;usuario2&#10;nombre.apellido"><?= e($_POST['usernames'] ?? '') ?></textarea>
                            <small class="text-muted">Solo la parte antes del @. Se generará contraseña automática.</small>
                        </div>

                        <div class="form-group">
                            <label for="domain">Dominio</label>
                            <select name="domain" id="domain" required>
                                <option value="">Selecciona dominio</option>
                                <?php foreach ($domains as $d): ?>
                                    <option value="<?= e($d['name']) ?>" <?= (($_POST['domain'] ?? '') === $d['name']) ? 'selected' : '' ?>>
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
                                    <?php foreach ($quotaOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= (($_POST['quota'] ?? '2G') === $value) ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="outgoing_limit">Límite emails salientes/hora</label>
                                <select name="outgoing_limit" id="outgoing_limit">
                                    <option value="">Por defecto del servidor</option>
                                    <?php foreach ($outgoingLimitOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= (($_POST['outgoing_limit'] ?? '50') === $value) ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="action-row">
                            <button type="submit" class="btn btn-primary btn-grow" onclick="document.getElementById('formAction').value='validate'">
                                Validar primero
                            </button>
                            <button type="submit" class="btn btn-outline btn-grow" onclick="document.getElementById('formAction').value='create_bulk'">
                                Crear directamente
                            </button>
                        </div>
                    </form>
                </div>

                <div class="section-heading">
                    <span class="section-kicker">Creados</span>
                    <h2>Historial reciente de altas</h2>
                    <p class="text-muted">Ultimas cuentas creadas desde esta aplicación.</p>
                </div>

                <div class="card">
                    <?php if (empty($recentCreatedEmails)): ?>
                        <p class="text-muted">No hay correos creados todavía.</p>
                    <?php else: ?>
                        <div class="table-scroll">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Correo</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentCreatedEmails as $log): ?>
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
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-section section-management tab-panel <?= $activeTab === 'manage' ? 'is-active' : '' ?>" data-tab-panel="manage">
                <div class="section-heading">
                    <span class="section-kicker section-kicker-management">Gestion</span>
                    <h2>Gestionar cuentas existentes</h2>
                    <p class="text-muted">Carga las cuentas reales de Plesk por dominio para restablecer contraseña, editar límites o borrar cuentas.</p>
                </div>

                <div class="card card-management">
                    <form method="POST" action="" class="manage-toolbar" id="manage-toolbar-form">
                        <input type="hidden" name="action" value="load_mailboxes">

                        <div class="form-group manage-domain-group">
                            <label for="manage_domain">Dominio a gestionar</label>
                            <select name="manage_domain" id="manage_domain" required>
                                <option value="">Selecciona dominio</option>
                                <?php foreach ($domains as $d): ?>
                                    <option value="<?= e($d['name']) ?>" <?= $manageDomain === $d['name'] ? 'selected' : '' ?>>
                                        @<?= e($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">Cargar cuentas</button>
                    </form>

                    <div id="manage-mailboxes-region">
                    <?php if ($manageDomain !== '' && empty($managedMailboxes) && $action === 'load_mailboxes'): ?>
                        <div class="empty-state">
                            <strong>No hay cuentas cargadas para este dominio.</strong>
                            <p class="text-muted">Si el dominio existe en Plesk pero no hay buzones, esta tabla aparecerá vacía.</p>
                        </div>
                    <?php elseif (!empty($managedMailboxes)): ?>
                        <div class="management-summary">
                            <strong><?= count($managedMailboxes) ?></strong> cuenta(s) encontradas en <code>@<?= e($manageDomain) ?></code>
                        </div>

                        <div class="list-toolbar">
                            <div class="list-search">
                                <label for="mailbox-search">Buscar correo</label>
                                <input type="search" id="mailbox-search" placeholder="Filtrar por nombre o dominio" data-list-search="mailboxes-table">
                            </div>
                            <div class="list-page-size">
                                <label for="mailbox-page-size">Mostrar</label>
                                <select id="mailbox-page-size" data-page-size="mailboxes-table">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>

                        <div class="table-scroll">
                            <table class="table" id="mailboxes-table">
                                <thead>
                                    <tr>
                                        <th>Correo</th>
                                        <th>Cuota</th>
                                        <th>Salida/hora</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($managedMailboxes as $mailbox): ?>
                                        <?php $rowId = getRowId($mailbox['email']); ?>
                                        <tr
                                            data-list-row="mailboxes-table"
                                            data-email="<?= e($mailbox['email']) ?>"
                                            data-details-state="pending"
                                            data-search="<?= e(strtolower($mailbox['email'] . ' ' . ($mailbox['quota'] ?? '') . ' ' . ($mailbox['outgoing_limit'] ?? ''))) ?>"
                                        >
                                            <td><code><?= e($mailbox['email']) ?></code></td>
                                            <td data-field="quota"><?= e(formatQuotaDisplay($mailbox['quota'])) ?></td>
                                            <td data-field="outgoing_limit"><?= e(formatOutgoingLimitDisplay($mailbox['outgoing_limit'])) ?></td>
                                            <td>
                                                <div class="table-actions">
                                                    <button type="button" class="btn btn-sm btn-primary" data-ajax-action="reset_password" data-email="<?= e($mailbox['email']) ?>">Restablecer</button>

                                                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleEditForm('<?= e($rowId) ?>')">
                                                        Editar
                                                    </button>

                                                    <button type="button" class="btn btn-sm btn-danger" data-ajax-action="delete_mailbox" data-email="<?= e($mailbox['email']) ?>">Borrar</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr id="<?= e($rowId) ?>" class="edit-row" hidden data-related-row="mailboxes-table">
                                            <td colspan="4">
                                                <form method="POST" action="" class="inline-edit-form" data-ajax-form="update_mailbox">
                                                    <input type="hidden" name="action" value="update_mailbox">
                                                    <input type="hidden" name="manage_domain" value="<?= e($manageDomain) ?>">
                                                    <input type="hidden" name="email" value="<?= e($mailbox['email']) ?>">

                                                    <div class="form-row">
                                                        <div class="form-group">
                                                            <label>Nueva contraseña (opcional)</label>
                                                            <input type="text" name="edit_password" placeholder="Déjalo vacío para no cambiar">
                                                        </div>

                                                        <div class="form-group">
                                                            <label>Cuota del buzón</label>
                                                            <select name="edit_quota">
                                                                <option value="__keep__">Mantener actual (<?= e(formatQuotaDisplay($mailbox['quota'])) ?>)</option>
                                                                <?php foreach ($quotaOptions as $value => $label): ?>
                                                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="form-group">
                                                            <label>Límite de salida</label>
                                                            <select name="edit_outgoing_limit">
                                                                <option value="__keep__">Mantener actual (<?= e(formatOutgoingLimitDisplay($mailbox['outgoing_limit'])) ?>)</option>
                                                                <?php foreach ($outgoingLimitOptions as $value => $label): ?>
                                                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="action-row">
                                                        <button type="submit" class="btn btn-primary">Guardar cambios</button>
                                                        <button type="button" class="btn btn-outline" onclick="toggleEditForm('<?= e($rowId) ?>')">Cancelar</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination-bar" data-pagination="mailboxes-table">
                            <button type="button" class="btn btn-sm btn-outline" data-page-prev="mailboxes-table">Anterior</button>
                            <span class="pagination-status" data-page-status="mailboxes-table">Pagina 1</span>
                            <button type="button" class="btn btn-sm btn-outline" data-page-next="mailboxes-table">Siguiente</button>
                        </div>
                    <?php elseif ($manageDomain !== '' && $action !== 'load_mailboxes'): ?>
                        <p class="text-muted">Selecciona un dominio y carga las cuentas para empezar a gestionarlas.</p>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="section-heading">
                    <span class="section-kicker">Auditoria</span>
                    <h2>Historial reciente</h2>
                    <p class="text-muted">Resumen de altas, reseteos, cambios y borrados registrados desde la aplicación.</p>
                </div>

                <div class="card">
                    <div id="history-region">
                    <?php if (empty($recentManageActions)): ?>
                        <p class="text-muted">No hay operaciones registradas todavía.</p>
                    <?php else: ?>
                        <div class="list-toolbar">
                            <div class="list-search">
                                <label for="history-search">Buscar en historial</label>
                                <input type="search" id="history-search" placeholder="Filtrar por correo o acción" data-list-search="history-table">
                            </div>
                            <div class="list-page-size">
                                <label for="history-page-size">Mostrar</label>
                                <select id="history-page-size" data-page-size="history-table">
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                </select>
                            </div>
                        </div>

                        <div class="table-scroll">
                            <table class="table" id="history-table">
                                <thead>
                                    <tr>
                                        <th>Correo</th>
                                        <th>Acción</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentManageActions as $log): ?>
                                        <tr data-list-row="history-table" data-search="<?= e(strtolower(($log['email_address'] ?? '') . ' ' . ($log['action_type'] ?? 'create') . ' ' . ($log['status'] ?? ''))) ?>">
                                            <td><?= e($log['email_address']) ?></td>
                                            <td>
                                                <span class="badge <?= e(getActionBadgeClass($log['action_type'] ?? 'create')) ?>">
                                                    <?= e(getActionLabel($log['action_type'] ?? 'create')) ?>
                                                </span>
                                            </td>
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
                        </div>

                        <div class="pagination-bar" data-pagination="history-table">
                            <button type="button" class="btn btn-sm btn-outline" data-page-prev="history-table">Anterior</button>
                            <span class="pagination-status" data-page-status="history-table">Pagina 1</span>
                            <button type="button" class="btn btn-sm btn-outline" data-page-next="history-table">Siguiente</button>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
        const quotaOptions = <?= json_encode($quotaOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const outgoingLimitOptions = <?= json_encode($outgoingLimitOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const mailboxApiUrl = new URL('mailbox_api.php', window.location.href).toString();
        const tableStates = {};
        const mailboxDetailCache = new Map();
        const mailboxDetailPending = new Set();

        function activateTab(tabName) {
            document.querySelectorAll('[data-tab-trigger]').forEach(button => {
                button.classList.toggle('is-active', button.dataset.tabTrigger === tabName);
            });

            document.querySelectorAll('[data-tab-panel]').forEach(panel => {
                panel.classList.toggle('is-active', panel.dataset.tabPanel === tabName);
            });
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function debounce(fn, wait) {
            let timeoutId = null;
            return (...args) => {
                window.clearTimeout(timeoutId);
                timeoutId = window.setTimeout(() => fn(...args), wait);
            };
        }

        function showAjaxFeedback(type, message) {
            const container = document.getElementById('ajax-feedback');
            if (!container) {
                return;
            }

            if (!message) {
                container.innerHTML = '';
                return;
            }

            const alertClass = type === 'error' ? 'alert-error' : 'alert-success';
            container.innerHTML = '<div class="alert ' + alertClass + '">' + escapeHtml(message) + '</div>';
        }

        function copyText(text, successMessage) {
            navigator.clipboard.writeText(text).then(() => {
                alert(successMessage);
            }).catch(() => {
                alert('No se pudo copiar al portapapeles.');
            });
        }

        function copyRow(row, includePassword) {
            const email = row.dataset.email;
            const password = row.dataset.password || '';
            const link = row.dataset.link || '';
            const text = includePassword ? (email + '\t' + password + '\t' + link) : (email + '\t' + (link || 'Sin enlace'));
            copyText(text, 'Datos copiados: ' + email);
        }

        function copyAllRows(tableId, includePassword) {
            const table = document.getElementById(tableId);
            if (!table) {
                return;
            }

            const rows = table.querySelectorAll('tbody tr[data-email]');
            let text = '';

            rows.forEach(row => {
                const email = row.dataset.email;
                const password = row.dataset.password || '';
                const link = row.dataset.link || '';
                text += includePassword
                    ? (email + '\t' + password + '\t' + link + '\n')
                    : (email + '\t' + (link || 'Sin enlace') + '\n');
            });

            copyText(text.trim(), 'Se han copiado ' + rows.length + ' registros.');
        }

        function formatQuotaValue(value) {
            if (value === null || value === undefined || value === '') {
                return 'No disponible';
            }

            const normalized = String(value).trim();
            if (normalized === '-1') {
                return 'Ilimitado';
            }

            if (/^\d+$/.test(normalized)) {
                const bytes = Number(normalized);
                if (bytes <= 0) {
                    return normalized;
                }

                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                let unitIndex = 0;
                let display = bytes;

                while (display >= 1024 && unitIndex < units.length - 1) {
                    display /= 1024;
                    unitIndex += 1;
                }

                const decimals = display >= 10 || unitIndex === 0 ? 0 : 1;
                return display.toFixed(decimals).replace(/\.0$/, '') + ' ' + units[unitIndex];
            }

            return normalized;
        }

        function formatOutgoingLimitValue(value) {
            if (value === null || value === undefined || value === '') {
                return 'No disponible';
            }

            const normalized = String(value).trim();
            if (normalized === '-1') {
                return 'Ilimitado';
            }

            return normalized;
        }

        function updateEditFormSummary(row, details) {
            const editRow = row && row.nextElementSibling && row.nextElementSibling.classList.contains('edit-row')
                ? row.nextElementSibling
                : null;
            if (!editRow) {
                return;
            }

            const quotaSelect = editRow.querySelector('select[name="edit_quota"]');
            const outgoingSelect = editRow.querySelector('select[name="edit_outgoing_limit"]');

            if (quotaSelect && quotaSelect.options.length > 0) {
                quotaSelect.options[0].textContent = 'Mantener actual (' + formatQuotaValue(details && details.quota ? details.quota : null) + ')';
            }

            if (outgoingSelect && outgoingSelect.options.length > 0) {
                outgoingSelect.options[0].textContent = 'Mantener actual (' + formatOutgoingLimitValue(details && details.outgoing_limit ? details.outgoing_limit : null) + ')';
            }
        }

        function toggleEditForm(rowId) {
            const row = document.getElementById(rowId);
            if (!row) {
                return;
            }

            row.hidden = !row.hidden;

            if (!row.hidden && row.previousElementSibling && row.previousElementSibling.dataset.email) {
                loadMailboxDetailsForRow(row.previousElementSibling);
            }
        }

        function confirmDelete(email) {
            return confirm('Se va a borrar la cuenta ' + email + '. Esta acción no se puede deshacer. ¿Continuar?');
        }

        function confirmReset(email) {
            return confirm('Se generará una nueva contraseña para ' + email + '. ¿Quieres continuar?');
        }

        function getActionLabel(actionType) {
            const labels = {
                create: 'Creacion',
                password_reset: 'Reset',
                update: 'Edicion',
                delete: 'Borrado'
            };

            return labels[actionType] || actionType;
        }

        function getActionBadgeClass(actionType) {
            const classes = {
                create: 'badge-info',
                password_reset: 'badge-warning',
                update: 'badge-outline',
                delete: 'badge-error'
            };

            return classes[actionType] || 'badge-outline';
        }

        function renderOptionList(options, selectedValue, currentLabel) {
            let html = '<option value="__keep__">Mantener actual (' + escapeHtml(currentLabel) + ')</option>';
            Object.entries(options).forEach(([value, label]) => {
                const selected = String(selectedValue) === String(value) ? ' selected' : '';
                html += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(label) + '</option>';
            });
            return html;
        }

        function renderResetResult(result) {
            const region = document.getElementById('reset-result-region');
            if (!region) {
                return;
            }

            if (!result) {
                region.innerHTML = '';
                return;
            }

            const safeEmail = escapeHtml(result.email);
            const safePassword = escapeHtml(result.password);
            const safeLink = result.share_link ? escapeHtml(result.share_link) : '';

            region.innerHTML = `
                <div class="card card-highlight management-highlight" id="reset-result-card">
                    <h2>Nuevo acceso generado</h2>
                    <p class="text-muted">Usa este bloque para copiar rápidamente el enlace o la contraseña recién creada.</p>
                    <div class="result-grid">
                        <div>
                            <span class="result-label">Correo</span>
                            <code>${safeEmail}</code>
                        </div>
                        <div>
                            <span class="result-label">Contraseña</span>
                            <code>${safePassword}</code>
                        </div>
                        <div>
                            <span class="result-label">Enlace seguro</span>
                            ${result.share_link
                                ? `<a href="${safeLink}" target="_blank" class="share-link" rel="noopener noreferrer">${safeLink}</a>`
                                : '<span class="text-muted">No disponible</span>'}
                        </div>
                    </div>
                    <div class="copy-all-container">
                        ${result.share_link
                            ? `<button type="button" class="btn btn-sm btn-primary" onclick="copyText(${JSON.stringify(result.email + '\t' + result.share_link)}, 'Correo y enlace copiados')">Copiar email + enlace</button>
                               <button type="button" class="btn btn-sm btn-outline" onclick="copyText(${JSON.stringify(result.share_link)}, 'Enlace copiado')">Copiar solo enlace</button>`
                            : ''}
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyText(${JSON.stringify(result.email + '\t' + result.password)}, 'Correo y contraseña copiados')">Copiar email + contraseña</button>
                    </div>
                </div>
            `;
        }

        function renderMailboxes(mailboxes, domain) {
            const region = document.getElementById('manage-mailboxes-region');
            if (!region) {
                return;
            }

            if (!Array.isArray(mailboxes) || mailboxes.length === 0) {
                region.innerHTML = `
                    <div class="empty-state">
                        <strong>No hay cuentas cargadas para este dominio.</strong>
                        <p class="text-muted">Si el dominio existe en Plesk pero no hay buzones, esta tabla aparecerá vacía.</p>
                    </div>
                `;
                return;
            }

            const mailboxRows = mailboxes.map(mailbox => {
                const rowId = 'mailbox-' + btoa(unescape(encodeURIComponent(mailbox.email))).replace(/[^a-zA-Z0-9]/g, '').slice(0, 16);
                const quotaLabel = escapeHtml(formatQuotaValue(mailbox.quota));
                const outgoingLabel = escapeHtml(formatOutgoingLimitValue(mailbox.outgoing_limit));
                const searchText = escapeHtml((mailbox.email + ' ' + (mailbox.quota || '') + ' ' + (mailbox.outgoing_limit || '')).toLowerCase());

                return `
                    <tr data-list-row="mailboxes-table" data-email="${escapeHtml(mailbox.email)}" data-details-state="pending" data-search="${searchText}">
                        <td><code>${escapeHtml(mailbox.email)}</code></td>
                        <td data-field="quota">${quotaLabel}</td>
                        <td data-field="outgoing_limit">${outgoingLabel}</td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="btn btn-sm btn-primary" data-ajax-action="reset_password" data-email="${escapeHtml(mailbox.email)}">Restablecer</button>
                                <button type="button" class="btn btn-sm btn-outline" onclick="toggleEditForm('${escapeHtml(rowId)}')">Editar</button>
                                <button type="button" class="btn btn-sm btn-danger" data-ajax-action="delete_mailbox" data-email="${escapeHtml(mailbox.email)}">Borrar</button>
                            </div>
                        </td>
                    </tr>
                    <tr id="${escapeHtml(rowId)}" class="edit-row" hidden data-related-row="mailboxes-table">
                        <td colspan="4">
                            <form class="inline-edit-form" data-ajax-form="update_mailbox">
                                <input type="hidden" name="action" value="update_mailbox">
                                <input type="hidden" name="manage_domain" value="${escapeHtml(domain)}">
                                <input type="hidden" name="email" value="${escapeHtml(mailbox.email)}">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Nueva contraseña (opcional)</label>
                                        <input type="text" name="edit_password" placeholder="Dejalo vacio para no cambiar">
                                    </div>
                                    <div class="form-group">
                                        <label>Cuota del buzón</label>
                                        <select name="edit_quota">
                                            ${renderOptionList(quotaOptions, '', formatQuotaValue(mailbox.quota))}
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Límite de salida</label>
                                        <select name="edit_outgoing_limit">
                                            ${renderOptionList(outgoingLimitOptions, '', formatOutgoingLimitValue(mailbox.outgoing_limit))}
                                        </select>
                                    </div>
                                </div>
                                <div class="action-row">
                                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                                    <button type="button" class="btn btn-outline" onclick="toggleEditForm('${escapeHtml(rowId)}')">Cancelar</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                `;
            }).join('');

            region.innerHTML = `
                <div class="management-summary">
                    <strong>${mailboxes.length}</strong> cuenta(s) encontradas en <code>@${escapeHtml(domain)}</code>
                </div>
                <div class="list-toolbar">
                    <div class="list-search">
                        <label for="mailbox-search">Buscar correo</label>
                        <input type="search" id="mailbox-search" placeholder="Filtrar por nombre o dominio" data-list-search="mailboxes-table">
                    </div>
                    <div class="list-page-size">
                        <label for="mailbox-page-size">Mostrar</label>
                        <select id="mailbox-page-size" data-page-size="mailboxes-table">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="table" id="mailboxes-table">
                        <thead>
                            <tr>
                                <th>Correo</th>
                                <th>Cuota</th>
                                <th>Salida/hora</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>${mailboxRows}</tbody>
                    </table>
                </div>
                <div class="pagination-bar" data-pagination="mailboxes-table">
                    <button type="button" class="btn btn-sm btn-outline" data-page-prev="mailboxes-table">Anterior</button>
                    <span class="pagination-status" data-page-status="mailboxes-table">Pagina 1</span>
                    <button type="button" class="btn btn-sm btn-outline" data-page-next="mailboxes-table">Siguiente</button>
                </div>
            `;

            initPaginatedList('mailboxes-table', 250);
        }

        function applyMailboxDetailsToRow(row, details) {
            if (!row) {
                return;
            }

            const quotaCell = row.querySelector('[data-field="quota"]');
            const outgoingCell = row.querySelector('[data-field="outgoing_limit"]');
            const quotaValue = formatQuotaValue(details && details.quota ? details.quota : null);
            const outgoingValue = formatOutgoingLimitValue(details && details.outgoing_limit ? details.outgoing_limit : null);

            if (quotaCell) {
                quotaCell.textContent = quotaValue;
            }

            if (outgoingCell) {
                outgoingCell.textContent = outgoingValue;
            }

            const email = row.dataset.email || '';
            row.dataset.search = (email + ' ' + quotaValue + ' ' + outgoingValue).toLowerCase();
            row.dataset.detailsState = 'loaded';
            updateEditFormSummary(row, details);
        }

        async function loadMailboxDetailsForRow(row) {
            const email = row.dataset.email || '';
            if (!email) {
                return;
            }

            if (mailboxDetailCache.has(email)) {
                applyMailboxDetailsToRow(row, mailboxDetailCache.get(email));
                return;
            }

            if (mailboxDetailPending.has(email)) {
                return;
            }

            mailboxDetailPending.add(email);
            try {
                const formData = new FormData();
                formData.append('action', 'mailbox_info');
                formData.append('email', email);
                const payload = await postMailboxAction(formData);
                const details = payload.mailbox || null;
                mailboxDetailCache.set(email, details);
                applyMailboxDetailsToRow(row, details);
            } catch (error) {
                row.dataset.detailsState = 'error';
            } finally {
                mailboxDetailPending.delete(email);
            }
        }

        function loadVisibleMailboxDetails() {
            const table = document.getElementById('mailboxes-table');
            if (!table) {
                return;
            }

            const visibleRows = Array.from(table.querySelectorAll('tbody tr[data-list-row="mailboxes-table"][data-email]')).filter(row => !row.hidden);
            visibleRows.slice(0, 15).forEach(row => {
                if (row.dataset.detailsState !== 'loaded') {
                    loadMailboxDetailsForRow(row);
                }
            });
        }

        function renderHistory(logs) {
            const region = document.getElementById('history-region');
            if (!region) {
                return;
            }

            if (!Array.isArray(logs) || logs.length === 0) {
                region.innerHTML = '<p class="text-muted">No hay operaciones registradas todavía.</p>';
                return;
            }

            const rows = logs.map(log => `
                <tr data-list-row="history-table" data-search="${escapeHtml(((log.email_address || '') + ' ' + (log.action_type || 'create') + ' ' + (log.status || '')).toLowerCase())}">
                    <td>${escapeHtml(log.email_address)}</td>
                    <td><span class="badge ${escapeHtml(getActionBadgeClass(log.action_type || 'create'))}">${escapeHtml(getActionLabel(log.action_type || 'create'))}</span></td>
                    <td><span class="badge badge-${log.status === 'success' ? 'success' : 'error'}">${log.status === 'success' ? 'OK' : 'Error'}</span></td>
                    <td>${escapeHtml(log.created_at_label || '')}</td>
                </tr>
            `).join('');

            region.innerHTML = `
                <div class="list-toolbar">
                    <div class="list-search">
                        <label for="history-search">Buscar en historial</label>
                        <input type="search" id="history-search" placeholder="Filtrar por correo o acción" data-list-search="history-table">
                    </div>
                    <div class="list-page-size">
                        <label for="history-page-size">Mostrar</label>
                        <select id="history-page-size" data-page-size="history-table">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="table" id="history-table">
                        <thead>
                            <tr>
                                <th>Correo</th>
                                <th>Acción</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div class="pagination-bar" data-pagination="history-table">
                    <button type="button" class="btn btn-sm btn-outline" data-page-prev="history-table">Anterior</button>
                    <span class="pagination-status" data-page-status="history-table">Pagina 1</span>
                    <button type="button" class="btn btn-sm btn-outline" data-page-next="history-table">Siguiente</button>
                </div>
            `;

            initPaginatedList('history-table', 250);
        }

        function initPaginatedList(tableId, debounceMs = 0) {
            const table = document.getElementById(tableId);
            if (!table) {
                return;
            }

            const rows = Array.from(table.querySelectorAll('tbody tr[data-list-row="' + tableId + '"]'));
            const searchInput = document.querySelector('[data-list-search="' + tableId + '"]');
            const pageSizeSelect = document.querySelector('[data-page-size="' + tableId + '"]');
            const prevButton = document.querySelector('[data-page-prev="' + tableId + '"]');
            const nextButton = document.querySelector('[data-page-next="' + tableId + '"]');
            const status = document.querySelector('[data-page-status="' + tableId + '"]');
            let currentPage = 1;

            const updateList = () => {
                const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
                const pageSize = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) : 25;
                const filteredRows = rows.filter(row => {
                    const haystack = (row.dataset.search || '').toLowerCase();
                    return query === '' || haystack.indexOf(query) !== -1;
                });
                const filteredSet = new Set(filteredRows);
                const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }

                const start = (currentPage - 1) * pageSize;
                const visibleSet = new Set(filteredRows.slice(start, start + pageSize));

                rows.forEach(row => {
                    const isVisible = visibleSet.has(row);
                    row.hidden = !isVisible;

                    const relatedRow = row.nextElementSibling;
                    if ((!isVisible || !filteredSet.has(row)) && relatedRow && relatedRow.classList.contains('edit-row')) {
                        relatedRow.hidden = true;
                    }
                });

                if (status) {
                    status.textContent = filteredRows.length === 0
                        ? 'Sin resultados'
                        : 'Pagina ' + currentPage + ' de ' + totalPages + ' · ' + filteredRows.length + ' resultado(s)';
                }

                if (prevButton) {
                    prevButton.disabled = currentPage <= 1 || filteredRows.length === 0;
                }

                if (nextButton) {
                    nextButton.disabled = currentPage >= totalPages || filteredRows.length === 0;
                }

                if (tableId === 'mailboxes-table') {
                    loadVisibleMailboxDetails();
                }
            };

            const debouncedUpdate = debounceMs > 0 ? debounce(updateList, debounceMs) : updateList;

            if (searchInput && !searchInput.dataset.bound) {
                searchInput.dataset.bound = 'true';
                searchInput.addEventListener('input', () => {
                    currentPage = 1;
                    debouncedUpdate();
                });
            }

            if (pageSizeSelect && !pageSizeSelect.dataset.bound) {
                pageSizeSelect.dataset.bound = 'true';
                pageSizeSelect.addEventListener('change', () => {
                    currentPage = 1;
                    updateList();
                });
            }

            if (prevButton && !prevButton.dataset.bound) {
                prevButton.dataset.bound = 'true';
                prevButton.addEventListener('click', () => {
                    if (currentPage > 1) {
                        currentPage -= 1;
                        updateList();
                    }
                });
            }

            if (nextButton && !nextButton.dataset.bound) {
                nextButton.dataset.bound = 'true';
                nextButton.addEventListener('click', () => {
                    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
                    const filteredCount = rows.filter(row => query === '' || (row.dataset.search || '').toLowerCase().indexOf(query) !== -1).length;
                    const pageSize = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) : 25;
                    const totalPages = Math.max(1, Math.ceil(filteredCount / pageSize));
                    if (currentPage < totalPages) {
                        currentPage += 1;
                        updateList();
                    }
                });
            }

            tableStates[tableId] = { updateList };
            updateList();
        }

        function tryAutoSelectDomain() {
            const usernamesField = document.getElementById('usernames');
            const domainSelect = document.getElementById('domain');

            if (!usernamesField || !domainSelect) {
                return;
            }

            const matches = usernamesField.value.match(/[A-Z0-9._%+-]+@([A-Z0-9.-]+\.[A-Z]{2,})/ig);
            if (!matches || matches.length === 0) {
                return;
            }

            const domains = {};
            matches.forEach(email => {
                const parts = email.toLowerCase().split('@');
                if (parts[1]) {
                    domains[parts[1]] = (domains[parts[1]] || 0) + 1;
                }
            });

            const sortedDomains = Object.keys(domains).sort((a, b) => domains[b] - domains[a]);
            if (sortedDomains.length === 0) {
                return;
            }

            const matchingOption = Array.from(domainSelect.options).find(option => option.value.toLowerCase() === sortedDomains[0]);
            if (matchingOption) {
                domainSelect.value = matchingOption.value;
            }
        }

        async function postMailboxAction(formData) {
            const response = await fetch(mailboxApiUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'No se pudo completar la operación');
            }

            return payload;
        }

        async function handleManageAction(formData) {
            const payload = await postMailboxAction(formData);
            showAjaxFeedback('success', payload.message || '');
            renderResetResult(payload.reset_result || null);
            renderMailboxes(payload.mailboxes || [], payload.manage_domain || formData.get('manage_domain') || '');
            renderHistory(payload.recent_logs || []);
            activateTab('manage');
        }

        const manageToolbarForm = document.getElementById('manage-toolbar-form');
        if (manageToolbarForm) {
            const loadSelectedDomain = async () => {
                const formData = new FormData();
                const manageDomainField = document.getElementById('manage_domain');
                const manageDomain = manageDomainField ? manageDomainField.value : '';
                if (!manageDomain) {
                    return;
                }

                showAjaxFeedback('', '');
                formData.append('action', 'load_mailboxes');
                formData.append('manage_domain', manageDomain);

                try {
                    await handleManageAction(formData);
                } catch (error) {
                    showAjaxFeedback('error', error.message);
                }
            };

            manageToolbarForm.addEventListener('submit', async event => {
                event.preventDefault();
                await loadSelectedDomain();
            });

            const manageDomainField = document.getElementById('manage_domain');
            if (manageDomainField) {
                manageDomainField.addEventListener('change', loadSelectedDomain);

                if (manageDomainField.value && !document.querySelector('#manage-mailboxes-region [data-list-row="mailboxes-table"]')) {
                    loadSelectedDomain();
                }
            }
        }

        const manageRegion = document.getElementById('manage-mailboxes-region');
        if (manageRegion) {
            manageRegion.addEventListener('click', async event => {
                const actionButton = event.target.closest('[data-ajax-action]');
                if (!actionButton) {
                    return;
                }

                const action = actionButton.dataset.ajaxAction;
                const email = actionButton.dataset.email || '';
                const manageDomainField = document.getElementById('manage_domain');
                const manageDomain = manageDomainField ? manageDomainField.value : '';

                if (action === 'reset_password' && !confirmReset(email)) {
                    return;
                }

                if (action === 'delete_mailbox' && !confirmDelete(email)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', action);
                formData.append('manage_domain', manageDomain);
                formData.append('email', email);

                try {
                    await handleManageAction(formData);
                } catch (error) {
                    showAjaxFeedback('error', error.message);
                }
            });

            manageRegion.addEventListener('submit', async event => {
                const form = event.target.closest('form[data-ajax-form="update_mailbox"]');
                if (!form) {
                    return;
                }

                event.preventDefault();

                try {
                    await handleManageAction(new FormData(form));
                } catch (error) {
                    showAjaxFeedback('error', error.message);
                }
            });
        }

        document.querySelectorAll('[data-tab-trigger]').forEach(button => {
            button.addEventListener('click', () => activateTab(button.dataset.tabTrigger));
        });

        const usernamesField = document.getElementById('usernames');
        if (usernamesField) {
            usernamesField.addEventListener('input', tryAutoSelectDomain);
            usernamesField.addEventListener('blur', tryAutoSelectDomain);
            tryAutoSelectDomain();
        }

        initPaginatedList('mailboxes-table', 250);
        initPaginatedList('history-table', 250);
    </script>
</body>
</html>

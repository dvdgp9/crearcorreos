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

$action = $_POST['action'] ?? '';

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

                foreach ($userList as $username) {
                    $emailAddress = strtolower($username . '@' . $domain);

                    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                        $validationResults[] = [
                            'username' => $username,
                            'email' => $emailAddress,
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

            foreach ($userList as $username) {
                if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                    $errors[] = "Usuario inválido: {$username}";
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

$recentEmails = [];
try {
    $recentEmails = EmailLog::getRecent(10);
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

            <?php if ($lastResetResult): ?>
                <div class="card card-highlight management-highlight">
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

            <section class="dashboard-section">
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
            </section>

            <section class="dashboard-section section-management">
                <div class="section-heading">
                    <span class="section-kicker section-kicker-management">Gestion</span>
                    <h2>Gestionar cuentas existentes</h2>
                    <p class="text-muted">Carga las cuentas reales de Plesk por dominio para restablecer contraseña, editar límites o borrar cuentas.</p>
                </div>

                <div class="card card-management">
                    <form method="POST" action="" class="manage-toolbar">
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

                    <?php if ($manageDomain !== '' && empty($managedMailboxes) && $action === 'load_mailboxes'): ?>
                        <div class="empty-state">
                            <strong>No hay cuentas cargadas para este dominio.</strong>
                            <p class="text-muted">Si el dominio existe en Plesk pero no hay buzones, esta tabla aparecerá vacía.</p>
                        </div>
                    <?php elseif (!empty($managedMailboxes)): ?>
                        <div class="management-summary">
                            <strong><?= count($managedMailboxes) ?></strong> cuenta(s) encontradas en <code>@<?= e($manageDomain) ?></code>
                        </div>

                        <div class="table-scroll">
                            <table class="table">
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
                                        <tr>
                                            <td><code><?= e($mailbox['email']) ?></code></td>
                                            <td><?= e(formatMailboxValue($mailbox['quota'])) ?></td>
                                            <td><?= e(formatMailboxValue($mailbox['outgoing_limit'])) ?></td>
                                            <td>
                                                <div class="table-actions">
                                                    <form method="POST" action="" onsubmit="return confirmReset('<?= e($mailbox['email']) ?>');">
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="manage_domain" value="<?= e($manageDomain) ?>">
                                                        <input type="hidden" name="email" value="<?= e($mailbox['email']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-primary">Restablecer</button>
                                                    </form>

                                                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleEditForm('<?= e($rowId) ?>')">
                                                        Editar
                                                    </button>

                                                    <form method="POST" action="" onsubmit="return confirmDelete('<?= e($mailbox['email']) ?>');">
                                                        <input type="hidden" name="action" value="delete_mailbox">
                                                        <input type="hidden" name="manage_domain" value="<?= e($manageDomain) ?>">
                                                        <input type="hidden" name="email" value="<?= e($mailbox['email']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">Borrar</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr id="<?= e($rowId) ?>" class="edit-row" hidden>
                                            <td colspan="4">
                                                <form method="POST" action="" class="inline-edit-form">
                                                    <input type="hidden" name="action" value="update_mailbox">
                                                    <input type="hidden" name="manage_domain" value="<?= e($manageDomain) ?>">
                                                    <input type="hidden" name="email" value="<?= e($mailbox['email']) ?>">

                                                    <div class="form-row">
                                                        <div class="form-group">
                                                            <label>Cuota del buzón</label>
                                                            <select name="edit_quota">
                                                                <option value="__keep__">Mantener actual (<?= e(formatMailboxValue($mailbox['quota'], 'sin dato')) ?>)</option>
                                                                <?php foreach ($quotaOptions as $value => $label): ?>
                                                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="form-group">
                                                            <label>Límite de salida</label>
                                                            <select name="edit_outgoing_limit">
                                                                <option value="__keep__">Mantener actual (<?= e(formatMailboxValue($mailbox['outgoing_limit'], 'sin dato')) ?>)</option>
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
                    <?php elseif ($manageDomain !== '' && $action !== 'load_mailboxes'): ?>
                        <p class="text-muted">Selecciona un dominio y carga las cuentas para empezar a gestionarlas.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-section">
                <div class="section-heading">
                    <span class="section-kicker">Auditoria</span>
                    <h2>Historial reciente</h2>
                    <p class="text-muted">Resumen de altas, reseteos, cambios y borrados registrados desde la aplicación.</p>
                </div>

                <div class="card">
                    <?php if (empty($recentEmails)): ?>
                        <p class="text-muted">No hay operaciones registradas todavía.</p>
                    <?php else: ?>
                        <div class="table-scroll">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Correo</th>
                                        <th>Acción</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentEmails as $log): ?>
                                        <tr>
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
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <script>
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

        function toggleEditForm(rowId) {
            const row = document.getElementById(rowId);
            if (!row) {
                return;
            }

            row.hidden = !row.hidden;
        }

        function confirmDelete(email) {
            return confirm('Se va a borrar la cuenta ' + email + '. Esta acción no se puede deshacer. ¿Continuar?');
        }

        function confirmReset(email) {
            return confirm('Se generará una nueva contraseña para ' + email + '. ¿Quieres continuar?');
        }
    </script>
</body>
</html>

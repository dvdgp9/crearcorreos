<?php
/**
 * Endpoint AJAX para gestionar cuentas de correo
 */

require_once __DIR__ . '/../includes/init.php';

Auth::requireLogin();

$currentUserId = Auth::getUserId();
if ($currentUserId === null) {
    respondJson([
        'success' => false,
        'message' => 'Sesión no válida'
    ], 401);
}

// Evita bloquear la sesión mientras se ejecutan llamadas largas a Plesk.
session_write_close();

header('Content-Type: application/json; charset=UTF-8');

function respondJson(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function formatLogRows(array $logs): array {
    return array_map(static function (array $log): array {
        return [
            'email_address' => $log['email_address'] ?? '',
            'action_type' => $log['action_type'] ?? 'create',
            'status' => $log['status'] ?? 'success',
            'created_at' => $log['created_at'] ?? '',
            'created_at_label' => !empty($log['created_at']) ? date('d/m H:i', strtotime($log['created_at'])) : ''
        ];
    }, $logs);
}

$action = $_POST['action'] ?? '';
$manageDomain = trim($_POST['manage_domain'] ?? '');

if (!in_array($action, ['load_mailboxes', 'reset_password', 'update_mailbox', 'delete_mailbox', 'bulk_delete_mailboxes', 'mailbox_info'], true)) {
    respondJson([
        'success' => false,
        'message' => 'Acción no permitida'
    ], 400);
}

if ($action !== 'mailbox_info' && $manageDomain === '') {
    respondJson([
        'success' => false,
        'message' => 'Debes seleccionar un dominio para gestionar cuentas'
    ], 422);
}

$plesk = new PleskApi();

try {
    $message = '';
    $resetResult = null;

    if ($action === 'mailbox_info') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if ($email === '') {
            throw new Exception('Debes indicar un correo');
        }

        $info = $plesk->getMailboxInfo($email);

        respondJson([
            'success' => true,
            'message' => 'Detalle de cuenta cargado.',
            'mailbox' => [
                'email' => $info['email'],
                'quota' => $info['quota'],
                'outgoing_limit' => $info['outgoing_limit']
            ]
        ]);
    }

    if ($action === 'reset_password') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $passwordShare = new PasswordShare();
        $resetResult = $plesk->resetMailboxPassword($email, $passwordShare);
        [, $domain] = explode('@', $email, 2);

        EmailLog::log(
            $currentUserId,
            $email,
            $domain,
            'success',
            $resetResult['share_link'] ? null : 'Contraseña actualizada sin enlace seguro',
            'password_reset'
        );

        $message = $resetResult['share_link']
            ? 'Contraseña restablecida y enlace seguro generado.'
            : 'Contraseña restablecida, pero no se pudo generar el enlace seguro.';
    }

    if ($action === 'update_mailbox') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $quotaSelection = $_POST['edit_quota'] ?? '__keep__';
        $outgoingSelection = $_POST['edit_outgoing_limit'] ?? '__keep__';
        $passwordSelection = trim($_POST['edit_password'] ?? '');
        $changes = [];

        if ($passwordSelection !== '') {
            $changes['password'] = $passwordSelection;
        }

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
            $currentUserId,
            $email,
            $domain,
            'success',
            null,
            'update'
        );

        $message = 'La cuenta se ha actualizado correctamente.';
    }

    if ($action === 'delete_mailbox') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $plesk->deleteMailbox($email);
        [, $domain] = explode('@', $email, 2);

        EmailLog::log(
            $currentUserId,
            $email,
            $domain,
            'success',
            null,
            'delete'
        );

        $message = 'La cuenta se ha eliminado correctamente.';
    }

    if ($action === 'bulk_delete_mailboxes') {
        $emails = $_POST['emails'] ?? [];
        if (!is_array($emails) || empty($emails)) {
            throw new Exception('Debes seleccionar al menos un correo');
        }

        $deletedCount = 0;
        $errors = [];

        foreach ($emails as $rawEmail) {
            $email = strtolower(trim((string) $rawEmail));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $rawEmail . ': correo no válido';
                continue;
            }

            try {
                $plesk->deleteMailbox($email);
                [, $domain] = explode('@', $email, 2);

                EmailLog::log(
                    $currentUserId,
                    $email,
                    $domain,
                    'success',
                    null,
                    'delete'
                );

                $deletedCount++;
            } catch (Exception $e) {
                [, $domain] = explode('@', $email, 2);

                try {
                    EmailLog::log(
                        $currentUserId,
                        $email,
                        $domain,
                        'error',
                        $e->getMessage(),
                        'delete'
                    );
                } catch (Exception $logError) {
                    // Silenciar fallo de auditoría
                }

                $errors[] = $email . ': ' . $e->getMessage();
            }
        }

        if ($deletedCount === 0) {
            throw new Exception('No se pudo borrar ningún correo. ' . implode(' | ', $errors));
        }

        $message = 'Se borraron ' . $deletedCount . ' correo(s) correctamente.';
        if (!empty($errors)) {
            $message .= ' Errores: ' . implode(' | ', $errors);
        }
    }

    if ($action === 'load_mailboxes') {
        $message = 'Listado cargado correctamente.';
    }

    $mailboxes = $plesk->getMailboxesByDomain($manageDomain);
    $recentLogs = EmailLog::getRecentByActionTypes(['password_reset', 'update', 'delete'], 50);

    respondJson([
        'success' => true,
        'message' => $message,
        'manage_domain' => $manageDomain,
        'mailboxes' => $mailboxes,
        'reset_result' => $resetResult,
        'recent_logs' => formatLogRows($recentLogs)
    ]);
} catch (Exception $e) {
    $emailForLog = strtolower(trim($_POST['email'] ?? ''));
    if ($action !== 'mailbox_info' && $emailForLog !== '' && strpos($emailForLog, '@') !== false) {
        [, $logDomain] = explode('@', $emailForLog, 2);
        $actionType = $action === 'reset_password' ? 'password_reset' : ($action === 'update_mailbox' ? 'update' : 'delete');

        try {
            EmailLog::log(
                $currentUserId,
                $emailForLog,
                $logDomain,
                'error',
                $e->getMessage(),
                $actionType
            );
        } catch (Exception $logError) {
            // Silenciar fallo de auditoría
        }
    }

    respondJson([
        'success' => false,
        'message' => $e->getMessage()
    ], 422);
}

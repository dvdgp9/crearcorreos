<?php
/**
 * Descarga todas las cuentas de correo del dominio seleccionado en formato CSV.
 */

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/MailboxCsvExporter.php';

Auth::requireLogin();

$requestedDomain = strtolower(trim($_GET['domain'] ?? ''));
if ($requestedDomain === '') {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Debes seleccionar un dominio para descargar el listado.');
}

// Evita bloquear otras peticiones del usuario durante la consulta a Plesk.
session_write_close();

try {
    $plesk = new PleskApi();
    $domains = $plesk->getDomains();
    $availableDomain = null;

    foreach ($domains as $domain) {
        $domainName = strtolower(trim((string) ($domain['name'] ?? '')));
        if ($domainName === $requestedDomain) {
            $availableDomain = $domainName;
            break;
        }
    }

    if ($availableDomain === null) {
        throw new RuntimeException('El dominio seleccionado no está disponible en Plesk.');
    }

    $mailboxes = $plesk->getMailboxesByDomain($availableDomain);
    $filename = MailboxCsvExporter::buildFilename($availableDomain);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('No se pudo iniciar la descarga del listado.');
    }

    MailboxCsvExporter::write($output, $mailboxes);
    fclose($output);
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'No se pudo descargar el listado: ' . $e->getMessage();
}

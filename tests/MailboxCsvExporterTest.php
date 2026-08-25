<?php

require_once __DIR__ . '/../includes/MailboxCsvExporter.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALLO: {$message}\nEsperado: " . var_export($expected, true) . "\nObtenido: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$stream = fopen('php://temp', 'w+');
$count = MailboxCsvExporter::write($stream, [
    ['email' => 'ana@example.com'],
    ['email' => 'ventas@example.com'],
    ['email' => ''],
    [],
]);

rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);

assertSameValue(2, $count, 'Debe exportar únicamente direcciones no vacías.');
assertSameValue(
    "\xEF\xBB\xBFCorreo\nana@example.com\nventas@example.com\n",
    $csv,
    'Debe generar un CSV UTF-8 compatible con Excel con una fila por correo.'
);

$formulaStream = fopen('php://temp', 'w+');
MailboxCsvExporter::write($formulaStream, [['email' => '=cmd@example.com']]);
rewind($formulaStream);
$formulaCsv = stream_get_contents($formulaStream);
fclose($formulaStream);

assertSameValue(
    "\xEF\xBB\xBFCorreo\n'=cmd@example.com\n",
    $formulaCsv,
    'Debe neutralizar valores que una hoja de cálculo pueda interpretar como fórmulas.'
);

assertSameValue(
    'correos-example.com-2026-08-25.csv',
    MailboxCsvExporter::buildFilename('Example.COM', new DateTimeImmutable('2026-08-25')),
    'Debe generar un nombre de archivo seguro y reconocible.'
);

fwrite(STDOUT, "OK: MailboxCsvExporterTest\n");

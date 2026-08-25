<?php

/**
 * Genera listados CSV de buzones compatibles con Excel.
 */
class MailboxCsvExporter
{
    /**
     * @param resource $stream
     * @param array<int, array{email?: mixed}> $mailboxes
     */
    public static function write($stream, array $mailboxes): int
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('El destino del CSV no es válido');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        self::writeRow($stream, ['Correo']);

        $count = 0;
        foreach ($mailboxes as $mailbox) {
            $email = trim((string) ($mailbox['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            self::writeRow($stream, [self::sanitizeCell($email)]);
            $count++;
        }

        return $count;
    }

    public static function buildFilename(string $domain, ?DateTimeInterface $date = null): string
    {
        $safeDomain = strtolower(trim($domain));
        $safeDomain = preg_replace('/[^a-z0-9.-]+/', '-', $safeDomain) ?? '';
        $safeDomain = trim($safeDomain, '.-');

        if ($safeDomain === '') {
            $safeDomain = 'dominio';
        }

        $date = $date ?? new DateTimeImmutable();
        return 'correos-' . $safeDomain . '-' . $date->format('Y-m-d') . '.csv';
    }

    /**
     * @param resource $stream
     * @param string[] $values
     */
    private static function writeRow($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '', "\n") === false) {
            throw new RuntimeException('No se pudo escribir el listado CSV');
        }
    }

    private static function sanitizeCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}

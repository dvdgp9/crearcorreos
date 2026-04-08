<?php
/**
 * Clase para comunicación con API REST de Plesk
 */

class PleskApi {
    private string $host;
    private string $apiKey;

    public function __construct() {
        $this->host = PLESK_HOST;
        $this->apiKey = PLESK_API_KEY;
    }

    /**
     * Ejecutar comando CLI de Plesk
     */
    private function executeCliCommand(string $command, array $params = [], array $env = []): array {
        $url = $this->host . '/api/v2/cli/' . $command . '/call';

        $data = [
            'params' => $params
        ];

        if (!empty($env)) {
            $data['env'] = $env;
        }

        return $this->makeRequest('POST', $url, $data);
    }

    /**
     * Realizar petición HTTP a la API
     */
    private function makeRequest(string $method, string $url, array $data = []): array {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false, // En producción, considerar verificar SSL
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey
            ]
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception("Error de conexión: " . $error);
        }

        $decoded = json_decode($response, true);

        return [
            'http_code' => $httpCode,
            'response' => $decoded ?? $response
        ];
    }

    /**
     * Validar respuesta de CLI de Plesk.
     */
    private function assertCliSuccess(array $result, string $genericMessage): array {
        if ($result['http_code'] !== 200) {
            throw new Exception($genericMessage . ': ' . json_encode($result['response']));
        }

        $response = $result['response'];

        if (is_array($response) && isset($response['code']) && (int) $response['code'] !== 0) {
            $stderr = trim((string) ($response['stderr'] ?? ''));
            throw new Exception($stderr !== '' ? $stderr : $genericMessage);
        }

        return is_array($response) ? $response : ['stdout' => (string) $response];
    }

    /**
     * Obtener lista de dominios
     */
    public function getDomains(): array {
        $url = $this->host . '/api/v2/domains';
        $result = $this->makeRequest('GET', $url);

        if ($result['http_code'] !== 200) {
            throw new Exception("Error al obtener dominios: " . json_encode($result['response']));
        }

        return $result['response'];
    }

    /**
     * Crear cuenta de correo con opciones avanzadas
     *
     * @param string $email Dirección de correo
     * @param string $password Contraseña
     * @param string|null $quota Tamaño del buzón (ej: '50M', '1G', '-1' para ilimitado)
     * @param int|null $outgoingLimit Límite de mensajes salientes por hora (-1 para ilimitado)
     */
    public function createMailbox(string $email, string $password, ?string $quota = null, ?int $outgoingLimit = null): array {
        $params = [
            '--create', $email,
            '-passwd', $password,
            '-mailbox', 'true'
        ];

        if ($quota !== null && $quota !== '') {
            $params[] = '-mbox_quota';
            $params[] = $quota;
        }

        if ($outgoingLimit !== null) {
            $params[] = '-outgoing-messages-mbox-limit';
            $params[] = (string) $outgoingLimit;
        }

        $result = $this->executeCliCommand('mail', $params);
        return $this->assertCliSuccess($result, 'Error al crear el correo');
    }

    /**
     * Listar cuentas de correo (respuesta raw)
     */
    public function listMailboxes(string $domain): array {
        $result = $this->executeCliCommand('mail', ['--list', '-json']);
        return $this->assertCliSuccess($result, 'Error al listar correos');
    }

    /**
     * Obtener array de correos existentes en un dominio
     * @return string[] Lista de direcciones de email
     */
    public function getExistingMailboxes(string $domain): array {
        $mailboxes = $this->getMailboxesByDomain($domain);
        return array_values(array_map(fn($mailbox) => $mailbox['email'], $mailboxes));
    }

    /**
     * Obtener cuentas reales de un dominio.
     *
     * @return array<int, array{email:string, mailbox:string, domain:string, quota:?string, outgoing_limit:?string}>
     */
    public function getMailboxesByDomain(string $domain): array {
        $response = $this->listMailboxes($domain);
        $stdout = trim((string) ($response['stdout'] ?? ''));
        if ($stdout === '') {
            return [];
        }

        $normalizedDomain = strtolower(trim($domain));
        $mailboxes = [];
        $decoded = json_decode($stdout, true);

        if (is_array($decoded)) {
            $this->extractMailboxesFromJson($decoded, $normalizedDomain, $mailboxes);
        }

        if (empty($mailboxes)) {
            $lines = preg_split('/[\r\n]+/', $stdout);
            foreach ($lines as $line) {
                $candidate = $this->normalizeMailboxCandidate($line, $normalizedDomain);
                if ($candidate === null) {
                    continue;
                }

                $mailboxes[$candidate] = [
                    'email' => $candidate,
                    'mailbox' => strstr($candidate, '@', true),
                    'domain' => $normalizedDomain,
                    'quota' => null,
                    'outgoing_limit' => null
                ];
            }
        }

        foreach ($mailboxes as $email => $mailbox) {
            if ($mailbox['quota'] !== null && $mailbox['outgoing_limit'] !== null) {
                continue;
            }

            try {
                $details = $this->getMailboxInfo($email);
                $mailboxes[$email]['quota'] = $details['quota'] ?? $mailboxes[$email]['quota'];
                $mailboxes[$email]['outgoing_limit'] = $details['outgoing_limit'] ?? $mailboxes[$email]['outgoing_limit'];
            } catch (Exception $e) {
                // Si falla el detalle individual, mantenemos la cuenta en el listado.
            }
        }

        ksort($mailboxes);
        return array_values($mailboxes);
    }

    /**
     * Obtener información detallada de un buzón.
     *
     * @return array{email:string, mailbox:string, domain:string, quota:?string, outgoing_limit:?string, raw_stdout:string}
     */
    public function getMailboxInfo(string $email): array {
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('La dirección de correo no es válida');
        }

        $result = $this->executeCliCommand('mail', ['--info', $normalizedEmail]);
        $response = $this->assertCliSuccess($result, 'Error al obtener información del correo');
        $stdout = (string) ($response['stdout'] ?? '');

        [$mailbox, $domain] = explode('@', $normalizedEmail, 2);

        return [
            'email' => $normalizedEmail,
            'mailbox' => $mailbox,
            'domain' => $domain,
            'quota' => $this->extractValueByPatterns($stdout, [
                '/mailbox quota\s*:\s*(.+)/i',
                '/quota\s*:\s*(.+)/i',
                '/mailbox_quota\s*[:=]\s*(.+)/i',
                '/mbox_quota\s*[:=]\s*(.+)/i'
            ]),
            'outgoing_limit' => $this->extractValueByPatterns($stdout, [
                '/outgoing(?:\s+messages)?(?:\s+mbox)?\s+limit\s*:\s*(.+)/i',
                '/outgoing messages(?: per hour)?\s*:\s*(.+)/i',
                '/outgoing-messages-mbox-limit\s*[:=]\s*(.+)/i',
                '/outgoing_messages_mbox_limit\s*[:=]\s*(.+)/i'
            ]),
            'raw_stdout' => $stdout
        ];
    }

    /**
     * Actualizar una cuenta de correo.
     *
     * @param array{password?:string, quota?:?string, outgoing_limit?:?int} $changes
     */
    public function updateMailbox(string $email, array $changes): array {
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('La dirección de correo no es válida');
        }

        $params = ['--update', $normalizedEmail];

        if (array_key_exists('password', $changes)) {
            $password = (string) $changes['password'];
            if ($password === '') {
                throw new Exception('La contraseña no puede estar vacía');
            }
            $params[] = '-passwd';
            $params[] = $password;
        }

        if (array_key_exists('quota', $changes)) {
            $quota = $changes['quota'];
            if ($quota === null || $quota === '') {
                $quota = '-1';
            }
            $params[] = '-mbox_quota';
            $params[] = (string) $quota;
        }

        if (array_key_exists('outgoing_limit', $changes)) {
            $outgoingLimit = $changes['outgoing_limit'];
            if ($outgoingLimit === null || $outgoingLimit === '') {
                $outgoingLimit = -1;
            }
            $params[] = '-outgoing-messages-mbox-limit';
            $params[] = (string) $outgoingLimit;
        }

        if (count($params) === 2) {
            throw new Exception('No se han indicado cambios para actualizar');
        }

        $result = $this->executeCliCommand('mail', $params);
        return $this->assertCliSuccess($result, 'Error al actualizar el correo');
    }

    /**
     * Restablecer contraseña y generar enlace seguro.
     *
     * @return array{email:string, password:string, share_link:?string}
     */
    public function resetMailboxPassword(string $email, ?PasswordShare $passwordShare = null): array {
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('La dirección de correo no es válida');
        }

        $password = $this->generateSecurePassword();
        $this->updateMailbox($normalizedEmail, ['password' => $password]);

        $shareLink = null;
        try {
            $passwordShare = $passwordShare ?? new PasswordShare();
            $shareLink = $passwordShare->createShareLink($password);
        } catch (Exception $e) {
            $shareLink = null;
        }

        return [
            'email' => $normalizedEmail,
            'password' => $password,
            'share_link' => $shareLink
        ];
    }

    /**
     * Eliminar cuenta de correo
     */
    public function deleteMailbox(string $email): array {
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('La dirección de correo no es válida');
        }

        $result = $this->executeCliCommand('mail', ['--remove', $normalizedEmail]);
        return $this->assertCliSuccess($result, 'Error al eliminar correo');
    }

    /**
     * Probar conexión con Plesk
     */
    public function testConnection(): bool {
        try {
            $url = $this->host . '/api/v2/server';
            $result = $this->makeRequest('GET', $url);
            return $result['http_code'] === 200;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Extraer buzones desde la salida JSON de Plesk.
     *
     * @param mixed $node
     * @param array<string, array{email:string, mailbox:string, domain:string, quota:?string, outgoing_limit:?string}> $mailboxes
     */
    private function extractMailboxesFromJson($node, string $domain, array &$mailboxes): void {
        if (!is_array($node)) {
            return;
        }

        if ($this->looksLikeMailboxRecord($node, $domain)) {
            $email = $this->resolveMailboxEmail($node, $domain);
            if ($email !== null) {
                $mailboxes[$email] = [
                    'email' => $email,
                    'mailbox' => strstr($email, '@', true),
                    'domain' => $domain,
                    'quota' => $this->pickFirstStringValue($node, ['mbox_quota', 'mailbox_quota', 'quota']),
                    'outgoing_limit' => $this->pickFirstScalarValue($node, [
                        'outgoing_messages_mbox_limit',
                        'outgoing-messages-mbox-limit',
                        'outgoing_limit'
                    ])
                ];
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->extractMailboxesFromJson($value, $domain, $mailboxes);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function looksLikeMailboxRecord(array $node, string $domain): bool {
        return $this->resolveMailboxEmail($node, $domain) !== null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveMailboxEmail(array $node, string $domain): ?string {
        $candidates = [
            $node['email'] ?? null,
            $node['address'] ?? null,
            $node['mail'] ?? null
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeMailboxCandidate($candidate, $domain);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $nameCandidates = [
            $node['name'] ?? null,
            $node['mail_name'] ?? null,
            $node['mailname'] ?? null,
            $node['username'] ?? null
        ];

        foreach ($nameCandidates as $candidate) {
            $normalized = $this->normalizeMailboxCandidate($candidate, $domain);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeMailboxCandidate($candidate, string $domain): ?string {
        if (!is_scalar($candidate)) {
            return null;
        }

        $candidate = strtolower(trim((string) $candidate));
        if ($candidate === '') {
            return null;
        }

        if (strpos($candidate, '@') === false) {
            if (!preg_match('/^[a-z0-9._-]+$/', $candidate)) {
                return null;
            }
            $candidate .= '@' . $domain;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $this->endsWith($candidate, '@' . $domain) ? $candidate : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param string[] $keys
     */
    private function pickFirstStringValue(array $node, array $keys): ?string {
        foreach ($keys as $key) {
            if (array_key_exists($key, $node) && is_scalar($node[$key])) {
                $value = trim((string) $node[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param string[] $keys
     */
    private function pickFirstScalarValue(array $node, array $keys): ?string {
        foreach ($keys as $key) {
            if (array_key_exists($key, $node) && is_scalar($node[$key])) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    /**
     * @param string[] $patterns
     */
    private function extractValueByPatterns(string $input, array $patterns): ?string {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function generateSecurePassword(int $length = 12): string {
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

    private function endsWith(string $value, string $suffix): bool {
        if ($suffix === '') {
            return true;
        }

        return substr($value, -strlen($suffix)) === $suffix;
    }
}

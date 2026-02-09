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
        
        // Añadir cuota si se especifica
        if ($quota !== null && $quota !== '') {
            $params[] = '-mbox_quota';
            $params[] = $quota;
        }
        
        // Añadir límite de mensajes salientes si se especifica
        if ($outgoingLimit !== null) {
            $params[] = '-outgoing-messages-mbox-limit';
            $params[] = (string) $outgoingLimit;
        }
        
        $result = $this->executeCliCommand('mail', $params);
        
        if ($result['http_code'] !== 200) {
            throw new Exception("Error en la API: " . json_encode($result['response']));
        }
        
        $response = $result['response'];
        
        // Verificar si el comando se ejecutó correctamente
        if (isset($response['code']) && $response['code'] !== 0) {
            throw new Exception($response['stderr'] ?? 'Error desconocido al crear el correo');
        }
        
        return $response;
    }
    
    /**
     * Listar cuentas de correo de un dominio (respuesta raw)
     */
    public function listMailboxes(string $domain): array {
        $params = ['--list', $domain];
        
        $result = $this->executeCliCommand('mail', $params);
        
        if ($result['http_code'] !== 200) {
            throw new Exception("Error al listar correos: " . json_encode($result['response']));
        }
        
        return $result['response'];
    }
    
    /**
     * Obtener array de correos existentes en un dominio
     * @return string[] Lista de direcciones de email
     */
    public function getExistingMailboxes(string $domain): array {
        $result = $this->listMailboxes($domain);
        
        $stdout = $result['stdout'] ?? '';
        if (empty($stdout)) {
            return [];
        }
        
        // Parsear: una dirección por línea
        $lines = preg_split('/[\r\n]+/', trim($stdout));
        $emails = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '@') !== false) {
                $emails[] = strtolower($line);
            }
        }
        
        return $emails;
    }
    
    /**
     * Eliminar cuenta de correo
     */
    public function deleteMailbox(string $email): array {
        $params = ['--remove', $email];
        
        $result = $this->executeCliCommand('mail', $params);
        
        if ($result['http_code'] !== 200) {
            throw new Exception("Error al eliminar correo: " . json_encode($result['response']));
        }
        
        return $result['response'];
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
}

<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\DomainResellerApi;

class ApiClient
{
    private string $apiKey;
    private string $baseUrl;
    private bool $sandbox;
    private int $timeout;

    public function __construct(string $apiKey, string $baseUrl = 'https://domain-reseller-api.de', bool $sandbox = false, int $timeout = 30)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->sandbox = $sandbox;
        $this->timeout = $timeout;

        if (!str_starts_with($this->baseUrl, 'https://') && !preg_match('#^http://(localhost|127\.0\.0\.1)(:|/|$)#', $this->baseUrl)) {
            throw new \InvalidArgumentException('API base URL must use HTTPS');
        }
    }

    // ──────────────────────────────────────────────
    // Health
    // ──────────────────────────────────────────────

    public function healthCheck(): array
    {
        return $this->get('/api/v1/health/');
    }

    // ──────────────────────────────────────────────
    // Pricing
    // ──────────────────────────────────────────────

    public function getPricing(?string $tld = null): array
    {
        $params = [];
        if ($tld !== null) {
            $params['tld'] = $tld;
        }
        return $this->get('/api/v1/pricing/', $params);
    }

    // ──────────────────────────────────────────────
    // Domains
    // ──────────────────────────────────────────────

    public function checkDomain(string $domain): array
    {
        return $this->get('/api/v1/domains/check/' . urlencode($domain));
    }

    public function suggestDomains(string $query, array $tlds = []): array
    {
        $params = ['query' => $query];
        if (!empty($tlds)) {
            $params['tlds'] = implode(',', $tlds);
        }
        return $this->get('/api/v1/domains/suggest', $params);
    }

    public function listDomains(int $page = 1, int $limit = 50): array
    {
        return $this->get('/api/v1/domains/', ['page' => $page, 'limit' => $limit]);
    }

    public function getDomain(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain));
    }

    public function registerDomain(string $domain, string $ownerContact, ?string $adminContact = null, ?string $techContact = null, array $nameservers = [], int $years = 1): array
    {
        $body = [
            'domain' => $domain,
            'years' => $years,
            'contacts' => [
                'owner' => $ownerContact,
            ],
        ];

        if ($adminContact !== null) {
            $body['contacts']['admin'] = $adminContact;
        }
        if ($techContact !== null) {
            $body['contacts']['tech'] = $techContact;
        }
        if (!empty($nameservers)) {
            $body['nameservers'] = array_values(array_filter($nameservers));
        }

        return $this->post('/api/v1/domains/register', $body);
    }

    public function transferDomain(string $domain, string $authCode, string $ownerContact, ?string $adminContact = null, ?string $techContact = null): array
    {
        $body = [
            'domain' => $domain,
            'authCode' => $authCode,
            'contacts' => [
                'owner' => $ownerContact,
            ],
        ];

        if ($adminContact !== null) {
            $body['contacts']['admin'] = $adminContact;
        }
        if ($techContact !== null) {
            $body['contacts']['tech'] = $techContact;
        }

        return $this->post('/api/v1/domains/transfer', $body);
    }

    public function updateDomain(string $domain, array $data): array
    {
        return $this->patch('/api/v1/domains/' . urlencode($domain), $data);
    }

    public function deleteDomain(string $domain): array
    {
        return $this->delete('/api/v1/domains/' . urlencode($domain));
    }

    public function getDomainState(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain) . '/state');
    }

    public function getAuthCode(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain) . '/authcode');
    }

    public function resetAuthCode(string $domain): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/reset-authcode');
    }

    public function restoreDomain(string $domain): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/restore');
    }

    public function cancelDelete(string $domain): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/cancel-delete');
    }

    public function getDnssec(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain) . '/dnssec');
    }

    public function updateDnssec(string $domain, array $data): array
    {
        return $this->put('/api/v1/domains/' . urlencode($domain) . '/dnssec', $data);
    }

    public function transferOut(string $domain): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/transfer-out');
    }

    public function tradeDomain(string $domain, array $data): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/trade', $data);
    }

    public function holdDomain(string $domain, array $data = []): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/hold', $data);
    }

    public function getPendingTransferOuts(): array
    {
        return $this->get('/api/v1/domains/transfer-out/pending');
    }

    // ──────────────────────────────────────────────
    // Contacts
    // ──────────────────────────────────────────────

    public function listContacts(int $page = 1, int $limit = 50): array
    {
        return $this->get('/api/v1/contacts/', ['page' => $page, 'limit' => $limit]);
    }

    public function getContact(string $handle): array
    {
        return $this->get('/api/v1/contacts/' . urlencode($handle));
    }

    public function createContact(array $data): array
    {
        return $this->post('/api/v1/contacts/', $data);
    }

    public function updateContact(string $handle, array $data): array
    {
        return $this->patch('/api/v1/contacts/' . urlencode($handle), $data);
    }

    public function deleteContact(string $handle): array
    {
        return $this->delete('/api/v1/contacts/' . urlencode($handle));
    }

    // ──────────────────────────────────────────────
    // DNS
    // ──────────────────────────────────────────────

    public function enableManagedDns(string $domain): array
    {
        return $this->post('/api/v1/dns/' . urlencode($domain) . '/enable');
    }

    public function disableManagedDns(string $domain): array
    {
        return $this->post('/api/v1/dns/' . urlencode($domain) . '/disable');
    }

    public function getDnsRecords(string $domain): array
    {
        return $this->get('/api/v1/dns/' . urlencode($domain));
    }

    public function createDnsRecord(string $domain, string $type, string $name, string $content, int $ttl = 3600, ?int $priority = null): array
    {
        $body = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
        ];

        if ($priority !== null) {
            $body['priority'] = $priority;
        }

        return $this->post('/api/v1/dns/' . urlencode($domain) . '/records', $body);
    }

    public function updateDnsRecord(string $domain, string $recordId, array $data): array
    {
        return $this->patch('/api/v1/dns/' . urlencode($domain) . '/records/' . urlencode($recordId), $data);
    }

    public function deleteDnsRecord(string $domain, string $recordId): array
    {
        return $this->delete('/api/v1/dns/' . urlencode($domain) . '/records/' . urlencode($recordId));
    }

    public function getZoneInfo(string $domain): array
    {
        return $this->get('/api/v1/dns/' . urlencode($domain) . '/zone-info');
    }

    public function exportZone(string $domain): array
    {
        return $this->get('/api/v1/dns/' . urlencode($domain) . '/export');
    }

    public function importZone(string $domain, array $data): array
    {
        return $this->post('/api/v1/dns/' . urlencode($domain) . '/import', $data);
    }

    // ──────────────────────────────────────────────
    // Webhooks
    // ──────────────────────────────────────────────

    public function listWebhookEvents(): array
    {
        return $this->get('/api/v1/webhooks/events');
    }

    public function listWebhooks(): array
    {
        return $this->get('/api/v1/webhooks/');
    }

    public function createWebhook(string $url, array $events = [], bool $isActive = true): array
    {
        return $this->post('/api/v1/webhooks/', [
            'url' => $url,
            'events' => $events,
            'isActive' => $isActive,
        ]);
    }

    public function updateWebhook(string $id, array $data): array
    {
        return $this->patch('/api/v1/webhooks/' . urlencode($id), $data);
    }

    public function deleteWebhook(string $id): array
    {
        return $this->delete('/api/v1/webhooks/' . urlencode($id));
    }

    public function testWebhook(string $id): array
    {
        return $this->post('/api/v1/webhooks/' . urlencode($id) . '/test');
    }

    public function getWebhookLogs(string $id): array
    {
        return $this->get('/api/v1/webhooks/' . urlencode($id) . '/logs');
    }

    // ──────────────────────────────────────────────
    // HTTP methods
    // ──────────────────────────────────────────────

    private function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    private function post(string $endpoint, array $body = []): array
    {
        return $this->request('POST', $endpoint, [], $body);
    }

    private function patch(string $endpoint, array $body = []): array
    {
        return $this->request('PATCH', $endpoint, [], $body);
    }

    private function put(string $endpoint, array $body = []): array
    {
        return $this->request('PUT', $endpoint, [], $body);
    }

    private function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    protected function request(string $method, string $endpoint, array $params = [], array $body = []): array
    {
        $url = $this->baseUrl . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WHMCS-DomainResellerApi/1.0',
        ];

        if ($this->sandbox) {
            $headers[] = 'x-sandbox: true';
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (in_array($method, ['POST', 'PATCH', 'PUT']) && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Connection error: ' . $curlError,
                'httpCode' => 0,
            ];
        }

        $decoded = json_decode($response, true);

        if ($decoded === null) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from API',
                'httpCode' => $httpCode,
                'raw' => substr($response, 0, 200),
            ];
        }

        $decoded['httpCode'] = $httpCode;

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\DomainResellerApi;

class ApiClient
{
    /** Module version, replaced by the release workflow. */
    public const VERSION = 'dev';

    /**
     * Optional factory used by domainresellerapi_getClient() instead of `new ApiClient`
     * (tests inject a client that records requests).
     *
     * @var null|callable(array): ApiClient
     */
    public static $factory = null;

    private string $apiKey;
    private string $baseUrl;
    private bool $sandbox;
    private int $timeout;

    public function __construct(string $apiKey, string $baseUrl = 'https://domain-reseller-api.de', bool $sandbox = false, int $timeout = 30)
    {
        $this->apiKey = $apiKey;
        // Accept a base URL entered with the version prefix (https://host/api/v1)
        $this->baseUrl = (string) preg_replace('#/api/v1$#', '', rtrim(trim($baseUrl), '/'));
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

    /**
     * Bulk availability check (POST /domains/check), up to 50 domains per request.
     */
    public function checkDomains(array $domains, ?int $years = null): array
    {
        $body = ['domains' => array_values($domains)];
        if ($years !== null) {
            $body['years'] = $years;
        }
        return $this->post('/api/v1/domains/check', $body);
    }

    /**
     * GET /domains/suggest?q=label&defaults=tld1,tld2
     */
    public function suggestDomains(string $query, array $tlds = []): array
    {
        $params = ['q' => $query];
        if (!empty($tlds)) {
            $params['defaults'] = implode(',', $tlds);
        }
        return $this->get('/api/v1/domains/suggest', $params);
    }

    public function listDomains(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v1/domains/', ['page' => $page, 'pageSize' => min(100, max(1, $pageSize))]);
    }

    public function getDomain(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain));
    }

    /**
     * POST /domains/register. $years must be a bookable term of the TLD (e.g. 2 for .ai);
     * null lets the API use the TLD's minimum term. $extra is merged into the body
     * (e.g. whoisPrivacy/acceptWppTerms).
     */
    public function registerDomain(string $domain, string $ownerContact, ?string $adminContact = null, ?string $techContact = null, array $nameservers = [], ?int $years = null, array $extra = []): array
    {
        $body = [
            'domain' => $domain,
            'contacts' => [
                'owner' => $ownerContact,
            ],
        ];
        if ($years !== null) {
            $body['years'] = $years;
        }

        if ($adminContact !== null) {
            $body['contacts']['admin'] = $adminContact;
        }
        if ($techContact !== null) {
            $body['contacts']['tech'] = $techContact;
        }
        $nameservers = array_values(array_filter($nameservers));
        // The API requires 2-6 nameservers when the field is sent; without it the managed DNS is used
        if (count($nameservers) >= 2) {
            $body['nameservers'] = array_slice($nameservers, 0, 6);
        }

        return $this->post('/api/v1/domains/register', $body + $extra);
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

    /**
     * PATCH /domains/{domain}/contacts — assign other contact handles to the roles
     * owner/admin/tech/billing. An owner change is only executed with $confirmOwnerChange
     * (otherwise the API answers 409 with the price).
     */
    public function updateDomainContacts(string $domain, array $contacts, bool $confirmOwnerChange = false): array
    {
        $body = ['contacts' => $contacts];
        if ($confirmOwnerChange) {
            $body['confirmOwnerChange'] = true;
        }
        return $this->patch('/api/v1/domains/' . urlencode($domain) . '/contacts', $body);
    }

    /**
     * DELETE /domains/{domain}: cancels at the end of the term, or immediately with $immediate.
     */
    public function deleteDomain(string $domain, bool $immediate = false): array
    {
        return $this->request('DELETE', '/api/v1/domains/' . urlencode($domain), $immediate ? ['immediate' => 'true'] : []);
    }

    /**
     * POST /domains/{domain}/renew. $years must be a bookable term (null = TLD minimum).
     */
    public function renewDomain(string $domain, ?int $years = null): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/renew', $years !== null ? ['years' => $years] : []);
    }

    public function getTransferLock(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain) . '/lock');
    }

    public function setTransferLock(string $domain, bool $locked): array
    {
        return $this->put('/api/v1/domains/' . urlencode($domain) . '/lock', ['locked' => $locked]);
    }

    /**
     * PUT /domains/{domain}/whois-privacy (OpenProvider TLDs, owner of type person).
     */
    public function setWhoisPrivacy(string $domain, bool $enabled, bool $acceptTerms = false): array
    {
        $body = ['enabled' => $enabled];
        if ($enabled) {
            $body['acceptWppTerms'] = $acceptTerms;
        }
        return $this->put('/api/v1/domains/' . urlencode($domain) . '/whois-privacy', $body);
    }

    public function listHosts(string $domain): array
    {
        return $this->get('/api/v1/domains/' . urlencode($domain) . '/hosts');
    }

    public function createHost(string $domain, string $hostname, ?string $ipv4 = null, ?string $ipv6 = null): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/hosts', ['hostname' => $hostname] + self::ips($ipv4, $ipv6));
    }

    public function updateHost(string $domain, string $hostname, ?string $ipv4 = null, ?string $ipv6 = null): array
    {
        return $this->put('/api/v1/domains/' . urlencode($domain) . '/hosts/' . urlencode($hostname), self::ips($ipv4, $ipv6));
    }

    public function deleteHost(string $domain, string $hostname): array
    {
        return $this->delete('/api/v1/domains/' . urlencode($domain) . '/hosts/' . urlencode($hostname));
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

    public function transferOut(string $domain, bool $approve = true): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/transfer-out', ['approve' => $approve]);
    }

    public function tradeDomain(string $domain, array $data): array
    {
        return $this->post('/api/v1/domains/' . urlencode($domain) . '/trade', $data);
    }

    /**
     * POST /domains/{domain}/hold (.de only). $data: disconnect (required), execDate, execOnExpire.
     */
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

    public function listContacts(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v1/contacts/', ['page' => $page, 'pageSize' => min(100, max(1, $pageSize))]);
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

    public function importZone(string $domain, string $zoneFile): array
    {
        return $this->post('/api/v1/dns/' . urlencode($domain) . '/import', ['zoneFile' => $zoneFile]);
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

    /**
     * POST /webhooks/ — at least one event is required; new webhooks are active.
     */
    public function createWebhook(string $url, array $events): array
    {
        return $this->post('/api/v1/webhooks/', [
            'url' => $url,
            'events' => array_values($events),
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

    private static function ips(?string $ipv4, ?string $ipv6): array
    {
        $body = [];
        if ($ipv4 !== null && $ipv4 !== '') {
            $body['ipv4'] = $ipv4;
        }
        if ($ipv6 !== null && $ipv6 !== '') {
            $body['ipv6'] = $ipv6;
        }
        return $body;
    }

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
            'User-Agent: WHMCS-DomainResellerApi/' . self::VERSION,
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

        if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            // Always send a JSON object: the API validates bodies as objects
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(empty($body) ? new \stdClass() : $body));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = $response === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($curlError !== '') {
            return [
                'success' => false,
                'error' => 'Connection error: ' . $curlError,
                'httpCode' => 0,
            ];
        }

        return self::normalizeResponse($httpCode, (string) $response);
    }

    /**
     * Normalises an API response into the envelope used by the module:
     * `success` (false for every non-2xx status), `data`, `error` (a single readable
     * message built from error/message/details) and `httpCode`.
     */
    public static function normalizeResponse(int $httpCode, string $raw): array
    {
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error' => 'Invalid response from the Domain Reseller API (HTTP ' . $httpCode . ')',
                'httpCode' => $httpCode,
                'raw' => substr($raw, 0, 200),
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded['success'] = false;
        } elseif (!array_key_exists('success', $decoded)) {
            // Endpoints without an envelope (health) are successful on 2xx
            $decoded['success'] = true;
        }

        if ($decoded['success'] !== true) {
            $decoded['success'] = false;
            $parts = [];
            foreach (['error', 'message', 'details'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '' && !in_array(trim($decoded[$key]), $parts, true)) {
                    $parts[] = trim($decoded[$key]);
                }
            }
            $message = implode(': ', $parts);
            if ($message === '') {
                $message = 'The Domain Reseller API returned an error (HTTP ' . $httpCode . ')';
            }
            $decoded['error'] = strlen($message) > 500 ? substr($message, 0, 497) . '...' : $message;
        }

        $decoded['httpCode'] = $httpCode;

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Registrar\DomainResellerApi\ApiClient;

/**
 * A testable ApiClient that captures requests instead of making real HTTP calls.
 */
class MockApiClient extends ApiClient
{
    /** @var array[] Captured requests */
    public array $requests = [];

    /** @var array Queue of responses to return */
    public array $responseQueue = [];

    protected function request(string $method, string $endpoint, array $params = [], array $body = []): array
    {
        $this->requests[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => $params,
            'body' => $body,
        ];

        if (!empty($this->responseQueue)) {
            return array_shift($this->responseQueue);
        }

        return ['success' => true, 'data' => [], 'httpCode' => 200];
    }

    public function getLastRequest(): ?array
    {
        return end($this->requests) ?: null;
    }

    public function queueResponse(array $response): void
    {
        $this->responseQueue[] = $response;
    }
}

class ApiClientTest extends TestCase
{
    private MockApiClient $client;

    protected function setUp(): void
    {
        $this->client = new MockApiClient('drapi_test_key123');
    }

    // ─── Constructor & Configuration ───

    public function testConstructorStoresBaseUrl(): void
    {
        $client = new MockApiClient('key', 'https://custom-api.example.com/');
        $client->healthCheck();

        $req = $client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/health/', $req['endpoint']);
    }

    // ─── Health ───

    public function testHealthCheck(): void
    {
        $this->client->healthCheck();

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/health/', $req['endpoint']);
    }

    // ─── Pricing ───

    public function testGetPricingWithoutTld(): void
    {
        $this->client->getPricing();

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/pricing/', $req['endpoint']);
        $this->assertEmpty($req['params']);
    }

    public function testGetPricingWithTld(): void
    {
        $this->client->getPricing('de');

        $req = $this->client->getLastRequest();
        $this->assertSame(['tld' => 'de'], $req['params']);
    }

    // ─── Domains ───

    public function testCheckDomain(): void
    {
        $this->client->checkDomain('example.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/check/example.de', $req['endpoint']);
    }

    public function testSuggestDomainsWithoutTlds(): void
    {
        $this->client->suggestDomains('mysite');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/suggest', $req['endpoint']);
        $this->assertSame(['query' => 'mysite'], $req['params']);
    }

    public function testSuggestDomainsWithTlds(): void
    {
        $this->client->suggestDomains('mysite', ['de', 'com']);

        $req = $this->client->getLastRequest();
        $this->assertSame(['query' => 'mysite', 'tlds' => 'de,com'], $req['params']);
    }

    public function testListDomains(): void
    {
        $this->client->listDomains(2, 25);

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/', $req['endpoint']);
        $this->assertSame(['page' => 2, 'limit' => 25], $req['params']);
    }

    public function testGetDomain(): void
    {
        $this->client->getDomain('example.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/example.de', $req['endpoint']);
    }

    public function testRegisterDomainMinimal(): void
    {
        $this->client->registerDomain('test.de', 'OWNER-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/register', $req['endpoint']);
        $this->assertSame('test.de', $req['body']['domain']);
        $this->assertSame(1, $req['body']['years']);
        $this->assertSame('OWNER-123', $req['body']['contacts']['owner']);
        $this->assertArrayNotHasKey('admin', $req['body']['contacts']);
        $this->assertArrayNotHasKey('tech', $req['body']['contacts']);
        $this->assertArrayNotHasKey('nameservers', $req['body']);
    }

    public function testRegisterDomainFull(): void
    {
        $this->client->registerDomain(
            'test.de',
            'OWNER-123',
            'ADMIN-456',
            'TECH-789',
            ['ns1.example.com', 'ns2.example.com'],
            2
        );

        $req = $this->client->getLastRequest();
        $this->assertSame(2, $req['body']['years']);
        $this->assertSame('ADMIN-456', $req['body']['contacts']['admin']);
        $this->assertSame('TECH-789', $req['body']['contacts']['tech']);
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $req['body']['nameservers']);
    }

    public function testRegisterDomainFiltersEmptyNameservers(): void
    {
        $this->client->registerDomain('test.de', 'OWNER-123', null, null, ['ns1.example.com', '', 'ns2.example.com', '']);

        $req = $this->client->getLastRequest();
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $req['body']['nameservers']);
    }

    public function testTransferDomain(): void
    {
        $this->client->transferDomain('test.de', 'AUTH123', 'OWNER-1', 'ADMIN-2');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/transfer', $req['endpoint']);
        $this->assertSame('test.de', $req['body']['domain']);
        $this->assertSame('AUTH123', $req['body']['authCode']);
        $this->assertSame('OWNER-1', $req['body']['contacts']['owner']);
        $this->assertSame('ADMIN-2', $req['body']['contacts']['admin']);
    }

    public function testUpdateDomain(): void
    {
        $this->client->updateDomain('test.de', ['autoRenew' => true]);

        $req = $this->client->getLastRequest();
        $this->assertSame('PATCH', $req['method']);
        $this->assertSame('/api/v1/domains/test.de', $req['endpoint']);
        $this->assertSame(['autoRenew' => true], $req['body']);
    }

    public function testDeleteDomain(): void
    {
        $this->client->deleteDomain('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/api/v1/domains/test.de', $req['endpoint']);
    }

    public function testGetDomainState(): void
    {
        $this->client->getDomainState('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/state', $req['endpoint']);
    }

    public function testGetAuthCode(): void
    {
        $this->client->getAuthCode('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/authcode', $req['endpoint']);
    }

    public function testResetAuthCode(): void
    {
        $this->client->resetAuthCode('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/reset-authcode', $req['endpoint']);
    }

    public function testRestoreDomain(): void
    {
        $this->client->restoreDomain('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/restore', $req['endpoint']);
    }

    public function testCancelDelete(): void
    {
        $this->client->cancelDelete('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/cancel-delete', $req['endpoint']);
    }

    public function testGetDnssec(): void
    {
        $this->client->getDnssec('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/dnssec', $req['endpoint']);
    }

    public function testUpdateDnssec(): void
    {
        $this->client->updateDnssec('test.de', ['enabled' => true]);

        $req = $this->client->getLastRequest();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/dnssec', $req['endpoint']);
        $this->assertSame(['enabled' => true], $req['body']);
    }

    public function testTransferOut(): void
    {
        $this->client->transferOut('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/transfer-out', $req['endpoint']);
    }

    public function testTradeDomain(): void
    {
        $this->client->tradeDomain('test.eu', ['newOwner' => 'HANDLE-1']);

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/test.eu/trade', $req['endpoint']);
    }

    public function testHoldDomain(): void
    {
        $this->client->holdDomain('test.de', ['hold' => true]);

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/domains/test.de/hold', $req['endpoint']);
    }

    public function testGetPendingTransferOuts(): void
    {
        $this->client->getPendingTransferOuts();

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/domains/transfer-out/pending', $req['endpoint']);
    }

    // ─── Contacts ───

    public function testListContacts(): void
    {
        $this->client->listContacts(3, 10);

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/contacts/', $req['endpoint']);
        $this->assertSame(['page' => 3, 'limit' => 10], $req['params']);
    }

    public function testGetContact(): void
    {
        $this->client->getContact('HANDLE-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/contacts/HANDLE-123', $req['endpoint']);
    }

    public function testCreateContact(): void
    {
        $data = ['type' => 'person', 'firstName' => 'Max', 'lastName' => 'Mustermann'];
        $this->client->createContact($data);

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/contacts/', $req['endpoint']);
        $this->assertSame($data, $req['body']);
    }

    public function testUpdateContact(): void
    {
        $this->client->updateContact('HANDLE-123', ['firstName' => 'Moritz']);

        $req = $this->client->getLastRequest();
        $this->assertSame('PATCH', $req['method']);
        $this->assertSame('/api/v1/contacts/HANDLE-123', $req['endpoint']);
    }

    public function testDeleteContact(): void
    {
        $this->client->deleteContact('HANDLE-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/api/v1/contacts/HANDLE-123', $req['endpoint']);
    }

    // ─── DNS ───

    public function testEnableManagedDns(): void
    {
        $this->client->enableManagedDns('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/enable', $req['endpoint']);
    }

    public function testDisableManagedDns(): void
    {
        $this->client->disableManagedDns('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/disable', $req['endpoint']);
    }

    public function testGetDnsRecords(): void
    {
        $this->client->getDnsRecords('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/dns/test.de', $req['endpoint']);
    }

    public function testCreateDnsRecordWithoutPriority(): void
    {
        $this->client->createDnsRecord('test.de', 'A', 'www', '1.2.3.4', 300);

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/records', $req['endpoint']);
        $this->assertSame([
            'type' => 'A',
            'name' => 'www',
            'content' => '1.2.3.4',
            'ttl' => 300,
        ], $req['body']);
    }

    public function testCreateDnsRecordWithPriority(): void
    {
        $this->client->createDnsRecord('test.de', 'MX', '@', 'mail.test.de', 3600, 10);

        $req = $this->client->getLastRequest();
        $this->assertSame(10, $req['body']['priority']);
    }

    public function testUpdateDnsRecord(): void
    {
        $this->client->updateDnsRecord('test.de', 'rec-123', ['content' => '5.6.7.8']);

        $req = $this->client->getLastRequest();
        $this->assertSame('PATCH', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/records/rec-123', $req['endpoint']);
    }

    public function testDeleteDnsRecord(): void
    {
        $this->client->deleteDnsRecord('test.de', 'rec-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/records/rec-123', $req['endpoint']);
    }

    public function testGetZoneInfo(): void
    {
        $this->client->getZoneInfo('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/zone-info', $req['endpoint']);
    }

    public function testExportZone(): void
    {
        $this->client->exportZone('test.de');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/export', $req['endpoint']);
    }

    public function testImportZone(): void
    {
        $this->client->importZone('test.de', ['zone' => 'data']);

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/dns/test.de/import', $req['endpoint']);
    }

    // ─── Webhooks ───

    public function testListWebhookEvents(): void
    {
        $this->client->listWebhookEvents();

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/webhooks/events', $req['endpoint']);
    }

    public function testListWebhooks(): void
    {
        $this->client->listWebhooks();

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/webhooks/', $req['endpoint']);
    }

    public function testCreateWebhook(): void
    {
        $this->client->createWebhook('https://example.com/hook', ['domain.register'], true);

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/webhooks/', $req['endpoint']);
        $this->assertSame('https://example.com/hook', $req['body']['url']);
        $this->assertSame(['domain.register'], $req['body']['events']);
        $this->assertTrue($req['body']['isActive']);
    }

    public function testUpdateWebhook(): void
    {
        $this->client->updateWebhook('wh-123', ['isActive' => false]);

        $req = $this->client->getLastRequest();
        $this->assertSame('PATCH', $req['method']);
        $this->assertSame('/api/v1/webhooks/wh-123', $req['endpoint']);
    }

    public function testDeleteWebhook(): void
    {
        $this->client->deleteWebhook('wh-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('DELETE', $req['method']);
        $this->assertSame('/api/v1/webhooks/wh-123', $req['endpoint']);
    }

    public function testTestWebhook(): void
    {
        $this->client->testWebhook('wh-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/webhooks/wh-123/test', $req['endpoint']);
    }

    public function testGetWebhookLogs(): void
    {
        $this->client->getWebhookLogs('wh-123');

        $req = $this->client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/api/v1/webhooks/wh-123/logs', $req['endpoint']);
    }

    // ─── Response handling ───

    public function testResponsePassedThrough(): void
    {
        $expected = ['success' => true, 'data' => ['domain' => 'test.de'], 'httpCode' => 200];
        $this->client->queueResponse($expected);

        $result = $this->client->getDomain('test.de');
        $this->assertSame($expected, $result);
    }

    public function testMultipleQueuedResponses(): void
    {
        $this->client->queueResponse(['success' => true, 'data' => ['first' => true]]);
        $this->client->queueResponse(['success' => false, 'error' => 'fail']);

        $r1 = $this->client->healthCheck();
        $r2 = $this->client->healthCheck();

        $this->assertTrue($r1['success']);
        $this->assertFalse($r2['success']);
    }
}

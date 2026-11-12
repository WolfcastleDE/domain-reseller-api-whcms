<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Domains\DomainLookup\ResultsList as LookupResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\DomainResellerApi\ApiClient;
use WHMCS\Results\ResultsList;

/**
 * Tests the WHMCS registrar functions end-to-end against a recording ApiClient
 * (injected via ApiClient::$factory). Requests are checked against the route
 * schemas of the backend (packages/backend/src/routes/*.ts).
 */
class RegistrarModuleTest extends TestCase
{
    private MockApiClient $client;

    protected function setUp(): void
    {
        $this->client = new MockApiClient('drapi_test_key123');
        $client = $this->client;
        ApiClient::$factory = static fn (array $params) => $client;
    }

    protected function tearDown(): void
    {
        ApiClient::$factory = null;
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'ApiKey' => 'drapi_test_key123',
            'ApiUrl' => 'https://domain-reseller-api.de',
            'Sandbox' => '',
            'Timeout' => '30',
            'sld' => 'example',
            'tld' => 'de',
            'regperiod' => 1,
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'companyname' => '',
            'email' => 'max@example.de',
            'phonenumber' => '01234567890',
            'fullphonenumber' => '+49.1234567890',
            'address1' => 'Musterstr 42',
            'city' => 'Berlin',
            'postcode' => '10115',
            'state' => 'Berlin',
            'country' => 'DE',
            'ns1' => 'ns1.example.com',
            'ns2' => 'ns2.example.com',
        ], $overrides);
    }

    private function ok(array $data = []): array
    {
        return ['success' => true, 'data' => $data, 'httpCode' => 200];
    }

    private function err(string $error, int $httpCode = 400, array $extra = []): array
    {
        return ['success' => false, 'error' => $error, 'httpCode' => $httpCode] + $extra;
    }

    /** @return array{0: string, 1: string}[] method + endpoint of every request */
    private function calls(): array
    {
        return array_map(static fn ($r) => [$r['method'], $r['endpoint']], $this->client->requests);
    }

    private function request(string $method, string $endpoint): array
    {
        foreach ($this->client->requests as $request) {
            if ($request['method'] === $method && $request['endpoint'] === $endpoint) {
                return $request;
            }
        }
        self::fail("No $method $endpoint request");
    }

    // ─── RegisterDomain ───

    public function testRegisterDomain(): void
    {
        $this->client->queueResponse($this->ok(['id' => 'c1', 'handle' => 'OWNER-1', 'message' => 'ok']));
        $this->client->queueResponse($this->ok(['domainId' => 'd1', 'domain' => 'example.de', 'status' => 'active']));
        $this->client->queueResponse(['success' => true, 'httpCode' => 200]);

        $result = domainresellerapi_RegisterDomain($this->params());

        $this->assertSame(['success' => true], $result);
        $this->assertSame([
            ['POST', '/api/v1/contacts/'],
            ['POST', '/api/v1/domains/register'],
            ['PATCH', '/api/v1/domains/example.de'],
        ], $this->calls());

        $contact = $this->client->requests[0]['body'];
        $this->assertSame('person', $contact['type']);
        $this->assertSame('NA', $contact['sex']);
        $this->assertSame('Musterstr', $contact['street']);
        $this->assertSame('42', $contact['houseNumber']);
        $this->assertArrayNotHasKey('organization', $contact);

        $body = $this->client->requests[1]['body'];
        $this->assertSame('example.de', $body['domain']);
        $this->assertSame(1, $body['years']);
        $this->assertSame(['owner' => 'OWNER-1'], $body['contacts']);
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $body['nameservers']);

        // WHMCS renews on payment: the API's own auto-renewal is turned off
        $this->assertSame(['autoRenew' => false], $this->client->requests[2]['body']);
    }

    public function testRegisterDomainKeepsApiAutoRenewWhenConfigured(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->ok(['domain' => 'example.de']));

        domainresellerapi_RegisterDomain($this->params(['ApiAutoRenew' => 'on']));

        $this->assertCount(2, $this->client->requests);
    }

    public function testRegisterDomainOrganizationWithAdminAndTech(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->ok(['handle' => 'ADMIN-1']));
        $this->client->queueResponse($this->ok(['handle' => 'TECH-1']));
        $this->client->queueResponse($this->ok(['domain' => 'example.ai']));

        $result = domainresellerapi_RegisterDomain($this->params([
            'tld' => 'ai',
            'regperiod' => 2,
            'companyname' => 'ACME GmbH',
            'adminfirstname' => 'Anna', 'adminlastname' => 'Admin', 'adminemail' => 'admin@acme.de',
            'adminfullphonenumber' => '+49.301111', 'adminaddress1' => 'Weg 1', 'admincity' => 'Berlin',
            'adminpostcode' => '10115', 'admincountry' => 'DE',
            'techfirstname' => 'Tom', 'techlastname' => 'Tech', 'techemail' => 'tech@acme.de',
            'techfullphonenumber' => '+49.302222', 'techaddress1' => 'Weg 2', 'techcity' => 'Berlin',
            'techpostcode' => '10115', 'techcountry' => 'DE',
        ]));

        $this->assertSame(['success' => true], $result);
        $owner = $this->client->requests[0]['body'];
        $this->assertSame('organization', $owner['type']);
        $this->assertSame('ACME GmbH', $owner['organization']);

        $body = $this->request('POST', '/api/v1/domains/register')['body'];
        // .ai has a minimum term of 2 years; the WHMCS term is passed through
        $this->assertSame(2, $body['years']);
        $this->assertSame(['owner' => 'OWNER-1', 'admin' => 'ADMIN-1', 'tech' => 'TECH-1'], $body['contacts']);
    }

    public function testRegisterDomainWithoutNameserversUsesManagedDns(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->ok(['domain' => 'example.de']));

        domainresellerapi_RegisterDomain($this->params(['ns1' => 'ns1.example.com', 'ns2' => '']));

        $this->assertArrayNotHasKey('nameservers', $this->request('POST', '/api/v1/domains/register')['body']);
    }

    public function testRegisterDomainIdProtection(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->ok(['domain' => 'example.com']));
        $this->client->queueResponse(['success' => true, 'httpCode' => 200]);
        $this->client->queueResponse($this->ok(['domain' => 'example.com', 'whoisPrivacy' => true]));

        domainresellerapi_RegisterDomain($this->params(['tld' => 'com', 'idprotection' => true]));

        // Vautron TLDs: contact protection flag; OpenProvider TLDs: whois-privacy after registration
        $this->assertTrue($this->client->requests[0]['body']['protection']);
        $this->assertArrayNotHasKey('whoisPrivacy', $this->request('POST', '/api/v1/domains/register')['body']);
        $this->assertSame(
            ['enabled' => true, 'acceptWppTerms' => true],
            $this->request('PUT', '/api/v1/domains/example.com/whois-privacy')['body']
        );
    }

    public function testRegisterDomainItSendsRegCodeAsPin(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->ok(['domain' => 'example.it']));

        domainresellerapi_RegisterDomain($this->params(['tld' => 'it', 'additionalfields' => ['Tax ID' => 'RSSMRA80A01H501U']]));

        $this->assertSame('RSSMRA80A01H501U', $this->client->requests[0]['body']['pin']);
    }

    public function testRegisterDomainReturnsApiError(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->err('Insufficient wallet balance', 402));

        $result = domainresellerapi_RegisterDomain($this->params());

        $this->assertSame(['error' => 'Insufficient wallet balance'], $result);
        $this->assertCount(2, $this->client->requests);
    }

    public function testRegisterDomainRejectsInvalidContactBeforeApiCall(): void
    {
        $result = domainresellerapi_RegisterDomain($this->params(['fullphonenumber' => '', 'phonenumber' => '', 'country' => '']));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('phone', $result['error']);
        $this->assertSame([], $this->client->requests);
    }

    public function testRegisterDomainConnectionErrorWithRealClient(): void
    {
        ApiClient::$factory = null;
        $result = domainresellerapi_RegisterDomain($this->params(['ApiUrl' => 'http://127.0.0.1:1', 'Timeout' => '5']));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection error', $result['error']);
    }

    // ─── TransferDomain ───

    public function testTransferDomain(): void
    {
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-1']));
        $this->client->queueResponse($this->ok(['handle' => 'TECH-1']));
        $this->client->queueResponse($this->ok(['domain' => 'example.de', 'status' => 'pending_transfer']));

        $result = domainresellerapi_TransferDomain($this->params([
            'eppcode' => 'AUTH-123',
            'techfirstname' => 'Tom', 'techlastname' => 'Tech', 'techemail' => 'tech@example.de',
            'techfullphonenumber' => '+49.302222', 'techaddress1' => 'Weg 2', 'techcity' => 'Berlin',
            'techpostcode' => '10115', 'techcountry' => 'DE',
        ]));

        $this->assertSame(['success' => true], $result);
        $body = $this->request('POST', '/api/v1/domains/transfer')['body'];
        $this->assertSame('example.de', $body['domain']);
        $this->assertSame('AUTH-123', $body['authCode']);
        $this->assertSame(['owner' => 'OWNER-1', 'tech' => 'TECH-1'], $body['contacts']);
    }

    public function testTransferDomainRequiresAuthCode(): void
    {
        $result = domainresellerapi_TransferDomain($this->params(['eppcode' => '']));

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $this->client->requests);
    }

    // ─── RenewDomain ───

    public function testRenewDomainCallsRenewEndpoint(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.ai', 'years' => 2, 'expiresAt' => '2028-11-12T00:00:00.000Z']));

        $result = domainresellerapi_RenewDomain($this->params(['tld' => 'ai', 'regperiod' => 2]));

        $this->assertSame(['success' => true], $result);
        $this->assertSame([['POST', '/api/v1/domains/example.ai/renew']], $this->calls());
        $this->assertSame(['years' => 2], $this->client->requests[0]['body']);
    }

    public function testRenewDomainInRedemptionRestores(): void
    {
        domainresellerapi_RenewDomain($this->params(['isInRedemptionGracePeriod' => true]));

        $this->assertSame([['POST', '/api/v1/domains/example.de/restore']], $this->calls());
    }

    public function testRenewDomainError(): void
    {
        $this->client->queueResponse($this->err('Renewal term not available', 400));

        $this->assertSame(['error' => 'Renewal term not available'], domainresellerapi_RenewDomain($this->params()));
    }

    // ─── Nameservers ───

    public function testGetNameservers(): void
    {
        $this->client->queueResponse($this->ok(['nameservers' => ['ns1.a.de', 'ns2.a.de']]));

        $this->assertSame(['ns1' => 'ns1.a.de', 'ns2' => 'ns2.a.de'], domainresellerapi_GetNameservers($this->params()));
        $this->assertSame([['GET', '/api/v1/domains/example.de']], $this->calls());
    }

    public function testGetNameserversError(): void
    {
        $this->client->queueResponse($this->err('Domain not found', 404));

        $this->assertSame(['error' => 'Domain not found'], domainresellerapi_GetNameservers($this->params()));
    }

    public function testSaveNameservers(): void
    {
        $result = domainresellerapi_SaveNameservers($this->params(['ns1' => 'NS1.Example.com.', 'ns2' => 'ns2.example.com', 'ns3' => '']));

        $this->assertSame(['success' => true], $result);
        $request = $this->request('PATCH', '/api/v1/domains/example.de');
        $this->assertSame(['nameservers' => ['ns1.example.com', 'ns2.example.com']], $request['body']);
    }

    public function testSaveNameserversRequiresMinimumTwo(): void
    {
        $result = domainresellerapi_SaveNameservers($this->params(['ns2' => '']));

        $this->assertStringContainsString('At least 2 nameservers', $result['error']);
        $this->assertSame([], $this->client->requests);
    }

    public function testSaveNameserversRejectsInvalidHostname(): void
    {
        $result = domainresellerapi_SaveNameservers($this->params(['ns2' => 'not a host']));

        $this->assertStringContainsString('Invalid nameserver', $result['error']);
        $this->assertSame([], $this->client->requests);
    }

    // ─── Registrar lock ───

    public function testGetRegistrarLock(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.com', 'supported' => true, 'locked' => true]));
        $this->assertSame('locked', domainresellerapi_GetRegistrarLock($this->params(['tld' => 'com'])));
        $this->assertSame([['GET', '/api/v1/domains/example.com/lock']], $this->calls());

        $this->client->queueResponse($this->ok(['domain' => 'example.com', 'supported' => true, 'locked' => false]));
        $this->assertSame('unlocked', domainresellerapi_GetRegistrarLock($this->params(['tld' => 'com'])));
    }

    public function testGetRegistrarLockUnsupported(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.de', 'supported' => false, 'locked' => null]));

        $this->assertSame('', domainresellerapi_GetRegistrarLock($this->params()));
    }

    public function testSaveRegistrarLock(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.com', 'locked' => false]));

        $result = domainresellerapi_SaveRegistrarLock($this->params(['tld' => 'com', 'lockenabled' => 'unlocked']));

        $this->assertSame(['success' => true], $result);
        $request = $this->request('PUT', '/api/v1/domains/example.com/lock');
        $this->assertSame(['locked' => false], $request['body']);
    }

    public function testSaveRegistrarLockError(): void
    {
        $this->client->queueResponse($this->err('Transfer lock is not supported for this TLD', 422));

        $this->assertSame(
            ['error' => 'Transfer lock is not supported for this TLD'],
            domainresellerapi_SaveRegistrarLock($this->params(['lockenabled' => 'locked']))
        );
    }

    // ─── EPP code ───

    public function testGetEPPCode(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.de', 'authCode' => 'ABC-123']));

        $this->assertSame(['eppcode' => 'ABC-123'], domainresellerapi_GetEPPCode($this->params()));
        $this->assertSame([['GET', '/api/v1/domains/example.de/authcode']], $this->calls());
    }

    public function testGetEPPCodeFallsBackToStoredCode(): void
    {
        $this->client->queueResponse($this->err('Auth code retrieval is only supported for .de and .eu domains', 400));
        $this->client->queueResponse($this->ok(['name' => 'example.com', 'authCode' => 'STORED-1']));

        $this->assertSame(['eppcode' => 'STORED-1'], domainresellerapi_GetEPPCode($this->params(['tld' => 'com'])));
    }

    public function testGetEPPCodeSentinelIsAnError(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.de', 'authCode' => 'Unable to retrieve auth code']));
        $this->client->queueResponse($this->ok(['name' => 'example.de', 'authCode' => null]));

        $result = domainresellerapi_GetEPPCode($this->params());
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('eppcode', $result);
    }

    // ─── Glue records ───

    public function testRegisterNameserver(): void
    {
        $result = domainresellerapi_RegisterNameserver($this->params(['tld' => 'com', 'nameserver' => 'NS1.example.com', 'ipaddress' => '192.0.2.10']));

        $this->assertSame(['success' => true], $result);
        $this->assertSame(
            ['hostname' => 'ns1.example.com', 'ipv4' => '192.0.2.10'],
            $this->request('POST', '/api/v1/domains/example.com/hosts')['body']
        );
    }

    public function testModifyNameserverIpv6(): void
    {
        domainresellerapi_ModifyNameserver($this->params(['tld' => 'com', 'nameserver' => 'ns1.example.com', 'currentipaddress' => '192.0.2.10', 'newipaddress' => '2001:db8::10']));

        $this->assertSame(['ipv6' => '2001:db8::10'], $this->request('PUT', '/api/v1/domains/example.com/hosts/ns1.example.com')['body']);
    }

    public function testDeleteNameserver(): void
    {
        domainresellerapi_DeleteNameserver($this->params(['tld' => 'com', 'nameserver' => 'ns1.example.com']));

        $this->assertSame([['DELETE', '/api/v1/domains/example.com/hosts/ns1.example.com']], $this->calls());
    }

    public function testRegisterNameserverInvalidIp(): void
    {
        $result = domainresellerapi_RegisterNameserver($this->params(['nameserver' => 'ns1.example.de', 'ipaddress' => 'nope']));

        $this->assertStringContainsString('Invalid IP', $result['error']);
        $this->assertSame([], $this->client->requests);
    }

    // ─── ID protection ───

    public function testIdProtectToggleOpenprovider(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.com', 'whoisPrivacy' => true]));

        $result = domainresellerapi_IDProtectToggle($this->params(['tld' => 'com', 'protectenable' => true]));

        $this->assertSame(['success' => true], $result);
        $this->assertSame(['enabled' => true, 'acceptWppTerms' => true], $this->request('PUT', '/api/v1/domains/example.com/whois-privacy')['body']);
    }

    public function testIdProtectToggleVautronUsesContactProtection(): void
    {
        $this->client->queueResponse($this->err('WHOIS privacy is not available for this TLD. For Vautron TLDs use the contact-level WHOIS protection flag instead.', 400));
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1']]));
        $this->client->queueResponse(['success' => true, 'message' => 'Contact updated successfully', 'httpCode' => 200]);

        $result = domainresellerapi_IDProtectToggle($this->params(['protectenable' => false]));

        $this->assertSame(['success' => true], $result);
        $this->assertSame(['enabled' => false], $this->client->requests[0]['body']);
        $this->assertSame(['protection' => false], $this->request('PATCH', '/api/v1/contacts/OWNER-1')['body']);
    }

    // ─── DNS ───

    public function testGetDns(): void
    {
        $this->client->queueResponse($this->ok(['domain' => 'example.de', 'useManagedDns' => true, 'records' => [
            ['id' => '1', 'type' => 'NS', 'name' => '@', 'content' => 'ns1.domain-reseller-api.de', 'ttl' => 3600],
            ['id' => '2', 'type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => 3600],
            ['id' => '3', 'type' => 'MX', 'name' => '@', 'content' => 'mail.example.de', 'ttl' => 3600, 'priority' => 10],
            ['id' => '4', 'type' => 'Redirect', 'name' => 'go', 'content' => 'https://example.org', 'ttl' => 3600],
        ]]));

        $records = domainresellerapi_GetDNS($this->params());

        $this->assertSame([['GET', '/api/v1/dns/example.de']], $this->calls());
        $this->assertSame([
            ['hostname' => '@', 'type' => 'A', 'address' => '192.0.2.1'],
            ['hostname' => '@', 'type' => 'MX', 'address' => 'mail.example.de', 'priority' => 10],
            ['hostname' => 'go', 'type' => 'URL', 'address' => 'https://example.org'],
        ], $records);
    }

    public function testGetDnsError(): void
    {
        $this->client->queueResponse($this->err('Managed DNS is not enabled for this domain. Enable it first to manage DNS records.', 200));

        $this->assertStringContainsString('Managed DNS is not enabled', domainresellerapi_GetDNS($this->params())['error']);
    }

    public function testSaveDnsKeepsUnchangedCreatesNewDeletesObsolete(): void
    {
        $this->client->queueResponse($this->ok(['useManagedDns' => true]));
        $this->client->queueResponse($this->ok(['records' => [
            ['id' => 'ns', 'type' => 'NS', 'name' => '@', 'content' => 'ns1.domain-reseller-api.de', 'ttl' => 3600],
            ['id' => 'keep', 'type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => 3600],
            ['id' => 'old', 'type' => 'A', 'name' => 'www', 'content' => '192.0.2.99', 'ttl' => 3600],
        ]]));

        $result = domainresellerapi_SaveDNS($this->params(['dnsrecords' => [
            ['hostname' => '', 'type' => 'A', 'address' => '192.0.2.1'],
            ['hostname' => 'www', 'type' => 'A', 'address' => '192.0.2.2'],
            ['hostname' => '@', 'type' => 'MX', 'address' => 'mail.example.de', 'priority' => '20'],
            ['hostname' => 'go', 'type' => 'URL', 'address' => 'https://example.org'],
        ]]));

        $this->assertSame(['success' => true], $result);
        $this->assertSame([
            ['GET', '/api/v1/domains/example.de'],
            ['GET', '/api/v1/dns/example.de'],
            ['POST', '/api/v1/dns/example.de/records'],
            ['POST', '/api/v1/dns/example.de/records'],
            ['POST', '/api/v1/dns/example.de/records'],
            ['DELETE', '/api/v1/dns/example.de/records/old'],
        ], $this->calls());

        $this->assertSame(['type' => 'A', 'name' => 'www', 'content' => '192.0.2.2', 'ttl' => 3600], $this->client->requests[2]['body']);
        $this->assertSame(['type' => 'MX', 'name' => '@', 'content' => 'mail.example.de', 'ttl' => 3600, 'priority' => 20], $this->client->requests[3]['body']);
        $this->assertSame('Redirect', $this->client->requests[4]['body']['type']);
    }

    public function testSaveDnsPreservesRecordsWhenAllCreationsFail(): void
    {
        $this->client->queueResponse($this->ok(['useManagedDns' => true]));
        $this->client->queueResponse($this->ok(['records' => [
            ['id' => 'old', 'type' => 'A', 'name' => 'www', 'content' => '192.0.2.99', 'ttl' => 3600],
        ]]));
        $this->client->queueResponse($this->err('Validation error', 400));

        $result = domainresellerapi_SaveDNS($this->params(['dnsrecords' => [
            ['hostname' => 'www', 'type' => 'A', 'address' => 'invalid'],
        ]]));

        $this->assertStringContainsString('existing records preserved', $result['error']);
        $this->assertNotContains(['DELETE', '/api/v1/dns/example.de/records/old'], $this->calls());
    }

    public function testSaveDnsEnablesManagedDns(): void
    {
        $this->client->queueResponse($this->ok(['useManagedDns' => false]));
        $this->client->queueResponse(['success' => true, 'message' => 'Managed DNS enabled', 'httpCode' => 200]);
        $this->client->queueResponse($this->ok(['records' => []]));

        domainresellerapi_SaveDNS($this->params(['dnsrecords' => []]));

        $this->assertSame(['POST', '/api/v1/dns/example.de/enable'], $this->calls()[1]);
    }

    public function testSaveDnsUnsupportedType(): void
    {
        $this->client->queueResponse($this->ok(['useManagedDns' => true]));
        $this->client->queueResponse($this->ok(['records' => []]));

        $result = domainresellerapi_SaveDNS($this->params(['dnsrecords' => [
            ['hostname' => 'www', 'type' => 'FRAME', 'address' => 'https://example.org'],
        ]]));

        $this->assertStringContainsString('not supported', $result['error']);
        $this->assertCount(2, $this->client->requests);
    }

    // ─── Contacts ───

    private function apiContact(string $handle, array $overrides = []): array
    {
        return $this->ok(array_merge([
            'id' => 'id-' . $handle, 'handle' => $handle, 'type' => 'person', 'sex' => 'NA',
            'firstName' => 'Max', 'lastName' => 'Mustermann', 'organization' => null,
            'street' => 'Musterstraße', 'houseNumber' => '42', 'city' => 'Berlin', 'postalCode' => '10115',
            'state' => null, 'country' => 'DE', 'extension' => null, 'email' => 'max@example.de',
            'phone' => '+49.301234567', 'pin' => null,
        ], $overrides));
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'First Name' => 'Max', 'Last Name' => 'Mustermann', 'Company Name' => '',
            'Email Address' => 'max@example.de', 'Phone Number' => '+49.301234567',
            'Address 1' => 'Musterstraße 42', 'Address 2' => '', 'City' => 'Berlin', 'State' => '',
            'Postcode' => '10115', 'Country' => 'DE',
        ], $overrides);
    }

    public function testGetContactDetails(): void
    {
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1', 'admin' => 'OWNER-1', 'tech' => 'TECH-1', 'billing' => 'OWNER-1']]));
        $this->client->queueResponse($this->apiContact('OWNER-1'));
        $this->client->queueResponse($this->apiContact('TECH-1', ['type' => 'organization', 'organization' => 'Hosting GmbH', 'houseNumber' => null]));

        $details = domainresellerapi_GetContactDetails($this->params());

        // Each handle is fetched once
        $this->assertSame([
            ['GET', '/api/v1/domains/example.de'],
            ['GET', '/api/v1/contacts/OWNER-1'],
            ['GET', '/api/v1/contacts/TECH-1'],
        ], $this->calls());
        $this->assertSame(['Registrant', 'Admin', 'Technical', 'Billing'], array_keys($details));
        $this->assertSame('Musterstraße 42', $details['Registrant']['Address 1']);
        $this->assertSame('+49.301234567', $details['Registrant']['Phone Number']);
        $this->assertSame('', $details['Registrant']['Company Name']);
        $this->assertSame('Hosting GmbH', $details['Technical']['Company Name']);
        $this->assertSame('Musterstraße', $details['Technical']['Address 1']);
    }

    public function testSaveContactDetailsUpdatesHandleInPlace(): void
    {
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1', 'admin' => 'ADMIN-1']]));
        $this->client->queueResponse($this->apiContact('OWNER-1'));
        $this->client->queueResponse(['success' => true, 'message' => 'ok', 'httpCode' => 200]);

        $result = domainresellerapi_SaveContactDetails($this->params(['contactdetails' => [
            'Registrant' => $this->details(['City' => 'Hamburg', 'Phone Number' => '040 123456']),
        ]]));

        $this->assertSame(['success' => true], $result);
        $body = $this->request('PATCH', '/api/v1/contacts/OWNER-1')['body'];
        // The contact type cannot be changed via PATCH
        $this->assertArrayNotHasKey('type', $body);
        $this->assertArrayNotHasKey('sex', $body);
        $this->assertSame('Hamburg', $body['city']);
        $this->assertSame('+49.40123456', $body['phone']);
        $this->assertSame('Musterstraße', $body['street']);
        $this->assertSame('42', $body['houseNumber']);
        // Empty organisation clears it for persons
        $this->assertSame('', $body['organization']);
    }

    public function testSaveContactDetailsSharedHandleGetsOwnContact(): void
    {
        // The API assigns the owner to roles that were not set at registration
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1', 'admin' => 'OWNER-1', 'tech' => 'OWNER-1', 'billing' => 'OWNER-1']]));
        $this->client->queueResponse($this->apiContact('OWNER-1'));
        $this->client->queueResponse(['success' => true, 'httpCode' => 200]);
        $this->client->queueResponse($this->ok(['handle' => 'ADMIN-NEW']));
        $this->client->queueResponse($this->ok(['domain' => 'example.de', 'ownerChanged' => false, 'method' => 'update', 'charged' => 0]));

        $result = domainresellerapi_SaveContactDetails($this->params(['contactdetails' => [
            'Registrant' => $this->details(['City' => 'Hamburg']),
            'Admin' => $this->details(['First Name' => 'Anna']),
            'Technical' => $this->details(['City' => 'Hamburg']),
        ]]));

        $this->assertSame(['success' => true], $result);
        $this->assertSame([
            ['GET', '/api/v1/domains/example.de'],
            ['GET', '/api/v1/contacts/OWNER-1'],
            ['PATCH', '/api/v1/contacts/OWNER-1'],
            ['POST', '/api/v1/contacts/'],
            ['PATCH', '/api/v1/domains/example.de/contacts'],
        ], $this->calls());
        $this->assertSame('person', $this->client->requests[3]['body']['type']);
        $this->assertSame('Anna', $this->client->requests[3]['body']['firstName']);
        // Admin-only change: no owner change confirmation
        $this->assertSame(['contacts' => ['admin' => 'ADMIN-NEW']], $this->client->requests[4]['body']);
    }

    public function testSaveContactDetailsOwnerTypeChangeNeedsConfirmation(): void
    {
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1']]));
        $this->client->queueResponse($this->apiContact('OWNER-1'));
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-ORG']));
        $this->client->queueResponse($this->err('Owner change requires confirmation', 409, ['ownerChange' => [
            'required' => true, 'method' => 'update', 'netPrice' => 0, 'price' => 0, 'currency' => 'EUR', 'vatPercent' => 19, 'confirmField' => 'confirmOwnerChange',
        ]]));

        $result = domainresellerapi_SaveContactDetails($this->params(['contactdetails' => [
            'Registrant' => $this->details(['Company Name' => 'ACME GmbH']),
        ]]));

        $this->assertStringContainsString('owner change', $result['error']);
        $this->assertSame('organization', $this->client->requests[2]['body']['type']);
        $this->assertSame(['contacts' => ['owner' => 'OWNER-ORG']], $this->client->requests[3]['body']);
    }

    public function testSaveContactDetailsOwnerTypeChangeConfirmed(): void
    {
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1']]));
        $this->client->queueResponse($this->apiContact('OWNER-1'));
        $this->client->queueResponse($this->ok(['handle' => 'OWNER-ORG']));
        $this->client->queueResponse($this->ok(['ownerChanged' => true]));

        $result = domainresellerapi_SaveContactDetails($this->params([
            'ConfirmOwnerChange' => 'on',
            'contactdetails' => ['Registrant' => $this->details(['Company Name' => 'ACME GmbH'])],
        ]));

        $this->assertSame(['success' => true], $result);
        $this->assertSame(['contacts' => ['owner' => 'OWNER-ORG'], 'confirmOwnerChange' => true], $this->client->requests[3]['body']);
    }

    public function testSaveContactDetailsInvalidData(): void
    {
        $this->client->queueResponse($this->ok(['contacts' => ['owner' => 'OWNER-1']]));

        $result = domainresellerapi_SaveContactDetails($this->params(['contactdetails' => [
            'Registrant' => $this->details(['Phone Number' => '', 'Country' => 'Germany']),
        ]]));

        $this->assertStringContainsString('Registrant', $result['error']);
        $this->assertCount(1, $this->client->requests);
    }

    // ─── Availability ───

    public function testCheckAvailabilityUsesBulkCheck(): void
    {
        $this->client->queueResponse($this->ok(['results' => [
            ['domain' => 'example.de', 'available' => true, 'premium' => false, 'price' => 5.0, 'years' => 1],
            ['domain' => 'example.com', 'available' => false, 'premium' => false],
            ['domain' => 'example.ai', 'available' => true, 'premium' => true, 'price' => 200.0, 'renewPrice' => 200.0, 'years' => 2, 'setupPrice' => null, 'currency' => 'EUR'],
            ['domain' => 'example.xyz', 'error' => 'TLD not supported'],
        ]]));

        $results = domainresellerapi_CheckAvailability($this->params([
            'searchTerm' => 'example',
            'tldsToInclude' => ['.de', '.com', '.ai', '.xyz'],
            'premiumEnabled' => true,
        ]));

        $this->assertInstanceOf(LookupResultsList::class, $results);
        $this->assertSame([['POST', '/api/v1/domains/check']], $this->calls());
        $this->assertSame(['domains' => ['example.de', 'example.com', 'example.ai', 'example.xyz']], $this->client->requests[0]['body']);

        $this->assertCount(4, $results);
        $this->assertSame(SearchResult::STATUS_NOT_REGISTERED, $results[0]->getStatus());
        $this->assertSame('de', $results[0]->getTopLevel());
        $this->assertSame(SearchResult::STATUS_REGISTERED, $results[1]->getStatus());
        $this->assertTrue($results[2]->isPremium());
        // price is the total for `years`; WHMCS gets the price per year
        $this->assertSame(['register' => 100.0, 'renew' => 100.0, 'CurrencyCode' => 'EUR'], $results[2]->getPremiumCostPricing());
        $this->assertSame(SearchResult::STATUS_UNKNOWN, $results[3]->getStatus());
    }

    public function testCheckAvailabilityPremiumDisabled(): void
    {
        $this->client->queueResponse($this->ok(['results' => [
            ['domain' => 'example.ai', 'available' => true, 'premium' => true, 'price' => 200.0, 'years' => 2],
        ]]));

        $results = domainresellerapi_CheckAvailability($this->params(['searchTerm' => 'example', 'tldsToInclude' => ['.ai']]));

        $this->assertSame(SearchResult::STATUS_RESERVED, $results[0]->getStatus());
    }

    public function testCheckAvailabilityApiError(): void
    {
        $this->client->queueResponse($this->err('Rate limit exceeded. Maximum 500 domain checks per minute.', 429));

        $results = domainresellerapi_CheckAvailability($this->params(['searchTerm' => 'example', 'tldsToInclude' => ['.de']]));

        $this->assertCount(1, $results);
        $this->assertSame(SearchResult::STATUS_UNKNOWN, $results[0]->getStatus());
    }

    public function testGetDomainSuggestions(): void
    {
        $this->client->queueResponse($this->ok(['suggestions' => [
            ['domain' => 'example.de', 'zone' => 'de'],
            ['domain' => 'example.shop', 'zone' => 'shop'],
        ]]));

        $results = domainresellerapi_GetDomainSuggestions($this->params(['searchTerm' => 'example', 'tldsToInclude' => ['.de', '.com']]));

        $request = $this->client->requests[0];
        $this->assertSame('/api/v1/domains/suggest', $request['endpoint']);
        $this->assertSame(['q' => 'example', 'defaults' => 'de,com'], $request['params']);
        // Only TLDs offered in WHMCS
        $this->assertCount(1, $results);
        $this->assertSame('example', $results[0]->getSecondLevel());
        $this->assertSame('de', $results[0]->getTopLevel());
    }

    // ─── TLD pricing ───

    public function testGetTldPricing(): void
    {
        $this->client->queueResponse([
            'success' => true,
            'currency' => 'EUR',
            'httpCode' => 200,
            'data' => [
                ['tld' => 'de', 'registerPrice' => '4.50', 'renewPrice' => '4.50', 'transferPrice' => '4.50', 'transferYears' => 1,
                    'restorePrice' => '25.00', 'setupPrice' => '1.00', 'minYears' => 1, 'maxYears' => 10,
                    'periods' => [['years' => 1, 'registerPrice' => '4.50', 'renewPrice' => '4.50']]],
                ['tld' => 'ai', 'registerPrice' => '70.00', 'renewPrice' => '70.00', 'transferPrice' => '140.00', 'transferYears' => 2,
                    'restorePrice' => null, 'setupPrice' => null, 'minYears' => 2, 'maxYears' => 10,
                    'periods' => [['years' => 2, 'registerPrice' => '139.90', 'renewPrice' => '139.80'], ['years' => 3, 'registerPrice' => '200.00', 'renewPrice' => '200.00']]],
                // Without periods: per-year price × minimum term
                ['tld' => 'tm', 'registerPrice' => '30.00', 'renewPrice' => '31.00', 'transferPrice' => '30.00', 'transferYears' => 1,
                    'restorePrice' => null, 'setupPrice' => null, 'minYears' => 2, 'maxYears' => 10],
                ['tld' => '', 'registerPrice' => '1.00'],
            ],
        ]);

        $results = domainresellerapi_GetTldPricing($this->params());

        $this->assertInstanceOf(ResultsList::class, $results);
        $this->assertSame([['GET', '/api/v1/pricing/']], $this->calls());
        $this->assertCount(3, $results);

        /** @var ImportItem $de */
        $de = $results[0];
        $this->assertSame('de', $de->getExtension());
        $this->assertSame(5.5, $de->getRegisterPrice()); // incl. setup fee
        $this->assertSame(4.5, $de->getRenewPrice());
        $this->assertSame(5.5, $de->getTransferPrice());
        $this->assertSame(25.0, $de->getRedemptionFeePrice());
        // .de transfers need the AuthInfo code, too
        $this->assertTrue($de->getEppRequired());

        /** @var ImportItem $ai */
        $ai = $results[1];
        // WHMCS expects the cost price for the minimum term (2 years), not per year
        $this->assertSame(2, $ai->getMinYears());
        $this->assertSame(139.9, $ai->getRegisterPrice());
        $this->assertSame(139.8, $ai->getRenewPrice());
        $this->assertSame(140.0, $ai->getTransferPrice());

        /** @var ImportItem $tm */
        $tm = $results[2];
        $this->assertSame(60.0, $tm->getRegisterPrice());
        $this->assertSame(62.0, $tm->getRenewPrice());
        $this->assertSame(30.0, $tm->getTransferPrice());
    }

    // ─── Sync ───

    /**
     * @dataProvider syncProvider
     */
    public function testSyncStatusMapping(string $status, array $expected): void
    {
        $this->client->queueResponse($this->ok(['status' => $status, 'autoRenew' => false, 'expiresAt' => '2027-03-15T12:00:00.000Z']));

        $values = domainresellerapi_Sync($this->params());

        $this->assertSame('2027-03-15', $values['expirydate']);
        unset($values['expirydate']);
        $this->assertSame($expected, $values);
    }

    public static function syncProvider(): array
    {
        $active = ['active' => true, 'expired' => false, 'cancelled' => false, 'transferredAway' => false];
        return [
            'active' => ['active', $active],
            'pending_delete' => ['pending_delete', $active],
            'suspended' => ['suspended', $active],
            'expired' => ['expired', ['active' => false, 'expired' => true]],
            'redemption' => ['redemption', ['active' => false, 'expired' => true]],
            'deleted' => ['deleted', ['active' => false, 'cancelled' => true]],
            'transferred_away' => ['transferred_away', ['active' => false, 'transferredAway' => true]],
            'pending' => ['pending', []],
            'pending_transfer' => ['pending_transfer', []],
        ];
    }

    public function testSyncDisablesApiAutoRenew(): void
    {
        $this->client->queueResponse($this->ok(['status' => 'active', 'autoRenew' => true, 'expiresAt' => '2027-03-15T00:00:00.000Z']));

        domainresellerapi_Sync($this->params());

        $this->assertSame(['autoRenew' => false], $this->request('PATCH', '/api/v1/domains/example.de')['body']);
    }

    public function testSyncError(): void
    {
        $this->client->queueResponse($this->err('Domain not found', 404));

        $this->assertSame(['error' => 'Domain not found'], domainresellerapi_Sync($this->params()));
    }

    // ─── TransferSync ───

    public function testTransferSyncCompleted(): void
    {
        $this->client->queueResponse($this->ok(['status' => 'active', 'autoRenew' => true, 'expiresAt' => '2027-06-01T00:00:00.000Z']));

        $values = domainresellerapi_TransferSync($this->params());

        $this->assertSame(['completed' => true, 'expirydate' => '2027-06-01'], $values);
        $this->assertSame(['autoRenew' => false], $this->request('PATCH', '/api/v1/domains/example.de')['body']);
    }

    public function testTransferSyncInProgress(): void
    {
        $this->client->queueResponse($this->ok(['status' => 'pending_transfer']));

        $this->assertSame([], domainresellerapi_TransferSync($this->params()));
    }

    public function testTransferSyncFailed(): void
    {
        // Failed and cancelled transfers end in the state "deleted"
        $this->client->queueResponse($this->ok(['status' => 'deleted']));

        $this->assertTrue(domainresellerapi_TransferSync($this->params())['failed']);
    }

    // ─── Deletion & admin actions ───

    public function testRequestDelete(): void
    {
        $this->client->queueResponse(['success' => true, 'message' => 'Domain will be cancelled at the end of the current term', 'httpCode' => 200]);

        $this->assertSame(['success' => true], domainresellerapi_RequestDelete($this->params()));
        $this->assertSame([['DELETE', '/api/v1/domains/example.de']], $this->calls());
        $this->assertSame([], $this->client->requests[0]['params']);
    }

    public function testAdminCustomButtonArray(): void
    {
        $this->assertSame([
            'Restore Domain' => 'RestoreDomain',
            'Cancel Deletion' => 'CancelDeletion',
            'Reset Auth Code' => 'ResetAuthCode',
            'Enable Managed DNS' => 'EnableManagedDns',
            'Disable Managed DNS' => 'DisableManagedDns',
        ], domainresellerapi_AdminCustomButtonArray());
    }

    /**
     * @dataProvider adminActionProvider
     */
    public function testAdminActions(string $function, string $method, string $endpoint): void
    {
        $this->assertSame(['success' => true], $function($this->params()));
        $this->assertSame([[$method, $endpoint]], $this->calls());

        $this->client->requests = [];
        $this->client->queueResponse($this->err('Domain cannot be modified in current state', 422));
        $this->assertSame(['error' => 'Domain cannot be modified in current state'], $function($this->params()));
    }

    public static function adminActionProvider(): array
    {
        return [
            ['domainresellerapi_RestoreDomain', 'POST', '/api/v1/domains/example.de/restore'],
            ['domainresellerapi_CancelDeletion', 'POST', '/api/v1/domains/example.de/cancel-delete'],
            ['domainresellerapi_ResetAuthCode', 'POST', '/api/v1/domains/example.de/reset-authcode'],
            ['domainresellerapi_EnableManagedDns', 'POST', '/api/v1/dns/example.de/enable'],
            ['domainresellerapi_DisableManagedDns', 'POST', '/api/v1/dns/example.de/disable'],
        ];
    }
}

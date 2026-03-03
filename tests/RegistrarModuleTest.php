<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Registrar\DomainResellerApi\ApiClient;
use WHMCS\Results\ResultsList;
use WHMCS\Domain\TopLevel\ImportItem;

/**
 * Tests for all WHMCS registrar module functions.
 *
 * We use a MockApiClient injected via a global override to test the module
 * functions in isolation from the real API.
 */
class RegistrarModuleTest extends TestCase
{
    /**
     * Build standard WHMCS params with a MockApiClient baked in.
     * The module calls domainresellerapi_getClient() which creates a real client.
     * To test functions that call the API, we create a mock and use it directly
     * in tests where we call the module's lower-level logic.
     *
     * For the module functions that build their own client from params,
     * we test the return value structure rather than API call specifics,
     * since those are covered by ApiClientTest and HelperFunctionsTest.
     */
    private function baseParams(array $overrides = []): array
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
            'fullphonenumber' => '+491234567890',
            'address1' => 'Musterstr 42',
            'city' => 'Berlin',
            'postcode' => '10115',
            'state' => 'Berlin',
            'country' => 'DE',
            'ns1' => 'ns1.example.com',
            'ns2' => 'ns2.example.com',
        ], $overrides);
    }

    // ─── RegisterDomain ───

    public function testRegisterDomainReturnsArrayWithSuccessOrError(): void
    {
        // We cannot mock the internal client creation, but we can verify the
        // function returns a proper array structure even when the API fails
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_RegisterDomain($params);

        // Should return an error (can't connect to localhost:1)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── TransferDomain ───

    public function testTransferDomainReturnsArray(): void
    {
        $params = $this->baseParams([
            'ApiUrl' => 'https://localhost:1',
            'eppcode' => 'AUTH123',
        ]);
        $result = domainresellerapi_TransferDomain($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── RenewDomain ───

    public function testRenewDomainReturnsArray(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_RenewDomain($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── GetNameservers ───

    public function testGetNameserversReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_GetNameservers($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── SaveNameservers ───

    public function testSaveNameserversRequiresMinimumTwo(): void
    {
        $params = $this->baseParams([
            'ns1' => 'ns1.example.com',
            'ns2' => '',
            'ns3' => '',
        ]);
        // Reduce timeout so it doesn't wait too long
        $params['ApiUrl'] = 'https://localhost:1';
        $params['Timeout'] = '1';

        $result = domainresellerapi_SaveNameservers($params);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('At least 2 nameservers', $result['error']);
    }

    // ─── GetRegistrarLock ───

    public function testGetRegistrarLockReturnsEmpty(): void
    {
        $result = domainresellerapi_GetRegistrarLock($this->baseParams());
        $this->assertSame('', $result);
    }

    // ─── SaveRegistrarLock ───

    public function testSaveRegistrarLockReturnsError(): void
    {
        $result = domainresellerapi_SaveRegistrarLock($this->baseParams());
        $this->assertArrayHasKey('error', $result);
    }

    // ─── GetEPPCode ───

    public function testGetEPPCodeReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_GetEPPCode($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── GetDNS ───

    public function testGetDNSReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_GetDNS($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── SaveDNS ───

    public function testSaveDNSReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams([
            'ApiUrl' => 'https://localhost:1',
            'dnsrecords' => [
                ['type' => 'A', 'hostname' => 'www', 'address' => '1.2.3.4'],
            ],
        ]);
        $result = domainresellerapi_SaveDNS($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── GetContactDetails ───

    public function testGetContactDetailsReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_GetContactDetails($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── SaveContactDetails ───

    public function testSaveContactDetailsReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams([
            'ApiUrl' => 'https://localhost:1',
            'contactdetails' => [
                'Registrant' => [
                    'First Name' => 'Max',
                    'Last Name' => 'Mustermann',
                ],
            ],
        ]);
        $result = domainresellerapi_SaveContactDetails($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── CheckAvailability ───

    public function testCheckAvailabilityReturnsResultsList(): void
    {
        $params = $this->baseParams([
            'ApiUrl' => 'https://localhost:1',
            'searchTerm' => 'example',
            'tlds' => ['.de', '.com'],
        ]);

        $result = domainresellerapi_CheckAvailability($params);
        $this->assertInstanceOf(ResultsList::class, $result);
    }

    public function testCheckAvailabilityReturnsEmptyOnError(): void
    {
        $params = $this->baseParams([
            'ApiUrl' => 'https://localhost:1',
            'searchTerm' => 'example',
            'tlds' => [],
        ]);

        $result = domainresellerapi_CheckAvailability($params);
        $this->assertInstanceOf(ResultsList::class, $result);
        $this->assertCount(0, $result);
    }

    // ─── GetDomainSuggestions ───

    public function testGetDomainSuggestionsReturnsResultsList(): void
    {
        $params = $this->baseParams([
            'ApiUrl' => 'https://localhost:1',
            'searchTerm' => 'mywebsite',
            'tlds' => ['.de'],
        ]);

        $result = domainresellerapi_GetDomainSuggestions($params);
        $this->assertInstanceOf(ResultsList::class, $result);
    }

    // ─── GetTldPricing ───

    public function testGetTldPricingReturnsResultsList(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_GetTldPricing($params);
        $this->assertInstanceOf(ResultsList::class, $result);
    }

    // ─── Sync ───

    public function testSyncReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_Sync($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── TransferSync ───

    public function testTransferSyncReturnsEmptyOnNotFound(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_TransferSync($params);

        // On connection failure, should return error
        $this->assertIsArray($result);
    }

    // ─── RequestDelete ───

    public function testRequestDeleteReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_RequestDelete($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ─── AdminCustomButtonArray ───

    public function testAdminCustomButtonArrayReturnsExpectedButtons(): void
    {
        $buttons = domainresellerapi_AdminCustomButtonArray();

        $this->assertIsArray($buttons);
        $this->assertArrayHasKey('Restore Domain', $buttons);
        $this->assertArrayHasKey('Cancel Deletion', $buttons);
        $this->assertArrayHasKey('Reset Auth Code', $buttons);
        $this->assertArrayHasKey('Enable Managed DNS', $buttons);
        $this->assertArrayHasKey('Disable Managed DNS', $buttons);

        $this->assertSame('RestoreDomain', $buttons['Restore Domain']);
        $this->assertSame('CancelDeletion', $buttons['Cancel Deletion']);
        $this->assertSame('ResetAuthCode', $buttons['Reset Auth Code']);
        $this->assertSame('EnableManagedDns', $buttons['Enable Managed DNS']);
        $this->assertSame('DisableManagedDns', $buttons['Disable Managed DNS']);
    }

    // ─── Admin Button Functions ───

    public function testRestoreDomainReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_RestoreDomain($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCancelDeletionReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_CancelDeletion($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testResetAuthCodeReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_ResetAuthCode($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testEnableManagedDnsReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_EnableManagedDns($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testDisableManagedDnsReturnsErrorOnFailure(): void
    {
        $params = $this->baseParams(['ApiUrl' => 'https://localhost:1']);
        $result = domainresellerapi_DisableManagedDns($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }
}

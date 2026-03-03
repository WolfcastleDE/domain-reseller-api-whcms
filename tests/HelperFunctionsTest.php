<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

class HelperFunctionsTest extends TestCase
{
    // ─── domainresellerapi_getDomain ───

    public function testGetDomainCombinesSldAndTld(): void
    {
        $result = domainresellerapi_getDomain(['sld' => 'example', 'tld' => 'de']);
        $this->assertSame('example.de', $result);
    }

    public function testGetDomainWithSubTld(): void
    {
        $result = domainresellerapi_getDomain(['sld' => 'example', 'tld' => 'co.uk']);
        $this->assertSame('example.co.uk', $result);
    }

    // ─── domainresellerapi_collectNameservers ───

    public function testGetNameserversCollectsFromParams(): void
    {
        $params = [
            'ns1' => 'ns1.example.com',
            'ns2' => 'ns2.example.com',
            'ns3' => '',
            'ns4' => 'ns4.example.com',
        ];

        $result = domainresellerapi_collectNameservers($params);
        $this->assertSame(['ns1.example.com', 'ns2.example.com', 'ns4.example.com'], $result);
    }

    public function testGetNameserversReturnsEmptyWhenNoneProvided(): void
    {
        $result = domainresellerapi_collectNameservers([]);
        $this->assertSame([], $result);
    }

    public function testGetNameserversMaxFive(): void
    {
        $params = [];
        for ($i = 1; $i <= 7; $i++) {
            $params['ns' . $i] = "ns{$i}.example.com";
        }

        $result = domainresellerapi_collectNameservers($params);
        // Only ns1-ns5 are checked
        $this->assertCount(5, $result);
        $this->assertSame('ns5.example.com', $result[4]);
    }

    // ─── domainresellerapi_formatPhone ───

    public function testFormatPhoneEmptyReturnsDefault(): void
    {
        $this->assertSame('+0.0', domainresellerapi_formatPhone(''));
    }

    public function testFormatPhoneWhitespaceOnlyReturnsDefault(): void
    {
        $this->assertSame('+0.0', domainresellerapi_formatPhone('   '));
    }

    public function testFormatPhoneAlreadyInternational(): void
    {
        $this->assertSame('+491234567', domainresellerapi_formatPhone('+491234567'));
    }

    public function testFormatPhoneAlreadyInternationalIgnoresCountry(): void
    {
        $this->assertSame('+441234567', domainresellerapi_formatPhone('+441234567', 'DE'));
    }

    public function testFormatPhoneGermanNumber(): void
    {
        $result = domainresellerapi_formatPhone('01234567890', 'DE');
        $this->assertSame('+491234567890', $result);
    }

    public function testFormatPhoneAustrianNumber(): void
    {
        $result = domainresellerapi_formatPhone('0123456', 'AT');
        $this->assertSame('+43123456', $result);
    }

    public function testFormatPhoneUSNumber(): void
    {
        $result = domainresellerapi_formatPhone('5551234567', 'US');
        $this->assertSame('+15551234567', $result);
    }

    public function testFormatPhoneUnknownCountryDefaultsToZero(): void
    {
        $result = domainresellerapi_formatPhone('01234567', 'XX');
        $this->assertSame('+01234567', $result);
    }

    public function testFormatPhoneNoCountryDefaultsToZero(): void
    {
        $result = domainresellerapi_formatPhone('01234567');
        $this->assertSame('+01234567', $result);
    }

    public function testFormatPhoneLowercaseCountry(): void
    {
        $result = domainresellerapi_formatPhone('01234567', 'de');
        $this->assertSame('+491234567', $result);
    }

    public function testFormatPhoneNoLeadingZero(): void
    {
        $result = domainresellerapi_formatPhone('1234567', 'DE');
        $this->assertSame('+491234567', $result);
    }

    public function testFormatPhoneAllSupportedCountries(): void
    {
        $expected = [
            'DE' => '+49', 'AT' => '+43', 'CH' => '+41', 'US' => '+1', 'GB' => '+44',
            'FR' => '+33', 'NL' => '+31', 'BE' => '+32', 'IT' => '+39', 'ES' => '+34',
            'PL' => '+48', 'CZ' => '+420', 'DK' => '+45', 'SE' => '+46', 'NO' => '+47',
            'FI' => '+358', 'PT' => '+351', 'IE' => '+353', 'LU' => '+352',
        ];

        foreach ($expected as $country => $prefix) {
            $result = domainresellerapi_formatPhone('123', $country);
            $this->assertSame($prefix . '123', $result, "Country {$country} should use prefix {$prefix}");
        }
    }

    // ─── domainresellerapi_getOrCreateContact ───

    public function testGetOrCreateContactSuccess(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse([
            'success' => true,
            'data' => ['handle' => 'CONTACT-999'],
        ]);

        $params = [
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'companyname' => '',
            'email' => 'max@example.de',
            'phonenumber' => '01234567',
            'fullphonenumber' => '+491234567',
            'address1' => 'Musterstraße 42',
            'address2' => 'Apt 3',
            'city' => 'Berlin',
            'postcode' => '10115',
            'state' => 'Berlin',
            'country' => 'DE',
        ];

        $handle = domainresellerapi_getOrCreateContact($client, $params);
        $this->assertSame('CONTACT-999', $handle);

        // Verify the contact data sent to the API
        $req = $client->getLastRequest();
        $this->assertSame('person', $req['body']['type']);
        $this->assertSame('Max', $req['body']['firstName']);
        $this->assertSame('Mustermann', $req['body']['lastName']);
        $this->assertSame('Musterstraße', $req['body']['street']);
        $this->assertSame('42', $req['body']['houseNumber']);
        $this->assertSame('Apt 3', $req['body']['extension']);
    }

    public function testGetOrCreateContactOrganization(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse([
            'success' => true,
            'data' => ['handle' => 'ORG-1'],
        ]);

        $params = [
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'companyname' => 'ACME GmbH',
            'email' => 'info@acme.de',
            'phonenumber' => '030123456',
            'address1' => 'Hauptstraße 1',
            'city' => 'München',
            'postcode' => '80331',
            'state' => 'Bayern',
            'country' => 'DE',
        ];

        $handle = domainresellerapi_getOrCreateContact($client, $params);
        $this->assertSame('ORG-1', $handle);

        $req = $client->getLastRequest();
        $this->assertSame('org', $req['body']['type']);
        $this->assertSame('ACME GmbH', $req['body']['organization']);
    }

    public function testGetOrCreateContactWithPrefix(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse([
            'success' => true,
            'data' => ['handle' => 'ADMIN-1'],
        ]);

        $params = [
            'adminfirstname' => 'Admin',
            'adminlastname' => 'User',
            'admincompanyname' => '',
            'adminemail' => 'admin@example.de',
            'adminphonenumber' => '0555123',
            'adminaddress1' => 'Adminstr 10',
            'admincity' => 'Hamburg',
            'adminpostcode' => '20095',
            'adminstate' => 'Hamburg',
            'admincountry' => 'DE',
        ];

        $handle = domainresellerapi_getOrCreateContact($client, $params, 'admin');
        $this->assertSame('ADMIN-1', $handle);

        $req = $client->getLastRequest();
        $this->assertSame('Admin', $req['body']['firstName']);
        $this->assertSame('Hamburg', $req['body']['city']);
    }

    public function testGetOrCreateContactThrowsOnFailure(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse([
            'success' => false,
            'error' => 'Validation failed',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to create contact: Validation failed');

        domainresellerapi_getOrCreateContact($client, [
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'test@test.de',
            'phonenumber' => '0123',
            'address1' => 'Street 1',
            'city' => 'City',
            'postcode' => '12345',
            'country' => 'DE',
        ]);
    }

    public function testGetOrCreateContactStreetWithoutHouseNumber(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse([
            'success' => true,
            'data' => ['handle' => 'H-1'],
        ]);

        $params = [
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'test@test.de',
            'phonenumber' => '0123',
            'address1' => 'OnlyStreetName',
            'city' => 'City',
            'postcode' => '12345',
            'country' => 'DE',
        ];

        domainresellerapi_getOrCreateContact($client, $params);

        $req = $client->getLastRequest();
        $this->assertSame('OnlyStreetName', $req['body']['street']);
        $this->assertSame('', $req['body']['houseNumber']);
    }

    // ─── domainresellerapi_getClient ───

    public function testGetClientReturnsApiClient(): void
    {
        $params = [
            'ApiKey' => 'drapi_test_xxx',
            'ApiUrl' => 'https://example.com',
            'Sandbox' => 'on',
            'Timeout' => '60',
        ];

        $client = domainresellerapi_getClient($params);
        $this->assertInstanceOf(\WHMCS\Module\Registrar\DomainResellerApi\ApiClient::class, $client);
    }

    // ─── domainresellerapi_MetaData ───

    public function testMetaDataReturnsExpectedKeys(): void
    {
        $meta = domainresellerapi_MetaData();
        $this->assertSame('Domain Reseller API', $meta['DisplayName']);
        $this->assertSame('1.1', $meta['APIVersion']);
    }

    // ─── domainresellerapi_getConfigArray ───

    public function testGetConfigArrayHasAllRequiredFields(): void
    {
        $config = domainresellerapi_getConfigArray();

        $this->assertArrayHasKey('FriendlyName', $config);
        $this->assertArrayHasKey('Description', $config);
        $this->assertArrayHasKey('ApiKey', $config);
        $this->assertArrayHasKey('ApiUrl', $config);
        $this->assertArrayHasKey('Sandbox', $config);
        $this->assertArrayHasKey('Timeout', $config);

        $this->assertSame('password', $config['ApiKey']['Type']);
        $this->assertSame('yesno', $config['Sandbox']['Type']);
    }
}

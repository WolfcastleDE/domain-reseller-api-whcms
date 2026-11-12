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

    public function testGetDomainLowercasesAndStripsDot(): void
    {
        $this->assertSame('example.de', domainresellerapi_getDomain(['sld' => 'Example', 'tld' => '.DE']));
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

    /** Phone pattern of POST /api/v1/contacts (routes/contacts.ts) */
    private const API_PHONE_PATTERN = '/^\+\d+\.\d+$/';

    public function testFormatPhoneEmptyReturnsNull(): void
    {
        $this->assertNull(domainresellerapi_formatPhone(''));
        $this->assertNull(domainresellerapi_formatPhone('   '));
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testFormatPhone(string $input, string $country, string $expected): void
    {
        $result = domainresellerapi_formatPhone($input, $country);
        $this->assertSame($expected, $result);
        $this->assertMatchesRegularExpression(self::API_PHONE_PATTERN, $result);
    }

    public static function phoneProvider(): array
    {
        return [
            'WHMCS fullphonenumber' => ['+49.301234567', 'DE', '+49.301234567'],
            'international with spaces' => ['+49 30 1234567', 'DE', '+49.301234567'],
            'international without separator' => ['+491234567890', 'DE', '+49.1234567890'],
            'trunk prefix in brackets' => ['+49 (0)30 1234567', 'DE', '+49.301234567'],
            '00 prefix' => ['0049 30 1234567', 'DE', '+49.301234567'],
            'national German' => ['030 1234567', 'DE', '+49.301234567'],
            'national Austrian' => ['0664/1234567', 'at', '+43.6641234567'],
            'US' => ['(555) 123-4567', 'US', '+1.5551234567'],
            'Italy keeps trunk prefix' => ['06 1234567', 'IT', '+39.061234567'],
            'foreign number of a German contact' => ['+44 20 7946 0958', 'DE', '+44.2079460958'],
        ];
    }

    public function testFormatPhoneUnknownCountryReturnsNull(): void
    {
        $this->assertNull(domainresellerapi_formatPhone('01234567', 'XX'));
        $this->assertNull(domainresellerapi_formatPhone('01234567'));
    }

    // ─── domainresellerapi_splitStreet ───

    /**
     * @dataProvider streetProvider
     */
    public function testSplitStreet(string $input, string $street, string $number): void
    {
        $this->assertSame([$street, $number], domainresellerapi_splitStreet($input));
    }

    public static function streetProvider(): array
    {
        return [
            ['Musterstraße 42', 'Musterstraße', '42'],
            ['Musterstr. 12a', 'Musterstr.', '12a'],
            ['Am Markt 3 b', 'Am Markt', '3b'],
            ['Hauptstraße 10-12', 'Hauptstraße', '10-12'],
            ['Straße des 17. Juni 135', 'Straße des 17. Juni', '135'],
            ['123 Main Street', 'Main Street', '123'],
            ['OnlyStreetName', 'OnlyStreetName', ''],
            ['', '', ''],
        ];
    }

    // ─── domainresellerapi_buildContactPayload / getOrCreateContact ───

    private function personParams(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'companyname' => '',
            'email' => 'max@example.de',
            'phonenumber' => '01234567',
            'fullphonenumber' => '+49.1234567',
            'address1' => 'Musterstraße 42',
            'address2' => 'Apt 3',
            'city' => 'Berlin',
            'postcode' => '10115',
            'state' => 'Berlin',
            'country' => 'DE',
        ], $overrides);
    }

    /**
     * Assert a payload matches the body schema of POST /api/v1/contacts.
     */
    private function assertValidContactPayload(array $body): void
    {
        $this->assertContains($body['type'], ['person', 'organization', 'role']);
        $this->assertContains($body['sex'], ['MALE', 'FEMALE', 'NA']);
        foreach (['firstName', 'lastName', 'street', 'postalCode', 'city'] as $field) {
            $this->assertNotSame('', $body[$field] ?? '', $field);
        }
        $this->assertMatchesRegularExpression('/^[A-Za-z]{2}$/', $body['country']);
        $this->assertMatchesRegularExpression(self::API_PHONE_PATTERN, $body['phone']);
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z._-]{1,64}@[0-9a-zA-Z._-]{3,64}$/', $body['email']);
        $allowed = ['type', 'sex', 'firstName', 'lastName', 'organization', 'street', 'houseNumber', 'postalCode',
            'city', 'country', 'phone', 'fax', 'email', 'middleName', 'state', 'extension', 'protection', 'remarks',
            'birthdate', 'birthplace', 'birthPostalCode', 'birthCountry', 'siret', 'xxxMembership', 'pin'];
        $this->assertSame([], array_diff(array_keys($body), $allowed), 'unknown contact fields');
    }

    public function testGetOrCreateContactPerson(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse(['success' => true, 'data' => ['handle' => 'CONTACT-999']]);

        $handle = domainresellerapi_getOrCreateContact($client, $this->personParams());
        $this->assertSame('CONTACT-999', $handle);

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/api/v1/contacts/', $req['endpoint']);
        $body = $req['body'];
        $this->assertValidContactPayload($body);
        $this->assertSame('person', $body['type']);
        $this->assertSame('NA', $body['sex']);
        $this->assertSame('Max', $body['firstName']);
        $this->assertSame('Mustermann', $body['lastName']);
        $this->assertSame('Musterstraße', $body['street']);
        $this->assertSame('42', $body['houseNumber']);
        $this->assertSame('Apt 3', $body['extension']);
        $this->assertSame('Berlin', $body['state']);
        $this->assertSame('+49.1234567', $body['phone']);
        // Natural persons have no organisation
        $this->assertArrayNotHasKey('organization', $body);
    }

    public function testGetOrCreateContactOrganization(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse(['success' => true, 'data' => ['handle' => 'ORG-1']]);

        $params = $this->personParams([
            'companyname' => 'ACME GmbH',
            'fullphonenumber' => '',
            'phonenumber' => '030123456',
            'country' => 'de',
        ]);

        $this->assertSame('ORG-1', domainresellerapi_getOrCreateContact($client, $params));

        $body = $client->getLastRequest()['body'];
        $this->assertValidContactPayload($body);
        $this->assertSame('organization', $body['type']);
        $this->assertNotSame('org', $body['type']);
        $this->assertSame('ACME GmbH', $body['organization']);
        $this->assertSame('DE', $body['country']);
        $this->assertSame('+49.30123456', $body['phone']);
    }

    public function testGetOrCreateContactWithPrefix(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse(['success' => true, 'data' => ['handle' => 'ADMIN-1']]);

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

        $this->assertSame('ADMIN-1', domainresellerapi_getOrCreateContact($client, $params, 'admin'));

        $body = $client->getLastRequest()['body'];
        $this->assertValidContactPayload($body);
        $this->assertSame('Admin', $body['firstName']);
        $this->assertSame('Hamburg', $body['city']);
        $this->assertSame('+49.555123', $body['phone']);
    }

    public function testGetOrCreateContactExtraFields(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse(['success' => true, 'data' => ['handle' => 'IT-1']]);

        domainresellerapi_getOrCreateContact($client, $this->personParams(), '', ['pin' => 'RSSMRA80A01H501U', 'protection' => true]);

        $body = $client->getLastRequest()['body'];
        $this->assertValidContactPayload($body);
        $this->assertSame('RSSMRA80A01H501U', $body['pin']);
        $this->assertTrue($body['protection']);
    }

    public function testGetOrCreateContactThrowsOnApiFailure(): void
    {
        $client = new MockApiClient('test-key');
        $client->queueResponse(['success' => false, 'error' => 'Validation error: Expected union value']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to create contact: Validation error: Expected union value');

        domainresellerapi_getOrCreateContact($client, $this->personParams());
    }

    public function testInvalidContactIsRejectedBeforeTheApiCall(): void
    {
        $client = new MockApiClient('test-key');

        try {
            domainresellerapi_getOrCreateContact($client, $this->personParams([
                'fullphonenumber' => '',
                'phonenumber' => '',
                'email' => 'max+tag@example.de',
                'city' => '',
            ]));
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('phone', $e->getMessage());
            $this->assertStringContainsString('email', $e->getMessage());
            $this->assertStringContainsString('city', $e->getMessage());
        }
        $this->assertSame([], $client->requests);
    }

    public function testStreetWithoutHouseNumberOmitsField(): void
    {
        $body = domainresellerapi_buildContactPayload(domainresellerapi_contactFromParams($this->personParams(['address1' => 'OnlyStreetName', 'address2' => '', 'state' => ''])));

        $this->assertValidContactPayload($body);
        $this->assertSame('OnlyStreetName', $body['street']);
        // houseNumber is optional; an empty value is not sent
        $this->assertArrayNotHasKey('houseNumber', $body);
        $this->assertArrayNotHasKey('extension', $body);
        $this->assertArrayNotHasKey('state', $body);
    }

    public function testOrganizationWithoutNamesUsesCompany(): void
    {
        $body = domainresellerapi_buildContactPayload(domainresellerapi_contactFromParams($this->personParams([
            'firstname' => '', 'lastname' => '', 'companyname' => 'ACME GmbH',
        ])));

        $this->assertSame('ACME GmbH', $body['firstName']);
        $this->assertSame('ACME GmbH', $body['lastName']);
    }

    public function testOwnerExtraForIt(): void
    {
        $extra = domainresellerapi_ownerExtra(['tld' => 'it', 'idprotection' => true, 'additionalfields' => ['Tax ID' => ' RSSMRA80A01H501U ']]);
        $this->assertSame(['protection' => true, 'pin' => 'RSSMRA80A01H501U'], $extra);

        // pin is only sent for .it
        $this->assertSame([], domainresellerapi_ownerExtra(['tld' => 'de', 'additionalfields' => ['Tax ID' => 'X']]));
    }

    // ─── domainresellerapi_normalizeNameservers ───

    public function testNormalizeNameservers(): void
    {
        $this->assertSame(
            ['ns1.example.com', 'ns2.example.com'],
            domainresellerapi_normalizeNameservers([' NS1.Example.com. ', '', 'ns2.example.com', 'ns1.example.com'])
        );
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
        $this->assertArrayHasKey('ApiAutoRenew', $config);
        $this->assertArrayHasKey('ConfirmOwnerChange', $config);

        $this->assertSame('password', $config['ApiKey']['Type']);
        $this->assertSame('yesno', $config['Sandbox']['Type']);
    }
}

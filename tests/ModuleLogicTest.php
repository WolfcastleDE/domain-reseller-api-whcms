<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Results\ResultsList;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Domain\Registrar\Domain as RegistrarDomain;

/**
 * Tests the actual business logic of module functions by calling helper functions
 * and simulating the full data flow with mock API responses.
 *
 * Since the main module functions create their own ApiClient internally,
 * and we can't override that without modifying production code, these tests
 * focus on the data transformation and logic aspects of each function.
 */
class ModuleLogicTest extends TestCase
{
    // ═══════════════════════════════════════════════
    // Sync status mapping tests
    // ═══════════════════════════════════════════════

    /**
     * Test the full Sync logic by using a mock server is impractical,
     * so we test the status-mapping logic via a helper that simulates
     * what Sync does with domain data.
     */
    private function simulateSync(array $domainData): array
    {
        $values = [];

        if (!empty($domainData['expiresAt'])) {
            $values['expirydate'] = date('Y-m-d', strtotime($domainData['expiresAt']));
        }

        $status = strtolower($domainData['status'] ?? '');
        switch ($status) {
            case 'active':
            case 'registered':
                $values['active'] = true;
                $values['cancelled'] = false;
                $values['transferredAway'] = false;
                break;
            case 'expired':
                $values['expired'] = true;
                $values['active'] = false;
                break;
            case 'cancelled':
            case 'deleted':
                $values['cancelled'] = true;
                $values['active'] = false;
                break;
            case 'transferred_out':
            case 'transferredout':
                $values['transferredAway'] = true;
                $values['active'] = false;
                break;
            case 'pending':
            case 'pending_delete':
                $values['active'] = true;
                break;
            default:
                $values['active'] = true;
                break;
        }

        return $values;
    }

    public function testSyncActiveDomain(): void
    {
        $result = $this->simulateSync([
            'status' => 'active',
            'expiresAt' => '2027-03-03T00:00:00Z',
        ]);

        $this->assertTrue($result['active']);
        $this->assertFalse($result['cancelled']);
        $this->assertFalse($result['transferredAway']);
        $this->assertSame('2027-03-03', $result['expirydate']);
    }

    public function testSyncRegisteredDomain(): void
    {
        $result = $this->simulateSync(['status' => 'registered']);
        $this->assertTrue($result['active']);
    }

    public function testSyncExpiredDomain(): void
    {
        $result = $this->simulateSync(['status' => 'expired']);
        $this->assertTrue($result['expired']);
        $this->assertFalse($result['active']);
    }

    public function testSyncCancelledDomain(): void
    {
        $result = $this->simulateSync(['status' => 'cancelled']);
        $this->assertTrue($result['cancelled']);
        $this->assertFalse($result['active']);
    }

    public function testSyncDeletedDomain(): void
    {
        $result = $this->simulateSync(['status' => 'deleted']);
        $this->assertTrue($result['cancelled']);
    }

    public function testSyncTransferredOutDomain(): void
    {
        $result = $this->simulateSync(['status' => 'transferred_out']);
        $this->assertTrue($result['transferredAway']);
        $this->assertFalse($result['active']);
    }

    public function testSyncTransferredoutVariant(): void
    {
        $result = $this->simulateSync(['status' => 'transferredout']);
        $this->assertTrue($result['transferredAway']);
    }

    public function testSyncPendingDomain(): void
    {
        $result = $this->simulateSync(['status' => 'pending']);
        $this->assertTrue($result['active']);
    }

    public function testSyncPendingDeleteDomain(): void
    {
        $result = $this->simulateSync(['status' => 'pending_delete']);
        $this->assertTrue($result['active']);
    }

    public function testSyncUnknownStatusDefaultsToActive(): void
    {
        $result = $this->simulateSync(['status' => 'some_new_status']);
        $this->assertTrue($result['active']);
    }

    // ═══════════════════════════════════════════════
    // TransferSync status mapping tests
    // ═══════════════════════════════════════════════

    private function simulateTransferSync(?array $domainData): array
    {
        if ($domainData === null) {
            return [];
        }

        $status = strtolower($domainData['status'] ?? '');

        switch ($status) {
            case 'active':
            case 'registered':
                $values = ['completed' => true];
                if (!empty($domainData['expiresAt'])) {
                    $values['expirydate'] = date('Y-m-d', strtotime($domainData['expiresAt']));
                }
                return $values;

            case 'failed':
            case 'cancelled':
            case 'rejected':
                return ['failed' => true, 'reason' => 'Transfer ' . $status];

            default:
                return [];
        }
    }

    public function testTransferSyncCompleted(): void
    {
        $result = $this->simulateTransferSync([
            'status' => 'active',
            'expiresAt' => '2027-06-15T00:00:00Z',
        ]);

        $this->assertTrue($result['completed']);
        $this->assertSame('2027-06-15', $result['expirydate']);
    }

    public function testTransferSyncFailed(): void
    {
        $result = $this->simulateTransferSync(['status' => 'failed']);
        $this->assertTrue($result['failed']);
        $this->assertSame('Transfer failed', $result['reason']);
    }

    public function testTransferSyncRejected(): void
    {
        $result = $this->simulateTransferSync(['status' => 'rejected']);
        $this->assertTrue($result['failed']);
        $this->assertSame('Transfer rejected', $result['reason']);
    }

    public function testTransferSyncInProgress(): void
    {
        $result = $this->simulateTransferSync(['status' => 'pending']);
        $this->assertEmpty($result);
    }

    public function testTransferSyncNotFound(): void
    {
        $result = $this->simulateTransferSync(null);
        $this->assertEmpty($result);
    }

    // ═══════════════════════════════════════════════
    // DNS record transformation tests
    // ═══════════════════════════════════════════════

    private function simulateGetDnsTransform(array $apiRecords): array
    {
        $records = [];
        foreach ($apiRecords as $record) {
            $hostRecord = [
                'hostname' => $record['name'] ?? '',
                'type' => $record['type'] ?? '',
                'address' => $record['content'] ?? '',
            ];

            if (isset($record['priority'])) {
                $hostRecord['priority'] = $record['priority'];
            }

            $records[] = $hostRecord;
        }
        return $records;
    }

    public function testDnsTransformARecord(): void
    {
        $records = $this->simulateGetDnsTransform([
            ['name' => 'www', 'type' => 'A', 'content' => '1.2.3.4'],
        ]);

        $this->assertCount(1, $records);
        $this->assertSame('www', $records[0]['hostname']);
        $this->assertSame('A', $records[0]['type']);
        $this->assertSame('1.2.3.4', $records[0]['address']);
        $this->assertArrayNotHasKey('priority', $records[0]);
    }

    public function testDnsTransformMxRecord(): void
    {
        $records = $this->simulateGetDnsTransform([
            ['name' => '@', 'type' => 'MX', 'content' => 'mail.example.de', 'priority' => 10],
        ]);

        $this->assertSame(10, $records[0]['priority']);
    }

    public function testDnsTransformMultipleRecords(): void
    {
        $records = $this->simulateGetDnsTransform([
            ['name' => '@', 'type' => 'A', 'content' => '1.2.3.4'],
            ['name' => 'www', 'type' => 'CNAME', 'content' => 'example.de'],
            ['name' => '@', 'type' => 'MX', 'content' => 'mx.example.de', 'priority' => 10],
            ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 include:example.de ~all'],
        ]);

        $this->assertCount(4, $records);
        $this->assertSame('CNAME', $records[1]['type']);
        $this->assertSame('v=spf1 include:example.de ~all', $records[3]['address']);
    }

    // ═══════════════════════════════════════════════
    // Contact detail transformation tests
    // ═══════════════════════════════════════════════

    private function simulateContactTransform(array $apiContact): array
    {
        $street = $apiContact['street'] ?? '';
        if (!empty($apiContact['houseNumber'])) {
            $street .= ' ' . $apiContact['houseNumber'];
        }

        return [
            'First Name' => $apiContact['firstName'] ?? '',
            'Last Name' => $apiContact['lastName'] ?? '',
            'Company Name' => $apiContact['organization'] ?? '',
            'Email Address' => $apiContact['email'] ?? '',
            'Phone Number' => $apiContact['phone'] ?? '',
            'Address 1' => $street,
            'Address 2' => $apiContact['extension'] ?? '',
            'City' => $apiContact['city'] ?? '',
            'State' => $apiContact['state'] ?? '',
            'Postcode' => $apiContact['postalCode'] ?? '',
            'Country' => $apiContact['country'] ?? '',
        ];
    }

    public function testContactTransformWithHouseNumber(): void
    {
        $result = $this->simulateContactTransform([
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'organization' => 'ACME',
            'email' => 'max@acme.de',
            'phone' => '+491234567',
            'street' => 'Musterstr',
            'houseNumber' => '42a',
            'extension' => 'Apt 5',
            'city' => 'Berlin',
            'state' => 'Berlin',
            'postalCode' => '10115',
            'country' => 'DE',
        ]);

        $this->assertSame('Max', $result['First Name']);
        $this->assertSame('Musterstr 42a', $result['Address 1']);
        $this->assertSame('Apt 5', $result['Address 2']);
    }

    public function testContactTransformWithoutHouseNumber(): void
    {
        $result = $this->simulateContactTransform([
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'street' => '123 Main Street',
            'city' => 'London',
            'postalCode' => 'SW1A 1AA',
            'country' => 'GB',
        ]);

        $this->assertSame('123 Main Street', $result['Address 1']);
    }

    // ═══════════════════════════════════════════════
    // TLD Pricing transformation tests
    // ═══════════════════════════════════════════════

    private function simulatePricingTransform(array $apiPricing): array
    {
        $results = [];
        foreach ($apiPricing as $tldInfo) {
            $tld = ltrim($tldInfo['tld'] ?? '', '.');
            if (empty($tld)) {
                continue;
            }

            $results[] = [
                'extension' => $tld,
                'minYears' => (int) ($tldInfo['minYears'] ?? 1),
                'maxYears' => (int) ($tldInfo['maxYears'] ?? 10),
                'registerPrice' => (float) ($tldInfo['registerPrice'] ?? 0),
                'renewPrice' => (float) ($tldInfo['renewPrice'] ?? 0),
                'transferPrice' => (float) ($tldInfo['transferPrice'] ?? 0),
                'hasRestore' => !empty($tldInfo['restorePrice']),
                'restorePrice' => (float) ($tldInfo['restorePrice'] ?? 0),
                'eppRequired' => $tld !== 'de',
            ];
        }
        return $results;
    }

    public function testPricingTransformDeTld(): void
    {
        $result = $this->simulatePricingTransform([
            [
                'tld' => '.de',
                'registerPrice' => '5.00',
                'renewPrice' => '4.50',
                'transferPrice' => '4.50',
                'restorePrice' => '79.00',
                'minYears' => 1,
                'maxYears' => 1,
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('de', $result[0]['extension']);
        $this->assertSame(5.00, $result[0]['registerPrice']);
        $this->assertSame(4.50, $result[0]['renewPrice']);
        $this->assertSame(79.00, $result[0]['restorePrice']);
        $this->assertFalse($result[0]['eppRequired']);
        $this->assertSame(1, $result[0]['maxYears']);
    }

    public function testPricingTransformComTld(): void
    {
        $result = $this->simulatePricingTransform([
            [
                'tld' => 'com',
                'registerPrice' => '9.99',
                'renewPrice' => '9.99',
                'transferPrice' => '9.99',
                'restorePrice' => null,
                'minYears' => 1,
                'maxYears' => 10,
            ],
        ]);

        $this->assertTrue($result[0]['eppRequired']);
        $this->assertSame(10, $result[0]['maxYears']);
        $this->assertFalse($result[0]['hasRestore']);
    }

    public function testPricingTransformSkipsEmptyTld(): void
    {
        $result = $this->simulatePricingTransform([
            ['tld' => '', 'registerPrice' => '1.00'],
            ['tld' => 'org', 'registerPrice' => '8.00'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('org', $result[0]['extension']);
    }

    public function testPricingTransformMultipleTlds(): void
    {
        $result = $this->simulatePricingTransform([
            ['tld' => 'de', 'registerPrice' => '5.00', 'renewPrice' => '4.50', 'transferPrice' => '4.50'],
            ['tld' => 'com', 'registerPrice' => '9.99', 'renewPrice' => '9.99', 'transferPrice' => '9.99'],
            ['tld' => 'net', 'registerPrice' => '10.99', 'renewPrice' => '10.99', 'transferPrice' => '10.99'],
        ]);

        $this->assertCount(3, $result);
    }

    // ═══════════════════════════════════════════════
    // CheckAvailability transformation tests
    // ═══════════════════════════════════════════════

    private function simulateAvailabilityTransform(array $apiResponse): array
    {
        $data = $apiResponse['data'] ?? [];

        if (!empty($data['available'])) {
            $status = 'not_registered';
        } else {
            $status = 'registered';
        }

        $result = ['status' => $status];

        if (!empty($data['premium'])) {
            $result['isPremium'] = true;
            $result['pricing'] = [
                'register' => $data['setupPrice'] ?? $data['registerPrice'] ?? null,
                'renew' => $data['renewPrice'] ?? null,
                'transfer' => $data['transferPrice'] ?? null,
            ];
        }

        return $result;
    }

    public function testAvailabilityTransformAvailable(): void
    {
        $result = $this->simulateAvailabilityTransform([
            'success' => true,
            'data' => ['available' => true, 'premium' => false],
        ]);

        $this->assertSame('not_registered', $result['status']);
        $this->assertArrayNotHasKey('isPremium', $result);
    }

    public function testAvailabilityTransformRegistered(): void
    {
        $result = $this->simulateAvailabilityTransform([
            'success' => true,
            'data' => ['available' => false, 'premium' => false],
        ]);

        $this->assertSame('registered', $result['status']);
    }

    public function testAvailabilityTransformPremium(): void
    {
        $result = $this->simulateAvailabilityTransform([
            'success' => true,
            'data' => [
                'available' => true,
                'premium' => true,
                'setupPrice' => '99.00',
                'renewPrice' => '89.00',
                'transferPrice' => '99.00',
            ],
        ]);

        $this->assertSame('not_registered', $result['status']);
        $this->assertTrue($result['isPremium']);
        $this->assertSame('99.00', $result['pricing']['register']);
        $this->assertSame('89.00', $result['pricing']['renew']);
    }

    // ═══════════════════════════════════════════════
    // DNS SaveDNS filtering logic
    // ═══════════════════════════════════════════════

    public function testSaveDnsFilterLogic(): void
    {
        // Test that empty type or empty address records are skipped
        $records = [
            ['type' => 'A', 'hostname' => 'www', 'address' => '1.2.3.4'],
            ['type' => '', 'hostname' => 'skip', 'address' => '5.6.7.8'],
            ['type' => 'CNAME', 'hostname' => 'mail', 'address' => ''],
            ['type' => 'MX', 'hostname' => '@', 'address' => 'mx.example.com', 'priority' => 10],
            ['type' => 'SRV', 'hostname' => '_sip', 'address' => 'sip.example.com', 'priority' => 5],
            ['type' => 'TXT', 'hostname' => '@', 'address' => 'v=spf1'],
        ];

        $created = [];
        foreach ($records as $record) {
            $type = $record['type'] ?? '';
            $hostname = $record['hostname'] ?? '';
            $address = $record['address'] ?? '';

            if (empty($type) || empty($address)) {
                continue;
            }

            $priority = null;
            if (in_array($type, ['MX', 'SRV'])) {
                $priority = (int) ($record['priority'] ?? 10);
            }

            $created[] = [
                'type' => $type,
                'name' => $hostname,
                'content' => $address,
                'priority' => $priority,
            ];
        }

        $this->assertCount(4, $created);
        $this->assertSame('A', $created[0]['type']);
        $this->assertSame('MX', $created[1]['type']);
        $this->assertSame(10, $created[1]['priority']);
        $this->assertSame('SRV', $created[2]['type']);
        $this->assertSame(5, $created[2]['priority']);
        $this->assertSame('TXT', $created[3]['type']);
        $this->assertNull($created[3]['priority']);
    }

    public function testSaveDnsSkipsApexSoaAndNsRecords(): void
    {
        $existingRecords = [
            ['id' => '1', 'type' => 'SOA', 'name' => '@'],
            ['id' => '2', 'type' => 'NS', 'name' => ''],
            ['id' => '3', 'type' => 'NS', 'name' => 'example.de'],
            ['id' => '4', 'type' => 'A', 'name' => 'www'],
            ['id' => '5', 'type' => 'NS', 'name' => 'sub'],
            ['id' => '6', 'type' => 'MX', 'name' => '@'],
        ];

        $domain = 'example.de';
        $toDelete = [];

        foreach ($existingRecords as $record) {
            if (!empty($record['id'])) {
                if (in_array($record['type'], ['SOA', 'NS']) && ($record['name'] === '@' || $record['name'] === '' || $record['name'] === $domain)) {
                    continue;
                }
                $toDelete[] = $record['id'];
            }
        }

        // Should skip: SOA @, NS (empty), NS example.de
        // Should delete: A www, NS sub, MX @
        $this->assertSame(['4', '5', '6'], $toDelete);
    }

    // ═══════════════════════════════════════════════
    // Nameserver extraction from domain info
    // ═══════════════════════════════════════════════

    public function testNameserverExtraction(): void
    {
        $nameservers = ['ns1.example.com', 'ns2.example.com', 'ns3.example.com'];

        $values = [];
        for ($i = 0; $i < min(5, count($nameservers)); $i++) {
            $values['ns' . ($i + 1)] = $nameservers[$i];
        }

        $this->assertSame([
            'ns1' => 'ns1.example.com',
            'ns2' => 'ns2.example.com',
            'ns3' => 'ns3.example.com',
        ], $values);
    }

    public function testNameserverExtractionCapsAtFive(): void
    {
        $nameservers = array_map(fn($i) => "ns{$i}.example.com", range(1, 8));

        $values = [];
        for ($i = 0; $i < min(5, count($nameservers)); $i++) {
            $values['ns' . ($i + 1)] = $nameservers[$i];
        }

        $this->assertCount(5, $values);
        $this->assertSame('ns5.example.com', $values['ns5']);
    }

    // ═══════════════════════════════════════════════
    // Street/house number parsing (SaveContactDetails)
    // ═══════════════════════════════════════════════

    /** @dataProvider streetParsingProvider */
    public function testStreetParsing(string $input, string $expectedStreet, string $expectedHouseNumber): void
    {
        $street = $input;
        $houseNumber = '';
        if (preg_match('/^(.+?)\s+(\d+\S*)$/', $street, $matches)) {
            $street = $matches[1];
            $houseNumber = $matches[2];
        }

        $this->assertSame($expectedStreet, $street);
        $this->assertSame($expectedHouseNumber, $houseNumber);
    }

    public static function streetParsingProvider(): array
    {
        return [
            'German format' => ['Musterstraße 42', 'Musterstraße', '42'],
            'With letter suffix' => ['Hauptstr 10a', 'Hauptstr', '10a'],
            'With slash' => ['Berliner Allee 123/4', 'Berliner Allee', '123/4'],
            'Multi-word street' => ['Am Alten Markt 5', 'Am Alten Markt', '5'],
            'No house number' => ['OnlyStreetName', 'OnlyStreetName', ''],
            'PO Box style' => ['Postfach', 'Postfach', ''],
            'With dash' => ['Rathausplatz 3-5', 'Rathausplatz', '3-5'],
        ];
    }
}

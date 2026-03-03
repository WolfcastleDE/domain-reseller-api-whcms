<?php

/**
 * Domain Reseller API - WHMCS Registrar Module
 *
 * A comprehensive WHMCS registrar module for the Domain Reseller API
 * by Wolfscastle (https://domain-reseller-api.de).
 *
 * @see https://domain-reseller-api.de/swagger
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Results\ResultsList;
use WHMCS\Module\Registrar\DomainResellerApi\ApiClient;

require_once __DIR__ . '/lib/ApiClient.php';

// ─────────────────────────────────────────────────────────────
// Helper: Build API client from WHMCS params
// ─────────────────────────────────────────────────────────────

function domainresellerapi_getClient(array $params): ApiClient
{
    $timeout = max(5, min(120, (int) ($params['Timeout'] ?? 30)));

    return new ApiClient(
        $params['ApiKey'],
        $params['ApiUrl'] ?? 'https://domain-reseller-api.de',
        (bool) ($params['Sandbox'] ?? false),
        $timeout
    );
}

/**
 * Build the full domain name from WHMCS params.
 */
function domainresellerapi_getDomain(array $params): string
{
    return $params['sld'] . '.' . $params['tld'];
}

/**
 * Collect nameservers from WHMCS params into a filtered array.
 */
function domainresellerapi_collectNameservers(array $params): array
{
    $ns = [];
    for ($i = 1; $i <= 5; $i++) {
        if (!empty($params['ns' . $i])) {
            $ns[] = $params['ns' . $i];
        }
    }
    return $ns;
}

/**
 * Find or create a contact handle for the given WHMCS contact details.
 */
function domainresellerapi_getOrCreateContact(ApiClient $client, array $params, string $prefix = ''): string
{
    $contactData = [
        'type' => !empty($params[$prefix . 'companyname']) ? 'org' : 'person',
        'sex' => 'unknown',
        'firstName' => $params[$prefix . 'firstname'] ?? '',
        'lastName' => $params[$prefix . 'lastname'] ?? '',
        'organization' => $params[$prefix . 'companyname'] ?? '',
        'email' => $params[$prefix . 'email'] ?? '',
        'phone' => domainresellerapi_formatPhone($params[$prefix . 'fullphonenumber'] ?? $params[$prefix . 'phonenumber'] ?? '', $params[$prefix . 'country'] ?? ''),
        'street' => $params[$prefix . 'address1'] ?? '',
        'houseNumber' => '',
        'city' => $params[$prefix . 'city'] ?? '',
        'postalCode' => $params[$prefix . 'postcode'] ?? '',
        'state' => $params[$prefix . 'state'] ?? '',
        'country' => $params[$prefix . 'country'] ?? '',
    ];

    // Extract house number from street if possible (common European format)
    if (preg_match('/^(.+?)\s+(\d+\S*)$/', $contactData['street'], $matches)) {
        $contactData['street'] = $matches[1];
        $contactData['houseNumber'] = $matches[2];
    }

    if (!empty($params[$prefix . 'address2'])) {
        $contactData['extension'] = $params[$prefix . 'address2'];
    }

    $result = $client->createContact($contactData);

    if (!empty($result['success']) && !empty($result['data']['handle'])) {
        return $result['data']['handle'];
    }

    throw new \Exception('Failed to create contact: ' . ($result['error'] ?? 'Unknown error'));
}

/**
 * Format phone number to E.164-ish format expected by the API.
 */
function domainresellerapi_formatPhone(string $phone, string $country = ''): string
{
    $phone = trim($phone);
    if (empty($phone)) {
        return '+0.0';
    }

    // Already in international format
    if (str_starts_with($phone, '+')) {
        return $phone;
    }

    // Common country code prefixes
    $countryPhoneCodes = [
        'DE' => '+49', 'AT' => '+43', 'CH' => '+41', 'US' => '+1', 'GB' => '+44',
        'FR' => '+33', 'NL' => '+31', 'BE' => '+32', 'IT' => '+39', 'ES' => '+34',
        'PL' => '+48', 'CZ' => '+420', 'DK' => '+45', 'SE' => '+46', 'NO' => '+47',
        'FI' => '+358', 'PT' => '+351', 'IE' => '+353', 'LU' => '+352',
    ];

    $prefix = $countryPhoneCodes[strtoupper($country)] ?? '+0';

    // Remove leading zero for local numbers
    $phone = ltrim($phone, '0');

    return $prefix . $phone;
}

// ─────────────────────────────────────────────────────────────
// Module Meta & Configuration
// ─────────────────────────────────────────────────────────────

function domainresellerapi_MetaData(): array
{
    return [
        'DisplayName' => 'Domain Reseller API',
        'APIVersion' => '1.1',
    ];
}

function domainresellerapi_getConfigArray(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Domain Reseller API',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Register and manage domains via the Domain Reseller API by Wolfscastle.',
        ],
        'ApiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => 60,
            'Description' => 'Your Domain Reseller API key (drapi_xxx_xxx).',
            'Required' => true,
        ],
        'ApiUrl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => 60,
            'Default' => 'https://domain-reseller-api.de',
            'Description' => 'Base URL for the API.',
        ],
        'Sandbox' => [
            'FriendlyName' => 'Sandbox Mode',
            'Type' => 'yesno',
            'Description' => 'Enable sandbox mode (no real registrations or charges).',
        ],
        'Timeout' => [
            'FriendlyName' => 'API Timeout',
            'Type' => 'text',
            'Size' => 5,
            'Default' => '30',
            'Description' => 'API request timeout in seconds.',
        ],
    ];
}

// ─────────────────────────────────────────────────────────────
// Domain Registration
// ─────────────────────────────────────────────────────────────

function domainresellerapi_RegisterDomain(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);
        $nameservers = domainresellerapi_collectNameservers($params);

        $ownerHandle = domainresellerapi_getOrCreateContact($client, $params);
        $adminHandle = null;
        $techHandle = null;

        if (!empty($params['adminfirstname'])) {
            try {
                $adminHandle = domainresellerapi_getOrCreateContact($client, $params, 'admin');
            } catch (\Exception $e) {
                logModuleCall('domainresellerapi', 'RegisterDomain-AdminContact', ['domain' => $domain], $e->getMessage(), '', [$params['ApiKey'] ?? '']);
            }
        }

        if (!empty($params['techfirstname'])) {
            try {
                $techHandle = domainresellerapi_getOrCreateContact($client, $params, 'tech');
            } catch (\Exception $e) {
                logModuleCall('domainresellerapi', 'RegisterDomain-TechContact', ['domain' => $domain], $e->getMessage(), '', [$params['ApiKey'] ?? '']);
            }
        }

        $years = (int) ($params['regperiod'] ?? 1);

        $result = $client->registerDomain(
            $domain,
            $ownerHandle,
            $adminHandle,
            $techHandle,
            $nameservers,
            $years
        );

        logModuleCall('domainresellerapi', 'RegisterDomain', ['domain' => $domain, 'years' => $years, 'owner' => $ownerHandle], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Domain registration failed'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Domain Transfer
// ─────────────────────────────────────────────────────────────

function domainresellerapi_TransferDomain(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);
        $authCode = $params['eppcode'] ?? $params['transfersecret'] ?? '';

        $ownerHandle = domainresellerapi_getOrCreateContact($client, $params);
        $adminHandle = null;
        $techHandle = null;

        if (!empty($params['adminfirstname'])) {
            try {
                $adminHandle = domainresellerapi_getOrCreateContact($client, $params, 'admin');
            } catch (\Exception $e) {
                logModuleCall('domainresellerapi', 'TransferDomain-AdminContact', ['domain' => $domain], $e->getMessage(), '', [$params['ApiKey'] ?? '']);
            }
        }

        $result = $client->transferDomain($domain, $authCode, $ownerHandle, $adminHandle, $techHandle);

        logModuleCall('domainresellerapi', 'TransferDomain', ['domain' => $domain, 'owner' => $ownerHandle], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Domain transfer failed'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Domain Renewal
// ─────────────────────────────────────────────────────────────

function domainresellerapi_RenewDomain(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        // The Domain Reseller API does not expose a dedicated "renew now" endpoint.
        // Renewal is handled automatically by the upstream registrar when autoRenew
        // is enabled and the wallet has sufficient balance. We enable autoRenew here
        // to ensure the domain will be renewed at its expiry.
        $result = $client->updateDomain($domain, [
            'autoRenew' => true,
        ]);

        logModuleCall('domainresellerapi', 'RenewDomain', ['domain' => $domain, 'regperiod' => $params['regperiod'] ?? 1], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Domain renewal failed'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Nameservers
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetNameservers(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->getDomain($domain);

        if (!empty($result['success']) && isset($result['data']['nameservers'])) {
            $values = [];
            $nameservers = $result['data']['nameservers'];
            for ($i = 0; $i < min(5, count($nameservers)); $i++) {
                $values['ns' . ($i + 1)] = $nameservers[$i];
            }
            return $values;
        }

        return ['error' => $result['error'] ?? 'Failed to retrieve nameservers'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_SaveNameservers(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);
        $nameservers = domainresellerapi_collectNameservers($params);

        if (count($nameservers) < 2) {
            return ['error' => 'At least 2 nameservers are required'];
        }

        $result = $client->updateDomain($domain, [
            'nameservers' => $nameservers,
        ]);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to update nameservers'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Registrar Lock (not supported by this API - return graceful defaults)
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetRegistrarLock(array $params): string
{
    // The Domain Reseller API does not expose a registrar lock toggle.
    // Return empty string to tell WHMCS that lock status is unsupported.
    return '';
}

function domainresellerapi_SaveRegistrarLock(array $params): array
{
    return ['error' => 'Registrar lock is not supported by the Domain Reseller API'];
}

// ─────────────────────────────────────────────────────────────
// EPP Code / Auth Code
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetEPPCode(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->getAuthCode($domain);

        if (!empty($result['success']) && !empty($result['data']['authCode'])) {
            return ['eppcode' => $result['data']['authCode']];
        }

        return ['error' => $result['error'] ?? 'Failed to retrieve auth code'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// DNS Management
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetDNS(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->getDnsRecords($domain);

        if (!empty($result['success']) && !empty($result['data']['records'])) {
            $records = [];
            foreach ($result['data']['records'] as $record) {
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

        if (!empty($result['success'])) {
            return [];
        }

        return ['error' => $result['error'] ?? 'Failed to retrieve DNS records'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_SaveDNS(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        // First ensure managed DNS is enabled
        $domainInfo = $client->getDomain($domain);
        if (!empty($domainInfo['success']) && empty($domainInfo['data']['useManagedDns'])) {
            $client->enableManagedDns($domain);
        }

        // Create new records first, before deleting old ones.
        // If creation fails entirely, we abort without destroying existing records.
        $dnsrecords = $params['dnsrecords'] ?? [];
        $errors = [];
        $createdCount = 0;
        $attemptedCount = 0;
        foreach ($dnsrecords as $record) {
            $type = $record['type'] ?? '';
            $hostname = $record['hostname'] ?? '';
            $address = $record['address'] ?? '';

            if (empty($type) || empty($address)) {
                continue;
            }

            $attemptedCount++;
            $priority = null;
            if (in_array($type, ['MX', 'SRV'])) {
                $priority = (int) ($record['priority'] ?? 10);
            }

            $createResult = $client->createDnsRecord($domain, $type, $hostname, $address, 3600, $priority);
            if (empty($createResult['success'])) {
                $errors[] = sprintf('%s %s: %s', $type, $hostname, $createResult['error'] ?? 'failed');
            } else {
                $createdCount++;
            }
        }

        // If no records were created at all and we tried, abort without deleting existing records
        if ($attemptedCount > 0 && $createdCount === 0) {
            logModuleCall('domainresellerapi', 'SaveDNS', $dnsrecords, $errors, '', [$params['ApiKey'] ?? '']);
            return ['error' => 'All DNS record creations failed, existing records preserved: ' . implode('; ', $errors)];
        }

        // Now delete old records that are no longer needed
        $existing = $client->getDnsRecords($domain);
        if (!empty($existing['success']) && !empty($existing['data']['records'])) {
            foreach ($existing['data']['records'] as $record) {
                if (!empty($record['id'])) {
                    // Skip SOA and NS records at zone apex - these are managed by the provider
                    if (in_array($record['type'], ['SOA', 'NS']) && ($record['name'] === '@' || $record['name'] === '' || $record['name'] === $domain)) {
                        continue;
                    }
                    // Skip records that were just created (they won't have an old ID)
                    $client->deleteDnsRecord($domain, $record['id']);
                }
            }
        }

        if (!empty($errors)) {
            logModuleCall('domainresellerapi', 'SaveDNS', $dnsrecords, $errors, '', [$params['ApiKey'] ?? '']);
            return ['error' => 'Some DNS records failed: ' . implode('; ', $errors)];
        }

        return ['success' => true];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// WHOIS Contact Information
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetContactDetails(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->getDomain($domain);

        if (empty($result['success']) || empty($result['data']['contacts'])) {
            return ['error' => $result['error'] ?? 'Failed to retrieve domain info'];
        }

        $contacts = $result['data']['contacts'];
        $details = [];

        $contactTypes = [
            'owner' => 'Registrant',
            'admin' => 'Admin',
            'tech' => 'Technical',
        ];

        foreach ($contactTypes as $apiType => $whmcsType) {
            $handle = $contacts[$apiType] ?? null;
            if (empty($handle)) {
                continue;
            }

            // Handle can be either a handle string or an inline object
            if (is_string($handle)) {
                $contactResult = $client->getContact($handle);
                if (empty($contactResult['success'])) {
                    continue;
                }
                $contact = $contactResult['data'];
            } else {
                $contact = $handle;
            }

            $street = $contact['street'] ?? '';
            if (!empty($contact['houseNumber'])) {
                $street .= ' ' . $contact['houseNumber'];
            }

            $details[$whmcsType] = [
                'First Name' => $contact['firstName'] ?? '',
                'Last Name' => $contact['lastName'] ?? '',
                'Company Name' => $contact['organization'] ?? '',
                'Email Address' => $contact['email'] ?? '',
                'Phone Number' => $contact['phone'] ?? '',
                'Address 1' => $street,
                'Address 2' => $contact['extension'] ?? '',
                'City' => $contact['city'] ?? '',
                'State' => $contact['state'] ?? '',
                'Postcode' => $contact['postalCode'] ?? '',
                'Country' => $contact['country'] ?? '',
            ];
        }

        return $details;
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_SaveContactDetails(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        // Get current domain to find existing contact handles
        $domainInfo = $client->getDomain($domain);
        if (empty($domainInfo['success'])) {
            return ['error' => $domainInfo['error'] ?? 'Failed to retrieve domain info'];
        }

        $contacts = $domainInfo['data']['contacts'] ?? [];
        $contactDetails = $params['contactdetails'] ?? [];

        $contactTypes = [
            'Registrant' => 'owner',
            'Admin' => 'admin',
            'Technical' => 'tech',
        ];

        $errors = [];

        foreach ($contactTypes as $whmcsType => $apiType) {
            if (empty($contactDetails[$whmcsType])) {
                continue;
            }

            $whmcsContact = $contactDetails[$whmcsType];
            $handle = $contacts[$apiType] ?? null;

            if (empty($handle) || !is_string($handle)) {
                continue;
            }

            // Parse street and house number
            $street = $whmcsContact['Address 1'] ?? '';
            $houseNumber = '';
            if (preg_match('/^(.+?)\s+(\d+\S*)$/', $street, $matches)) {
                $street = $matches[1];
                $houseNumber = $matches[2];
            }

            $updateData = [
                'firstName' => $whmcsContact['First Name'] ?? '',
                'lastName' => $whmcsContact['Last Name'] ?? '',
                'organization' => $whmcsContact['Company Name'] ?? '',
                'email' => $whmcsContact['Email Address'] ?? '',
                'phone' => domainresellerapi_formatPhone($whmcsContact['Phone Number'] ?? '', $whmcsContact['Country'] ?? ''),
                'street' => $street,
                'houseNumber' => $houseNumber,
                'city' => $whmcsContact['City'] ?? '',
                'postalCode' => $whmcsContact['Postcode'] ?? '',
                'state' => $whmcsContact['State'] ?? '',
                'country' => $whmcsContact['Country'] ?? '',
            ];

            if (!empty($whmcsContact['Address 2'])) {
                $updateData['extension'] = $whmcsContact['Address 2'];
            }

            $updateResult = $client->updateContact($handle, $updateData);
            if (empty($updateResult['success'])) {
                $errors[] = sprintf('%s contact (%s): %s', $whmcsType, $handle, $updateResult['error'] ?? 'update failed');
            }
        }

        if (!empty($errors)) {
            return ['error' => 'Contact update errors: ' . implode('; ', $errors)];
        }

        return ['success' => true];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Domain Availability Check
// ─────────────────────────────────────────────────────────────

function domainresellerapi_CheckAvailability(array $params): ResultsList
{
    $results = new ResultsList();

    try {
        $client = domainresellerapi_getClient($params);
        $sld = $params['searchTerm'] ?? $params['sld'] ?? '';
        $tldsToCheck = $params['tlds'] ?? [];

        foreach ($tldsToCheck as $tld) {
            $tld = ltrim($tld, '.');
            $domain = $sld . '.' . $tld;

            $result = $client->checkDomain($domain);

            $searchResult = new \WHMCS\Domain\Registrar\Domain();
            $searchResult->setDomainName($sld);
            $searchResult->setDomainTld($tld);

            if (!empty($result['success']) && isset($result['data'])) {
                $data = $result['data'];

                if (!empty($data['available'])) {
                    $status = \WHMCS\Domain\TopLevel\ImportItem::STATUS_NOT_REGISTERED;
                } else {
                    $status = \WHMCS\Domain\TopLevel\ImportItem::STATUS_REGISTERED;
                }

                $searchResult->setStatus($status);

                if (!empty($data['premium'])) {
                    $searchResult->setIsPremiumName(true);
                    $searchResult->setPremiumCostPricing([
                        'register' => $data['setupPrice'] ?? $data['registerPrice'] ?? null,
                        'renew' => $data['renewPrice'] ?? null,
                        'transfer' => $data['transferPrice'] ?? null,
                        'CurrencyCode' => $data['currency'] ?? 'EUR',
                    ]);
                }
            } else {
                $searchResult->setStatus(\WHMCS\Domain\TopLevel\ImportItem::STATUS_UNKNOWN);
            }

            $results->append($searchResult);
        }
    } catch (\Exception $e) {
        // Return empty results on error
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────
// Domain Suggestions
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetDomainSuggestions(array $params): ResultsList
{
    $results = new ResultsList();

    try {
        $client = domainresellerapi_getClient($params);
        $sld = $params['searchTerm'] ?? $params['sld'] ?? '';
        $tlds = $params['tlds'] ?? [];

        // Strip dots from TLDs
        $cleanTlds = array_map(function ($tld) {
            return ltrim($tld, '.');
        }, $tlds);

        $result = $client->suggestDomains($sld, $cleanTlds);

        if (!empty($result['success']) && !empty($result['data'])) {
            $suggestions = $result['data'];
            if (isset($suggestions['suggestions'])) {
                $suggestions = $suggestions['suggestions'];
            }

            foreach ($suggestions as $suggestion) {
                $sugDomain = $suggestion['domain'] ?? $suggestion['name'] ?? '';
                if (empty($sugDomain)) {
                    continue;
                }

                $parts = explode('.', $sugDomain, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $searchResult = new \WHMCS\Domain\Registrar\Domain();
                $searchResult->setDomainName($parts[0]);
                $searchResult->setDomainTld($parts[1]);
                $searchResult->setStatus(\WHMCS\Domain\TopLevel\ImportItem::STATUS_NOT_REGISTERED);

                $results->append($searchResult);
            }
        }
    } catch (\Exception $e) {
        // Return empty results on error
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────
// TLD Pricing Sync
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetTldPricing(array $params): ResultsList
{
    $results = new ResultsList();

    try {
        $client = domainresellerapi_getClient($params);
        $result = $client->getPricing();

        if (empty($result['success'])) {
            return $results;
        }

        $pricingData = $result['data'] ?? [];

        // The API may return a single TLD object or an array
        if (isset($pricingData['tld'])) {
            $pricingData = [$pricingData];
        }

        foreach ($pricingData as $tldInfo) {
            $tld = $tldInfo['tld'] ?? '';
            if (empty($tld)) {
                continue;
            }

            $tld = ltrim($tld, '.');

            $item = new ImportItem();
            $item->setExtension($tld);
            $item->setMinYears((int) ($tldInfo['minYears'] ?? 1));
            $item->setMaxYears((int) ($tldInfo['maxYears'] ?? 10));

            $registerPrice = (float) ($tldInfo['registerPrice'] ?? 0);
            $renewPrice = (float) ($tldInfo['renewPrice'] ?? 0);
            $transferPrice = (float) ($tldInfo['transferPrice'] ?? 0);

            $item->setRegisterPrice($registerPrice);
            $item->setRenewPrice($renewPrice);
            $item->setTransferPrice($transferPrice);

            if (!empty($tldInfo['restorePrice'])) {
                $item->setRedemptionFeeDays(30);
                $item->setRedemptionFeePrice((float) $tldInfo['restorePrice']);
            }

            $item->setCurrency('EUR');
            $item->setEppRequired($tld !== 'de');

            $results->append($item);
        }
    } catch (\Exception $e) {
        // Return empty results on error
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────
// Domain Sync (cron-based)
// ─────────────────────────────────────────────────────────────

function domainresellerapi_Sync(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->getDomain($domain);

        if (empty($result['success']) || empty($result['data'])) {
            return ['error' => $result['error'] ?? 'Failed to retrieve domain info'];
        }

        $data = $result['data'];
        $values = [];

        // Expiry date
        if (!empty($data['expiresAt'])) {
            $values['expirydate'] = date('Y-m-d', strtotime($data['expiresAt']));
        }

        // Domain status mapping
        $status = strtolower($data['status'] ?? '');
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
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Transfer Sync (cron-based)
// ─────────────────────────────────────────────────────────────

function domainresellerapi_TransferSync(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->getDomain($domain);

        if (empty($result['success']) || empty($result['data'])) {
            // Domain not found yet - transfer still in progress
            return [];
        }

        $data = $result['data'];
        $status = strtolower($data['status'] ?? '');

        switch ($status) {
            case 'active':
            case 'registered':
                $values = ['completed' => true];
                if (!empty($data['expiresAt'])) {
                    $values['expirydate'] = date('Y-m-d', strtotime($data['expiresAt']));
                }
                return $values;

            case 'failed':
            case 'cancelled':
            case 'rejected':
                return ['failed' => true, 'reason' => 'Transfer ' . $status];

            default:
                // Still in progress
                return [];
        }
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Domain Deletion
// ─────────────────────────────────────────────────────────────

function domainresellerapi_RequestDelete(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->deleteDomain($domain);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to delete domain'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Custom Admin Actions (optional WHMCS buttons)
// ─────────────────────────────────────────────────────────────

function domainresellerapi_AdminCustomButtonArray(): array
{
    return [
        'Restore Domain' => 'RestoreDomain',
        'Cancel Deletion' => 'CancelDeletion',
        'Reset Auth Code' => 'ResetAuthCode',
        'Enable Managed DNS' => 'EnableManagedDns',
        'Disable Managed DNS' => 'DisableManagedDns',
    ];
}

function domainresellerapi_RestoreDomain(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->restoreDomain($domain);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to restore domain'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_CancelDeletion(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->cancelDelete($domain);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to cancel deletion'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_ResetAuthCode(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->resetAuthCode($domain);

        logModuleCall('domainresellerapi', 'ResetAuthCode', ['domain' => $domain], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to reset auth code'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_EnableManagedDns(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->enableManagedDns($domain);

        logModuleCall('domainresellerapi', 'EnableManagedDns', ['domain' => $domain], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to enable managed DNS'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_DisableManagedDns(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->disableManagedDns($domain);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => $result['error'] ?? 'Failed to disable managed DNS'];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

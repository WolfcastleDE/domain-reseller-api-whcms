<?php

/**
 * Domain Reseller API - WHMCS Registrar Module
 *
 * A comprehensive WHMCS registrar module for the Domain Reseller API
 * by Wolfscastle (https://domain-reseller-api.de).
 *
 * @see https://domain-reseller-api.de/dokumentation
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Domains\DomainLookup\ResultsList as LookupResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Results\ResultsList;
use WHMCS\Module\Registrar\DomainResellerApi\ApiClient;
use WHMCS\Module\Registrar\DomainResellerApi\CallingCodes;

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/CallingCodes.php';

/** Phone format of the API/registry: +CC.NUMBER */
const DOMAINRESELLERAPI_PHONE_PATTERN = '/^\+\d{1,3}\.\d{1,14}$/';
/** E-mail format accepted by the API/registry */
const DOMAINRESELLERAPI_EMAIL_PATTERN = '/^[0-9a-zA-Z._-]{1,64}@[0-9a-zA-Z._-]{3,64}$/';
/** Hostname format the API accepts for nameservers */
const DOMAINRESELLERAPI_HOSTNAME_PATTERN = '/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/';
/** Value GET /domains/{domain}/authcode returns when the registry delivered no code */
const DOMAINRESELLERAPI_NO_AUTHCODE = 'Unable to retrieve auth code';

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

function domainresellerapi_getClient(array $params): ApiClient
{
    if (ApiClient::$factory !== null) {
        return (ApiClient::$factory)($params);
    }

    $timeout = max(5, min(120, (int) ($params['Timeout'] ?? 30)));

    return new ApiClient(
        (string) ($params['ApiKey'] ?? ''),
        !empty($params['ApiUrl']) ? (string) $params['ApiUrl'] : 'https://domain-reseller-api.de',
        !empty($params['Sandbox']),
        $timeout
    );
}

/**
 * Build the full (lower-case) domain name from WHMCS params.
 */
function domainresellerapi_getDomain(array $params): string
{
    return strtolower(trim((string) $params['sld']) . '.' . ltrim(trim((string) $params['tld']), '.'));
}

/**
 * Trim, lower-case, strip trailing dots and remove empty/duplicate nameservers.
 */
function domainresellerapi_normalizeNameservers(array $nameservers): array
{
    $result = [];
    foreach ($nameservers as $ns) {
        if (!is_scalar($ns)) {
            continue;
        }
        $ns = rtrim(strtolower(trim((string) $ns)), '.');
        if ($ns !== '' && !in_array($ns, $result, true)) {
            $result[] = $ns;
        }
    }
    return $result;
}

/**
 * Collect nameservers ns1..ns5 from WHMCS params.
 */
function domainresellerapi_collectNameservers(array $params): array
{
    $ns = [];
    for ($i = 1; $i <= 5; $i++) {
        $ns[] = $params['ns' . $i] ?? '';
    }
    return domainresellerapi_normalizeNameservers($ns);
}

/**
 * Format a phone number into the +CC.NUMBER format required by the API
 * (pattern ^\+\d+\.\d+$). Returns null if the number cannot be formatted.
 */
function domainresellerapi_formatPhone(string $phone, string $country = ''): ?string
{
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }

    if (preg_match(DOMAINRESELLERAPI_PHONE_PATTERN, $phone)) {
        return $phone;
    }

    // Drop extensions such as "x123" or "ext. 123"
    $phone = (string) preg_replace('/\s*(?:x|ext\.?|extension)\s*\d+$/i', '', $phone);

    $international = str_starts_with($phone, '+');
    $digits = (string) preg_replace('/\D+/', '', $phone);
    if (!$international && str_starts_with($digits, '00')) {
        $international = true;
        $digits = substr($digits, 2);
    }
    if ($digits === '') {
        return null;
    }

    $country = strtoupper(trim($country));
    $countryCode = CallingCodes::MAP[$country] ?? null;

    if ($international) {
        $code = null;
        if ($countryCode !== null && str_starts_with($digits, $countryCode)) {
            $code = $countryCode;
        } else {
            foreach ([3, 2, 1] as $length) {
                $candidate = substr($digits, 0, $length);
                if (in_array($candidate, CallingCodes::MAP, true)) {
                    $code = $candidate;
                    break;
                }
            }
        }
        if ($code === null) {
            return null;
        }
        $number = substr($digits, strlen($code));
        $codeCountry = $countryCode === $code ? $country : array_search($code, CallingCodes::MAP, true);
        // "+49 (0)30 ..." carries a trunk prefix that is not dialled internationally
        if (preg_match('/\(\s*0\s*\)/', $phone) || !in_array($codeCountry, CallingCodes::KEEP_TRUNK_PREFIX, true)) {
            $number = ltrim($number, '0');
        }
    } else {
        if ($countryCode === null) {
            return null;
        }
        $code = $countryCode;
        $number = in_array($country, CallingCodes::KEEP_TRUNK_PREFIX, true) ? $digits : ltrim($digits, '0');
        if ($code === '1' && strlen($number) === 11 && $number[0] === '1') {
            $number = substr($number, 1);
        }
    }

    if ($number === '' || strlen($number) > 14) {
        return null;
    }

    return '+' . $code . '.' . $number;
}

/**
 * Split an address line into street and house number
 * ("Musterstraße 12a" and "123 Main St").
 *
 * @return array{0: string, 1: string} [street, houseNumber]
 */
function domainresellerapi_splitStreet(string $address): array
{
    $address = trim((string) preg_replace('/\s+/u', ' ', $address));
    if ($address === '') {
        return ['', ''];
    }

    $number = '\d+\s?[a-zA-Z]?(?:\s?[-\/]\s?\d+\s?[a-zA-Z]?)?';

    if (preg_match('/^(.*?\D)[\s,]+(' . $number . ')$/u', $address, $m)) {
        return [rtrim($m[1], ' ,'), str_replace(' ', '', $m[2])];
    }
    if (preg_match('/^(' . $number . ')[\s,]+(.*\D.*)$/u', $address, $m)) {
        return [trim($m[2]), str_replace(' ', '', $m[1])];
    }

    return [$address, ''];
}

function domainresellerapi_limit(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

/**
 * Normalised contact (firstname, lastname, companyname, email, phone, address1,
 * address2, city, state, postcode, country) from WHMCS order params.
 */
function domainresellerapi_contactFromParams(array $params, string $prefix = ''): array
{
    $value = static fn (string $key): string => trim((string) ($params[$prefix . $key] ?? ''));

    return [
        'firstname' => $value('firstname'),
        'lastname' => $value('lastname'),
        'companyname' => $value('companyname'),
        'email' => $value('email'),
        'phone' => $value('fullphonenumber') !== '' ? $value('fullphonenumber') : $value('phonenumber'),
        'address1' => $value('address1'),
        'address2' => $value('address2'),
        'city' => $value('city'),
        'state' => $value('state'),
        'postcode' => $value('postcode'),
        'country' => $value('country'),
    ];
}

/**
 * Normalised contact from a WHMCS contact details block ("First Name", ...).
 */
function domainresellerapi_contactFromDetails(array $details): array
{
    $value = static fn (string $key): string => trim((string) ($details[$key] ?? ''));

    return [
        'firstname' => $value('First Name'),
        'lastname' => $value('Last Name'),
        'companyname' => $value('Company Name'),
        'email' => $value('Email Address'),
        'phone' => $value('Phone Number'),
        'address1' => $value('Address 1'),
        'address2' => $value('Address 2'),
        'city' => $value('City'),
        'state' => $value('State'),
        'postcode' => $value('Postcode'),
        'country' => $value('Country'),
    ];
}

/**
 * Build the payload for POST /api/v1/contacts from a normalised contact.
 *
 * type is person|organization (organization when a company is given), sex NA,
 * organization only for organisations, houseNumber only when one was found.
 *
 * @throws \InvalidArgumentException when a field the API requires is missing or invalid
 */
function domainresellerapi_buildContactPayload(array $contact): array
{
    $company = trim((string) ($contact['companyname'] ?? ''));
    $firstName = trim((string) ($contact['firstname'] ?? ''));
    $lastName = trim((string) ($contact['lastname'] ?? ''));
    $country = strtoupper(trim((string) ($contact['country'] ?? '')));
    if ($country === 'UK') {
        $country = 'GB';
    }
    [$street, $houseNumber] = domainresellerapi_splitStreet((string) ($contact['address1'] ?? ''));
    $phone = domainresellerapi_formatPhone((string) ($contact['phone'] ?? ''), $country);
    $email = trim((string) ($contact['email'] ?? ''));

    $payload = [
        'type' => $company !== '' ? 'organization' : 'person',
        'sex' => 'NA',
        'firstName' => domainresellerapi_limit($firstName !== '' ? $firstName : $company, 100),
        'lastName' => domainresellerapi_limit($lastName !== '' ? $lastName : $company, 100),
        'street' => domainresellerapi_limit($street, 255),
        'postalCode' => domainresellerapi_limit(trim((string) ($contact['postcode'] ?? '')), 20),
        'city' => domainresellerapi_limit(trim((string) ($contact['city'] ?? '')), 100),
        'country' => $country,
        'phone' => (string) $phone,
        'email' => $email,
    ];

    $invalid = [];
    foreach (['firstName', 'lastName', 'street', 'postalCode', 'city'] as $field) {
        if ($payload[$field] === '') {
            $invalid[] = $field;
        }
    }
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        $invalid[] = 'country';
    }
    if ($phone === null) {
        $invalid[] = 'phone (+CC.NUMBER)';
    }
    if (!preg_match(DOMAINRESELLERAPI_EMAIL_PATTERN, $email)) {
        $invalid[] = 'email';
    }
    if (!empty($invalid)) {
        throw new \InvalidArgumentException('Invalid contact data: ' . implode(', ', $invalid));
    }

    // Natural persons have no organisation
    if ($company !== '') {
        $payload['organization'] = domainresellerapi_limit($company, 255);
    }
    if ($houseNumber !== '') {
        $payload['houseNumber'] = domainresellerapi_limit($houseNumber, 20);
    }
    $address2 = trim((string) ($contact['address2'] ?? ''));
    if ($address2 !== '') {
        $payload['extension'] = domainresellerapi_limit($address2, 255);
    }
    $state = trim((string) ($contact['state'] ?? ''));
    if ($state !== '') {
        $payload['state'] = domainresellerapi_limit($state, 100);
    }

    return $payload;
}

/**
 * Create a contact handle for the WHMCS contact with the given prefix
 * ('' = registrant, 'admin', 'tech'). $extra is merged into the payload
 * (e.g. protection, pin).
 */
function domainresellerapi_getOrCreateContact(ApiClient $client, array $params, string $prefix = '', array $extra = []): string
{
    $payload = domainresellerapi_buildContactPayload(domainresellerapi_contactFromParams($params, $prefix)) + $extra;

    return domainresellerapi_createContact($client, $payload);
}

function domainresellerapi_createContact(ApiClient $client, array $payload): string
{
    $result = $client->createContact($payload);

    if (!empty($result['success']) && !empty($result['data']['handle'])) {
        return $result['data']['handle'];
    }

    throw new \Exception('Failed to create contact: ' . ($result['error'] ?? 'Unknown error'));
}

/**
 * Extra registrant fields: WHOIS protection (Vautron TLDs) and the nic.it RegCode
 * (contact field pin, taken from the .it additional field "Tax ID").
 */
function domainresellerapi_ownerExtra(array $params): array
{
    $extra = [];
    if (!empty($params['idprotection'])) {
        $extra['protection'] = true;
    }
    if (strtolower(ltrim((string) ($params['tld'] ?? ''), '.')) === 'it') {
        $fields = (array) ($params['additionalfields'] ?? []);
        $pin = trim((string) ($fields['Tax ID'] ?? $fields['RegCode'] ?? ''));
        if ($pin !== '') {
            $extra['pin'] = domainresellerapi_limit($pin, 36);
        }
    }
    return $extra;
}

/**
 * Create the registrant plus optional admin/tech contacts.
 *
 * @return array{owner: string, admin: ?string, tech: ?string, ownerType: string}
 */
function domainresellerapi_createOrderContacts(ApiClient $client, array $params, string $action): array
{
    $ownerPayload = domainresellerapi_buildContactPayload(domainresellerapi_contactFromParams($params))
        + domainresellerapi_ownerExtra($params);
    $handles = [
        'owner' => domainresellerapi_createContact($client, $ownerPayload),
        'admin' => null,
        'tech' => null,
        'ownerType' => $ownerPayload['type'],
    ];

    foreach (['admin', 'tech'] as $role) {
        if (empty($params[$role . 'firstname'])) {
            continue;
        }
        try {
            $handles[$role] = domainresellerapi_getOrCreateContact($client, $params, $role);
        } catch (\Exception $e) {
            // The API falls back to the owner contact for missing roles
            logModuleCall('domainresellerapi', $action . '-' . ucfirst($role) . 'Contact', ['domain' => domainresellerapi_getDomain($params)], $e->getMessage(), '', [$params['ApiKey'] ?? '']);
        }
    }

    return $handles;
}

/**
 * Error message of an API result.
 */
function domainresellerapi_error(array $result, string $default): string
{
    $error = $result['error'] ?? '';
    return is_string($error) && $error !== '' ? $error : $default;
}

/**
 * WHMCS renews domains when the renewal invoice is paid; the API's own auto-renewal
 * would renew (and charge) a second time. Disabled best-effort unless the
 * "ApiAutoRenew" option keeps it.
 */
function domainresellerapi_disableApiAutoRenew(ApiClient $client, array $params, string $domain): void
{
    if (!empty($params['ApiAutoRenew'])) {
        return;
    }
    $result = $client->updateDomain($domain, ['autoRenew' => false]);
    if (empty($result['success'])) {
        logModuleCall('domainresellerapi', 'DisableApiAutoRenew', ['domain' => $domain], $result, '', [$params['ApiKey'] ?? '']);
    }
}

function domainresellerapi_formatDate(?string $date): ?string
{
    if (empty($date)) {
        return null;
    }
    $time = strtotime($date);
    return $time === false ? null : gmdate('Y-m-d', $time);
}

/**
 * Map the domain state of the API (pending, pending_transfer, active, expired,
 * redemption, pending_delete, transferred_away, suspended, deleted) to WHMCS Sync values.
 */
function domainresellerapi_mapSyncStatus(array $data): array
{
    $values = [];
    $expiry = domainresellerapi_formatDate($data['expiresAt'] ?? null);
    if ($expiry !== null) {
        $values['expirydate'] = $expiry;
    }

    switch (strtolower((string) ($data['status'] ?? ''))) {
        case 'active':
        case 'pending_delete': // cancelled at the end of the term, still running
        case 'suspended':
            $values['active'] = true;
            $values['expired'] = false;
            $values['cancelled'] = false;
            $values['transferredAway'] = false;
            break;
        case 'expired':
        case 'redemption':
            $values['active'] = false;
            $values['expired'] = true;
            break;
        case 'deleted':
            $values['active'] = false;
            $values['cancelled'] = true;
            break;
        case 'transferred_away':
            $values['active'] = false;
            $values['transferredAway'] = true;
            break;
        default:
            // pending / pending_transfer: no status change yet
            break;
    }

    return $values;
}

/**
 * Comparable key of a DNS record (type, name, content, priority).
 */
function domainresellerapi_dnsKey(string $type, string $name, string $content, $priority): string
{
    $name = rtrim(strtolower(trim($name)), '.');
    if ($name === '') {
        $name = '@';
    }
    $priority = in_array(strtoupper($type), ['MX', 'SRV'], true) ? (string) (int) $priority : '';

    return strtoupper($type) . '|' . $name . '|' . trim($content) . '|' . $priority;
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
            'Value' => 'Register and manage domains via the Domain Reseller API by Wolfscastle (https://domain-reseller-api.de).',
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
        'ApiAutoRenew' => [
            'FriendlyName' => 'Keep API Auto-Renew',
            'Type' => 'yesno',
            'Description' => 'Leave the API\'s own auto-renewal enabled. Not recommended: WHMCS renews domains when the renewal invoice is paid, so the module disables the API auto-renewal to avoid double renewals.',
        ],
        'ConfirmOwnerChange' => [
            'FriendlyName' => 'Allow Owner Changes',
            'Type' => 'yesno',
            'Description' => 'Allow contact changes that require an owner change (e.g. switching the registrant between person and organisation). Owner changes may be charged.',
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
        $contacts = domainresellerapi_createOrderContacts($client, $params, 'RegisterDomain');

        // Must be a bookable term of the TLD (e.g. at least 2 years for .ai)
        $years = (int) ($params['regperiod'] ?? 0);

        $result = $client->registerDomain(
            $domain,
            $contacts['owner'],
            $contacts['admin'],
            $contacts['tech'],
            $nameservers,
            $years > 0 ? $years : null
        );

        logModuleCall('domainresellerapi', 'RegisterDomain', ['domain' => $domain, 'years' => $years, 'owner' => $contacts['owner']], $result, '', [$params['ApiKey'] ?? '']);

        if (empty($result['success'])) {
            return ['error' => domainresellerapi_error($result, 'Domain registration failed')];
        }

        domainresellerapi_disableApiAutoRenew($client, $params, $domain);

        // WHOIS privacy of OpenProvider TLDs (persons only); Vautron TLDs use the
        // contact protection flag set on the registrant above.
        if (!empty($params['idprotection']) && $contacts['ownerType'] === 'person') {
            $privacy = $client->setWhoisPrivacy($domain, true, true);
            if (empty($privacy['success'])) {
                logModuleCall('domainresellerapi', 'RegisterDomain-WhoisPrivacy', ['domain' => $domain], $privacy, '', [$params['ApiKey'] ?? '']);
            }
        }

        return ['success' => true];
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
        $authCode = trim((string) ($params['eppcode'] ?? $params['transfersecret'] ?? ''));

        if ($authCode === '') {
            return ['error' => 'An auth code (EPP code) is required for the transfer'];
        }

        $contacts = domainresellerapi_createOrderContacts($client, $params, 'TransferDomain');

        $result = $client->transferDomain($domain, $authCode, $contacts['owner'], $contacts['admin'], $contacts['tech']);

        logModuleCall('domainresellerapi', 'TransferDomain', ['domain' => $domain, 'owner' => $contacts['owner']], $result, '', [$params['ApiKey'] ?? '', $authCode]);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Domain transfer failed')];
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

        if (!empty($params['isInRedemptionGracePeriod'])) {
            $result = $client->restoreDomain($domain);
            $action = 'RenewDomain-Restore';
        } else {
            $years = (int) ($params['regperiod'] ?? 0);
            $result = $client->renewDomain($domain, $years > 0 ? $years : null);
            $action = 'RenewDomain';
        }

        logModuleCall('domainresellerapi', $action, ['domain' => $domain, 'regperiod' => $params['regperiod'] ?? null], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Domain renewal failed')];
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
        $result = $client->getDomain(domainresellerapi_getDomain($params));

        if (!empty($result['success']) && isset($result['data'])) {
            $values = [];
            $nameservers = array_values((array) ($result['data']['nameservers'] ?? []));
            for ($i = 0; $i < min(5, count($nameservers)); $i++) {
                $values['ns' . ($i + 1)] = $nameservers[$i];
            }
            return $values;
        }

        return ['error' => domainresellerapi_error($result, 'Failed to retrieve nameservers')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_SaveNameservers(array $params): array
{
    try {
        $nameservers = domainresellerapi_collectNameservers($params);

        if (count($nameservers) < 2) {
            return ['error' => 'At least 2 nameservers are required'];
        }
        foreach ($nameservers as $ns) {
            if (!preg_match(DOMAINRESELLERAPI_HOSTNAME_PATTERN, $ns)) {
                return ['error' => 'Invalid nameserver: ' . $ns];
            }
        }

        $client = domainresellerapi_getClient($params);
        $result = $client->updateDomain(domainresellerapi_getDomain($params), [
            'nameservers' => $nameservers,
        ]);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Failed to update nameservers')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Registrar (transfer) lock
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetRegistrarLock(array $params): string
{
    try {
        $client = domainresellerapi_getClient($params);
        $result = $client->getTransferLock(domainresellerapi_getDomain($params));

        // supported=false: the TLD has no transfer lock (e.g. .de)
        if (empty($result['success']) || empty($result['data']['supported']) || !isset($result['data']['locked'])) {
            return '';
        }

        return $result['data']['locked'] ? 'locked' : 'unlocked';
    } catch (\Exception $e) {
        return '';
    }
}

function domainresellerapi_SaveRegistrarLock(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $locked = ($params['lockenabled'] ?? '') === 'locked';
        $result = $client->setTransferLock(domainresellerapi_getDomain($params), $locked);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Failed to update the registrar lock')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// EPP Code / Auth Code
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetEPPCode(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        // GET /authcode fetches a code from the registry (.de and .eu only)
        $result = $client->getAuthCode($domain);
        $code = (string) ($result['data']['authCode'] ?? '');
        if (!empty($result['success']) && $code !== '' && $code !== DOMAINRESELLERAPI_NO_AUTHCODE) {
            return ['eppcode' => $code];
        }

        // Other TLDs: the auth code stored with the domain
        $info = $client->getDomain($domain);
        $stored = (string) ($info['data']['authCode'] ?? '');
        if (!empty($info['success']) && $stored !== '') {
            return ['eppcode' => $stored];
        }

        return ['error' => !empty($result['success']) ? 'The registry did not return an auth code' : domainresellerapi_error($result, 'Failed to retrieve auth code')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Glue records (child nameservers)
// ─────────────────────────────────────────────────────────────

/**
 * @return array{0: ?string, 1: ?string} [ipv4, ipv6]
 */
function domainresellerapi_splitIp(string $ip): array
{
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return [$ip, null];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return [null, $ip];
    }
    throw new \InvalidArgumentException('Invalid IP address: ' . $ip);
}

function domainresellerapi_RegisterNameserver(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        [$ipv4, $ipv6] = domainresellerapi_splitIp((string) ($params['ipaddress'] ?? ''));
        $result = $client->createHost(domainresellerapi_getDomain($params), strtolower(trim((string) ($params['nameserver'] ?? ''))), $ipv4, $ipv6);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Failed to register the nameserver')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_ModifyNameserver(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        [$ipv4, $ipv6] = domainresellerapi_splitIp((string) ($params['newipaddress'] ?? ''));
        $result = $client->updateHost(domainresellerapi_getDomain($params), strtolower(trim((string) ($params['nameserver'] ?? ''))), $ipv4, $ipv6);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Failed to modify the nameserver')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_DeleteNameserver(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $result = $client->deleteHost(domainresellerapi_getDomain($params), strtolower(trim((string) ($params['nameserver'] ?? ''))));

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Failed to delete the nameserver')];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// ID Protection (WHOIS privacy)
// ─────────────────────────────────────────────────────────────

function domainresellerapi_IDProtectToggle(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);
        $enable = !empty($params['protectenable']);

        // OpenProvider TLDs: Whois Privacy Protection (owner must be a person)
        $result = $client->setWhoisPrivacy($domain, $enable, $enable);
        if (!empty($result['success'])) {
            return ['success' => true];
        }

        // Vautron TLDs: WHOIS protection flag of the registrant contact
        if (($result['httpCode'] ?? 0) === 400 && stripos((string) ($result['error'] ?? ''), 'contact-level') !== false) {
            $info = $client->getDomain($domain);
            $owner = $info['data']['contacts']['owner'] ?? null;
            if (!empty($info['success']) && is_string($owner) && $owner !== '') {
                $update = $client->updateContact($owner, ['protection' => $enable]);
                if (!empty($update['success'])) {
                    return ['success' => true];
                }
                return ['error' => domainresellerapi_error($update, 'Failed to update WHOIS protection')];
            }
        }

        return ['error' => domainresellerapi_error($result, 'Failed to update WHOIS privacy')];
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
        $result = $client->getDnsRecords(domainresellerapi_getDomain($params));

        if (empty($result['success'])) {
            return ['error' => domainresellerapi_error($result, 'Failed to retrieve DNS records')];
        }

        $records = [];
        foreach ((array) ($result['data']['records'] ?? []) as $record) {
            $type = (string) ($record['type'] ?? '');
            $name = (string) ($record['name'] ?? '');
            // Apex NS records are managed by the API
            if ($type === 'SOA' || ($type === 'NS' && ($name === '' || $name === '@'))) {
                continue;
            }

            $hostRecord = [
                'hostname' => $name === '' ? '@' : $name,
                'type' => $type === 'Redirect' ? 'URL' : $type,
                'address' => (string) ($record['content'] ?? ''),
            ];
            if (isset($record['priority'])) {
                $hostRecord['priority'] = $record['priority'];
            }
            $records[] = $hostRecord;
        }

        return $records;
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Replace the zone with the submitted records: unchanged records are kept, new ones
 * created first, then obsolete ones deleted (existing records survive if every
 * creation fails). WHMCS URL records map to Redirect; FRAME and MXE are not supported.
 */
function domainresellerapi_SaveDNS(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $domainInfo = $client->getDomain($domain);
        if (!empty($domainInfo['success']) && empty($domainInfo['data']['useManagedDns'])) {
            $enabled = $client->enableManagedDns($domain);
            if (empty($enabled['success'])) {
                return ['error' => domainresellerapi_error($enabled, 'Failed to enable managed DNS')];
            }
        }

        $existing = $client->getDnsRecords($domain);
        if (empty($existing['success'])) {
            return ['error' => domainresellerapi_error($existing, 'Failed to retrieve DNS records')];
        }

        // Existing records by key (apex NS and SOA are managed by the API and never touched)
        $obsolete = [];
        foreach ((array) ($existing['data']['records'] ?? []) as $record) {
            $type = (string) ($record['type'] ?? '');
            $name = (string) ($record['name'] ?? '');
            if (empty($record['id']) || $type === 'SOA' || ($type === 'NS' && in_array($name, ['', '@', $domain], true))) {
                continue;
            }
            $key = domainresellerapi_dnsKey($type, $name, (string) ($record['content'] ?? ''), $record['priority'] ?? null);
            $obsolete[$key][] = (string) $record['id'];
        }

        $errors = [];
        $attempted = 0;
        $created = 0;
        foreach ((array) ($params['dnsrecords'] ?? []) as $record) {
            $type = strtoupper(trim((string) ($record['type'] ?? '')));
            $hostname = trim((string) ($record['hostname'] ?? ''));
            $address = trim((string) ($record['address'] ?? ''));
            if ($type === '' || $address === '') {
                continue;
            }
            if ($type === 'URL') {
                $type = 'Redirect';
            }
            if (in_array($type, ['FRAME', 'MXE'], true)) {
                $errors[] = sprintf('%s %s: record type not supported', $type, $hostname);
                continue;
            }
            $name = rtrim($hostname, '.');
            if ($name === '' || strtolower($name) === $domain) {
                $name = '@';
            }
            $priority = in_array($type, ['MX', 'SRV'], true) ? (int) ($record['priority'] ?? 10) : null;

            $key = domainresellerapi_dnsKey($type, $name, $address, $priority);
            if (!empty($obsolete[$key])) {
                // Unchanged: keep the existing record
                array_shift($obsolete[$key]);
                continue;
            }

            $attempted++;
            $result = $client->createDnsRecord($domain, $type, $name, $address, 3600, $priority);
            if (empty($result['success'])) {
                $errors[] = sprintf('%s %s: %s', $type, $name, domainresellerapi_error($result, 'failed'));
            } else {
                $created++;
            }
        }

        if ($attempted > 0 && $created === 0) {
            logModuleCall('domainresellerapi', 'SaveDNS', $params['dnsrecords'] ?? [], $errors, '', [$params['ApiKey'] ?? '']);
            return ['error' => 'All DNS record creations failed, existing records preserved: ' . implode('; ', $errors)];
        }

        foreach ($obsolete as $ids) {
            foreach ($ids as $id) {
                $result = $client->deleteDnsRecord($domain, $id);
                if (empty($result['success'])) {
                    $errors[] = sprintf('delete %s: %s', $id, domainresellerapi_error($result, 'failed'));
                }
            }
        }

        if (!empty($errors)) {
            logModuleCall('domainresellerapi', 'SaveDNS', $params['dnsrecords'] ?? [], $errors, '', [$params['ApiKey'] ?? '']);
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

/** WHMCS contact type => API role */
const DOMAINRESELLERAPI_CONTACT_ROLES = [
    'Registrant' => 'owner',
    'Admin' => 'admin',
    'Technical' => 'tech',
    'Billing' => 'billing',
];

function domainresellerapi_GetContactDetails(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $result = $client->getDomain(domainresellerapi_getDomain($params));

        if (empty($result['success']) || empty($result['data']['contacts'])) {
            return ['error' => domainresellerapi_error($result, 'Failed to retrieve domain info')];
        }

        $contacts = $result['data']['contacts'];
        $details = [];
        $cache = [];

        foreach (DOMAINRESELLERAPI_CONTACT_ROLES as $whmcsType => $role) {
            $handle = $contacts[$role] ?? null;
            if (!is_string($handle) || $handle === '') {
                continue;
            }

            if (!isset($cache[$handle])) {
                $contactResult = $client->getContact($handle);
                $cache[$handle] = !empty($contactResult['success']) ? (array) ($contactResult['data'] ?? []) : null;
            }
            $contact = $cache[$handle];
            if ($contact === null) {
                continue;
            }

            $street = trim((string) ($contact['street'] ?? '') . ' ' . (string) ($contact['houseNumber'] ?? ''));

            $details[$whmcsType] = [
                'First Name' => (string) ($contact['firstName'] ?? ''),
                'Last Name' => (string) ($contact['lastName'] ?? ''),
                // Persons have no organisation
                'Company Name' => ($contact['type'] ?? '') === 'person' ? '' : (string) ($contact['organization'] ?? ''),
                'Email Address' => (string) ($contact['email'] ?? ''),
                'Phone Number' => (string) ($contact['phone'] ?? ''),
                'Address 1' => $street,
                'Address 2' => (string) ($contact['extension'] ?? ''),
                'City' => (string) ($contact['city'] ?? ''),
                'State' => (string) ($contact['state'] ?? ''),
                'Postcode' => (string) ($contact['postalCode'] ?? ''),
                'Country' => (string) ($contact['country'] ?? ''),
            ];
        }

        return $details;
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Update the domain contacts.
 *
 * A handle is updated in place (PATCH /contacts/{handle}). A new contact is created
 * and assigned with PATCH /domains/{domain}/contacts when the handle is shared with
 * a role that got different data (the API assigns the owner to missing roles) or when
 * the contact type changes (person <-> organisation; the type of a handle is fixed).
 */
function domainresellerapi_SaveContactDetails(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $domainInfo = $client->getDomain($domain);
        if (empty($domainInfo['success'])) {
            return ['error' => domainresellerapi_error($domainInfo, 'Failed to retrieve domain info')];
        }

        $contacts = (array) ($domainInfo['data']['contacts'] ?? []);
        $contactDetails = (array) ($params['contactdetails'] ?? []);

        $errors = [];
        $updated = [];   // handle => payload applied in this request
        $types = [];     // handle => contact type
        $reassign = [];  // role => new handle

        foreach (DOMAINRESELLERAPI_CONTACT_ROLES as $whmcsType => $role) {
            if (empty($contactDetails[$whmcsType]) || !is_array($contactDetails[$whmcsType])) {
                continue;
            }
            $handle = $contacts[$role] ?? null;
            if (!is_string($handle) || $handle === '') {
                continue;
            }

            try {
                $payload = domainresellerapi_buildContactPayload(domainresellerapi_contactFromDetails($contactDetails[$whmcsType]));
            } catch (\InvalidArgumentException $e) {
                $errors[] = $whmcsType . ': ' . $e->getMessage();
                continue;
            }

            if (isset($updated[$handle])) {
                // Shared handle already updated in this request
                if ($updated[$handle] != $payload) {
                    $reassign[$role] = $payload;
                }
                continue;
            }

            if (!array_key_exists($handle, $types)) {
                $current = $client->getContact($handle);
                $types[$handle] = !empty($current['success']) ? ($current['data']['type'] ?? null) : null;
            }
            if ($types[$handle] !== null && $types[$handle] !== $payload['type']) {
                $reassign[$role] = $payload;
                continue;
            }

            // The type of a handle cannot be changed; empty values clear organisation and house number
            $update = $payload;
            unset($update['type'], $update['sex']);
            $update += ['organization' => '', 'houseNumber' => ''];

            $result = $client->updateContact($handle, $update);
            if (empty($result['success'])) {
                $errors[] = sprintf('%s contact (%s): %s', $whmcsType, $handle, domainresellerapi_error($result, 'update failed'));
                continue;
            }
            $updated[$handle] = $payload;
        }

        if (!empty($reassign)) {
            $handles = [];
            foreach ($reassign as $role => $payload) {
                $handles[$role] = domainresellerapi_createContact($client, $payload);
            }
            $result = $client->updateDomainContacts($domain, $handles, !empty($params['ConfirmOwnerChange']));
            logModuleCall('domainresellerapi', 'SaveContactDetails-Assign', ['domain' => $domain, 'contacts' => $handles], $result, '', [$params['ApiKey'] ?? '']);
            if (empty($result['success'])) {
                $message = domainresellerapi_error($result, 'contact assignment failed');
                if (($result['httpCode'] ?? 0) === 409 && isset($result['ownerChange'])) {
                    $message = sprintf(
                        'This change requires an owner change (%s %s). Enable "Allow Owner Changes" in the module settings to perform it.',
                        number_format((float) ($result['ownerChange']['price'] ?? 0), 2),
                        $result['ownerChange']['currency'] ?? 'EUR'
                    );
                }
                $errors[] = $message;
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

/**
 * Per-year premium cost from a check result (price/renewPrice are totals for `years`).
 */
function domainresellerapi_premiumPricing(array $data): array
{
    $years = max(1, (int) ($data['years'] ?? 1));
    $setup = (float) ($data['setupPrice'] ?? 0);

    return [
        'register' => round((float) ($data['price'] ?? 0) / $years + $setup, 2),
        'renew' => round((float) ($data['renewPrice'] ?? $data['price'] ?? 0) / $years, 2),
        'CurrencyCode' => $data['currency'] ?? 'EUR',
    ];
}

function domainresellerapi_CheckAvailability(array $params): LookupResultsList
{
    $results = new LookupResultsList();

    try {
        $client = domainresellerapi_getClient($params);
        $sld = strtolower(trim((string) (!empty($params['isIdnDomain']) && !empty($params['punyCodeSearchTerm'])
            ? $params['punyCodeSearchTerm']
            : ($params['searchTerm'] ?? $params['sld'] ?? ''))));
        $tlds = [];
        foreach ((array) ($params['tldsToInclude'] ?? $params['tlds'] ?? []) as $tld) {
            $tld = strtolower(ltrim(trim((string) $tld), '.'));
            if ($tld !== '' && !in_array($tld, $tlds, true)) {
                $tlds[] = $tld;
            }
        }
        if ($sld === '' || empty($tlds)) {
            return $results;
        }

        // Bulk check: up to 50 domains per request
        $checked = [];
        foreach (array_chunk($tlds, 50) as $chunk) {
            $result = $client->checkDomains(array_map(static fn ($tld) => $sld . '.' . $tld, $chunk));
            if (!empty($result['success'])) {
                foreach ((array) ($result['data']['results'] ?? []) as $row) {
                    if (isset($row['domain'])) {
                        $checked[strtolower((string) $row['domain'])] = $row;
                    }
                }
            }
        }

        foreach ($tlds as $tld) {
            $searchResult = new SearchResult($sld, $tld);
            $data = $checked[$sld . '.' . $tld] ?? null;

            if ($data === null || isset($data['error']) || !isset($data['available'])) {
                $searchResult->setStatus(SearchResult::STATUS_UNKNOWN);
            } elseif (!$data['available']) {
                $searchResult->setStatus(SearchResult::STATUS_REGISTERED);
            } elseif (!empty($data['premium'])) {
                if (empty($params['premiumEnabled']) || ($data['price'] ?? null) === null) {
                    $searchResult->setStatus(SearchResult::STATUS_RESERVED);
                } else {
                    $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
                    $searchResult->setPremiumDomain(true);
                    $searchResult->setPremiumCostPricing(domainresellerapi_premiumPricing($data));
                }
            } else {
                $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
            }

            $results->append($searchResult);
        }
    } catch (\Exception $e) {
        // Return the results collected so far
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────
// Domain Suggestions
// ─────────────────────────────────────────────────────────────

function domainresellerapi_GetDomainSuggestions(array $params): LookupResultsList
{
    $results = new LookupResultsList();

    try {
        $client = domainresellerapi_getClient($params);
        $sld = (string) (!empty($params['isIdnDomain']) && !empty($params['punyCodeSearchTerm'])
            ? $params['punyCodeSearchTerm']
            : ($params['searchTerm'] ?? $params['sld'] ?? ''));
        $tlds = array_values(array_filter(array_map(
            static fn ($tld) => strtolower(ltrim(trim((string) $tld), '.')),
            (array) ($params['tldsToInclude'] ?? $params['tlds'] ?? [])
        )));

        $result = $client->suggestDomains($sld, $tlds);

        if (!empty($result['success'])) {
            foreach ((array) ($result['data']['suggestions'] ?? []) as $suggestion) {
                $sugDomain = strtolower((string) ($suggestion['domain'] ?? ''));
                $parts = explode('.', $sugDomain, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                // Only TLDs offered in WHMCS
                if (!empty($tlds) && !in_array($parts[1], $tlds, true)) {
                    continue;
                }

                $searchResult = new SearchResult($parts[0], $parts[1]);
                $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
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

/**
 * Import the TLD pricing (net EUR). registerPrice/renewPrice are per year at the
 * minimum term (minYears, e.g. 2 for .ai), transferPrice is the total of a transfer
 * (transferYears). The setup fee (e.g. .de) is charged on registration and transfer.
 */
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
        if (isset($pricingData['tld'])) {
            $pricingData = [$pricingData];
        }

        foreach ((array) $pricingData as $tldInfo) {
            $tld = ltrim((string) ($tldInfo['tld'] ?? ''), '.');
            if ($tld === '') {
                continue;
            }

            $setup = (float) ($tldInfo['setupPrice'] ?? 0);
            $minYears = max(1, (int) ($tldInfo['minYears'] ?? 1));

            $item = new ImportItem();
            $item->setExtension($tld);
            $item->setMinYears($minYears);
            $item->setMaxYears(max($minYears, (int) ($tldInfo['maxYears'] ?? 10)));
            $item->setRegisterPrice(round((float) ($tldInfo['registerPrice'] ?? 0) + $setup, 2));
            $item->setRenewPrice((float) ($tldInfo['renewPrice'] ?? 0));
            $item->setTransferPrice(round((float) ($tldInfo['transferPrice'] ?? 0) + $setup, 2));

            if (!empty($tldInfo['restorePrice'])) {
                $item->setRedemptionFeeDays(30);
                $item->setRedemptionFeePrice((float) $tldInfo['restorePrice']);
            }

            $item->setCurrency((string) ($result['currency'] ?? 'EUR'));
            // Transfers always require an auth code (incl. .de AuthInfo)
            $item->setEppRequired(true);

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
            return ['error' => domainresellerapi_error($result, 'Failed to retrieve domain info')];
        }

        if (!empty($result['data']['autoRenew']) && ($result['data']['status'] ?? '') === 'active') {
            domainresellerapi_disableApiAutoRenew($client, $params, $domain);
        }

        return domainresellerapi_mapSyncStatus($result['data']);
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
            if (($result['httpCode'] ?? 0) === 404) {
                return ['failed' => true, 'reason' => 'Domain not found at the Domain Reseller API'];
            }
            return ['error' => domainresellerapi_error($result, 'Failed to retrieve domain info')];
        }

        $data = $result['data'];
        $status = strtolower((string) ($data['status'] ?? ''));

        switch ($status) {
            case 'active':
                $values = ['completed' => true];
                $expiry = domainresellerapi_formatDate($data['expiresAt'] ?? null);
                if ($expiry !== null) {
                    $values['expirydate'] = $expiry;
                }
                if (!empty($data['autoRenew'])) {
                    domainresellerapi_disableApiAutoRenew($client, $params, $domain);
                }
                return $values;

            case 'deleted':
                // Failed or cancelled transfers end in the state "deleted"
                return ['failed' => true, 'reason' => 'Transfer failed or was cancelled'];

            default:
                // pending / pending_transfer: still in progress
                return [];
        }
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────
// Domain Deletion
// ─────────────────────────────────────────────────────────────

/**
 * Cancels the domain at the end of its current term.
 */
function domainresellerapi_RequestDelete(array $params): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $result = $client->deleteDomain(domainresellerapi_getDomain($params));

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, 'Failed to delete domain')];
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

/**
 * Run a simple domain action and log it.
 */
function domainresellerapi_simpleAction(array $params, string $method, string $action, string $default): array
{
    try {
        $client = domainresellerapi_getClient($params);
        $domain = domainresellerapi_getDomain($params);

        $result = $client->{$method}($domain);

        logModuleCall('domainresellerapi', $action, ['domain' => $domain], $result, '', [$params['ApiKey'] ?? '']);

        if (!empty($result['success'])) {
            return ['success' => true];
        }

        return ['error' => domainresellerapi_error($result, $default)];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function domainresellerapi_RestoreDomain(array $params): array
{
    return domainresellerapi_simpleAction($params, 'restoreDomain', 'RestoreDomain', 'Failed to restore domain');
}

function domainresellerapi_CancelDeletion(array $params): array
{
    return domainresellerapi_simpleAction($params, 'cancelDelete', 'CancelDeletion', 'Failed to cancel deletion');
}

function domainresellerapi_ResetAuthCode(array $params): array
{
    return domainresellerapi_simpleAction($params, 'resetAuthCode', 'ResetAuthCode', 'Failed to reset auth code');
}

function domainresellerapi_EnableManagedDns(array $params): array
{
    return domainresellerapi_simpleAction($params, 'enableManagedDns', 'EnableManagedDns', 'Failed to enable managed DNS');
}

function domainresellerapi_DisableManagedDns(array $params): array
{
    return domainresellerapi_simpleAction($params, 'disableManagedDns', 'DisableManagedDns', 'Failed to disable managed DNS');
}

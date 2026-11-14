<?php
/**
 * Orders and registers domains through WHMCS (AddOrder + AcceptOrder -> RegisterDomain):
 * .com with ID protection + DNS management, .ai for its 2-year minimum term, .it with the
 * "Tax ID" additional field, .de. Checks the parameters WHMCS passed and the API requests.
 *
 * Writes the registered domain names to /shared/e2e_domains.json for the following scripts.
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
$domains = [
    'com' => "e2e-{$suffix}.com",
    'ai' => "e2e-{$suffix}.ai",
    'it' => "e2e-{$suffix}.it",
    'de' => "e2e-{$suffix}.de",
];
$clientId = clientId();

/**
 * Additional domain fields for AddOrder: base64(serialize([field name => value])).
 */
function domainFields(array $fields): string
{
    return base64_encode(serialize($fields));
}

function orderAndAccept(int $clientId, array $order): array
{
    $values = ['clientid' => $clientId, 'paymentmethod' => 'banktransfer', 'noemail' => true] + $order;
    $r = api('AddOrder', $values);
    if (($r['result'] ?? '') !== 'success') {
        return ['add' => $r];
    }
    $accept = api('AcceptOrder', ['orderid' => $r['orderid'], 'registrar' => 'domainresellerapi', 'sendregistrar' => true, 'sendemail' => false]);
    return ['add' => $r, 'accept' => $accept];
}

section('Register: .com with ID protection and DNS management');
$mark = logMark();
$r = orderAndAccept($clientId, [
    'domain' => [$domains['com']],
    'domaintype' => ['register'],
    'regperiod' => [1],
    'idprotection' => [true],
    'dnsmanagement' => [true],
    'nameserver1' => 'ns1.example-dns.net',
    'nameserver2' => 'ns2.example-dns.net',
]);
ok('AddOrder + AcceptOrder succeed', ($r['accept']['result'] ?? '') === 'success', $r);
$domain = Capsule::table('tbldomains')->where('domain', $domains['com'])->first();
ok('WHMCS domain status Active', ($domain->status ?? '') === 'Active', $domain->status ?? null);
$params = paramsSince($mark, 'RegisterDomain');
ok('RegisterDomain was called', $params !== null);
if ($params) {
    ok('param idprotection present and true', array_key_exists('idprotection', $params) && (bool) $params['idprotection'] === true, ['idprotection' => $params['idprotection'] ?? '(missing)']);
    ok('param dnsmanagement present', array_key_exists('dnsmanagement', $params), $params['dnsmanagement'] ?? '(missing)');
    ok('params regperiod=1, sld/tld split', (int) $params['regperiod'] === 1 && $params['sld'] === "e2e-{$suffix}" && $params['tld'] === 'com', [$params['regperiod'], $params['sld'], $params['tld']]);
    ok('nameservers ns1/ns2 passed', ($params['ns1'] ?? '') === 'ns1.example-dns.net' && ($params['ns2'] ?? '') === 'ns2.example-dns.net', [$params['ns1'] ?? null, $params['ns2'] ?? null]);
    info('phone params: phonenumber=' . json_encode($params['phonenumber'] ?? null) . ' fullphonenumber=' . json_encode($params['fullphonenumber'] ?? null) . ' phonecc=' . json_encode($params['phonecc'] ?? null));
    info('admin contact params present: ' . (isset($params['adminfirstname']) ? 'yes' : 'no') . '; additionalfields: ' . json_encode($params['additionalfields'] ?? null));
}
$requests = requestsSince($mark, 'RegisterDomain');
$contactCalls = array_values(array_filter($requests, fn ($q) => $q['method'] === 'POST' && $q['endpoint'] === '/api/v1/contacts/'));
$owner = $contactCalls[0]['body'] ?? [];
ok('owner contact created (person, +49.301234567, street/house number split, protection)', ($owner['type'] ?? '') === 'person' && ($owner['phone'] ?? '') === '+49.301234567'
    && ($owner['street'] ?? '') === 'Musterstraße' && ($owner['houseNumber'] ?? '') === '12a' && ($owner['protection'] ?? false) === true && ($contactCalls[0]['success'] ?? false), $contactCalls[0] ?? $requests);
$register = array_values(array_filter($requests, fn ($q) => $q['endpoint'] === '/api/v1/domains/register'))[0] ?? null;
ok('POST /domains/register succeeded with years=1 and nameservers', ($register['success'] ?? false) && ($register['body']['years'] ?? null) === 1 && ($register['body']['nameservers'] ?? []) === ['ns1.example-dns.net', 'ns2.example-dns.net'], $register);
$autoRenew = array_values(array_filter($requests, fn ($q) => $q['method'] === 'PATCH' && ($q['body']['autoRenew'] ?? null) === false))[0] ?? null;
ok('API auto-renew disabled after registration', ($autoRenew['success'] ?? false) === true, $autoRenew);
$privacy = array_values(array_filter($requests, fn ($q) => str_ends_with($q['endpoint'], '/whois-privacy')))[0] ?? null;
info('whois-privacy request: ' . json_encode($privacy ? ['status' => $privacy['status'], 'body' => $privacy['body'], 'error' => $privacy['error']] : null, JSON_UNESCAPED_SLASHES));
$backend = backendDomain($domains['com']);
ok('domain exists at the API (sandbox), status active, autoRenew off', ($backend['data']['status'] ?? '') === 'active' && empty($backend['data']['autoRenew']), $backend['data'] ?? $backend);

section('Register: .ai (2-year minimum term)');
$mark = logMark();
$r = orderAndAccept($clientId, ['domain' => [$domains['ai']], 'domaintype' => ['register'], 'regperiod' => [2]]);
ok('order accepted', ($r['accept']['result'] ?? '') === 'success', $r);
$register = array_values(array_filter(requestsSince($mark, 'RegisterDomain'), fn ($q) => $q['endpoint'] === '/api/v1/domains/register'))[0] ?? null;
ok('POST /domains/register with years=2 succeeded', ($register['success'] ?? false) && ($register['body']['years'] ?? null) === 2, $register);
$backend = backendDomain($domains['ai']);
$expiry = $backend['data']['expiresAt'] ?? null;
ok('API expiry about 2 years ahead', $expiry && abs(strtotime($expiry) - strtotime('+2 years')) < 3 * 86400, $expiry);
$order = Capsule::table('tblorders')->where('id', $r['add']['orderid'] ?? 0)->value('amount');
info('WHMCS order amount for .ai (2 years, incl. 20% margin): ' . $order);

section('Register: .it with "Tax ID" (nic.it RegCode -> contact pin)');
$mark = logMark();
$r = orderAndAccept($clientId, [
    'domain' => [$domains['it']],
    'domaintype' => ['register'],
    'regperiod' => [1],
    'domainfields' => [domainFields(['Legal Type' => 'Italian and foreign natural persons', 'Tax ID' => 'MSTRKE80A41Z112X', 'Publish Personal Data' => 'on',
        'Accept Section 3 of .IT registrar contract' => 'on', 'Accept Section 5 of .IT registrar contract' => 'on', 'Accept Section 6 of .IT registrar contract' => 'on', 'Accept Section 7 of .IT registrar contract' => 'on'])],
]);
ok('order accepted', ($r['accept']['result'] ?? '') === 'success', $r);
$stored = Capsule::table('tbldomainsadditionalfields')->where('domainid', domainId($domains['it']))->pluck('value', 'name')->all();
info('stored additional fields: ' . json_encode($stored, JSON_UNESCAPED_UNICODE));
$params = paramsSince($mark, 'RegisterDomain');
ok('param additionalfields["Tax ID"] passed to the module', ($params['additionalfields']['Tax ID'] ?? null) === 'MSTRKE80A41Z112X', $params['additionalfields'] ?? '(no params)');
$owner = array_values(array_filter(requestsSince($mark, 'RegisterDomain'), fn ($q) => $q['endpoint'] === '/api/v1/contacts/'))[0] ?? null;
ok('owner contact sent with pin = Tax ID', ($owner['body']['pin'] ?? null) === 'MSTRKE80A41Z112X' && ($owner['success'] ?? false), $owner);
ok('WHMCS domain Active', Capsule::table('tbldomains')->where('domain', $domains['it'])->value('status') === 'Active', Capsule::table('tbldomains')->where('domain', $domains['it'])->value('status'));

section('Register: .de');
$mark = logMark();
$r = orderAndAccept($clientId, ['domain' => [$domains['de']], 'domaintype' => ['register'], 'regperiod' => [1], 'dnsmanagement' => [true],
    'domainfields' => [domainFields(['Tax ID' => '', 'Address Confirmation' => 'on', 'Agree to DE Terms' => 'on'])]]);
ok('order accepted', ($r['accept']['result'] ?? '') === 'success', $r);
ok('WHMCS domain Active', Capsule::table('tbldomains')->where('domain', $domains['de'])->value('status') === 'Active', [Capsule::table('tbldomains')->where('domain', $domains['de'])->value('status'), requestsSince($mark)]);

file_put_contents('/shared/e2e_domains.json', json_encode($domains));
info('domains: ' . implode(', ', $domains));
done();

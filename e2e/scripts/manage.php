<?php
/**
 * Domain management through WHMCS (localAPI where WHMCS offers it, otherwise the internal
 * RegCallFunction): nameservers, transfer lock, EPP code, WHOIS contacts, ID protection,
 * child nameservers, DNS, renewal, status sync and the admin custom buttons.
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

$domains = json_decode((string) @file_get_contents('/shared/e2e_domains.json'), true) ?: [];
if (!$domains) {
    ok('domains from register.php available', false);
    done();
}
$com = $domains['com'];
$de = $domains['de'];
$comId = domainId($com);
$deId = domainId($de);

section("Nameservers ($com)");
$r = api('DomainGetNameservers', ['domainid' => $comId]);
ok('DomainGetNameservers returns the nameservers from the API', !empty($r['ns1']) && !empty($r['ns2']) && $r['ns1'] === (backendDomain($com)['data']['nameservers'][0] ?? null), $r);
$r = api('DomainUpdateNameservers', ['domainid' => $comId, 'ns1' => 'NS1.Example-DNS.org.', 'ns2' => 'ns2.example-dns.org', 'ns3' => 'ns3.example-dns.org']);
ok('DomainUpdateNameservers succeeds', ($r['result'] ?? '') === 'success', $r);
$r = api('DomainGetNameservers', ['domainid' => $comId]);
ok('nameservers saved (normalised, 3 entries)', ($r['ns1'] ?? '') === 'ns1.example-dns.org' && ($r['ns3'] ?? '') === 'ns3.example-dns.org', $r);
$r = api('DomainUpdateNameservers', ['domainid' => $comId, 'ns1' => 'ns1.example-dns.org', 'ns2' => '']);
ok('a single nameserver is rejected (WHMCS: "ns1 and ns2 required")', ($r['result'] ?? '') === 'error', $r);
$mark = logMark();
$r = registrarCall($comId, 'SaveNameservers', ['ns1' => 'ns1.example-dns.org', 'ns2' => 'not a hostname']);
ok('invalid hostname rejected before any API call', !empty($r['error']) && !requestsSince($mark), $r);

section('Transfer lock');
// The sandbox answers GET /lock with supported=false for every domain (no upstream registry),
// so WHMCS shows "Unknown" there; PUT /lock is accepted.
$mark = logMark();
$r = api('DomainGetLockingStatus', ['domainid' => $comId]);
$get = requestsSince($mark, 'GetRegistrarLock')[0] ?? null;
ok('.com: GET /lock called; supported=false (sandbox) maps to "Unknown"', ($get['success'] ?? false) && ($r['lockstatus'] ?? '') === (($get['response']['supported'] ?? false) ? (($get['response']['locked'] ?? false) ? 'locked' : 'unlocked') : 'Unknown'), [$r, $get]);
$mark = logMark();
$r = api('DomainUpdateLockingStatus', ['domainid' => $comId, 'lockstatus' => false]);
$put = requestsSince($mark, 'SaveRegistrarLock')[0] ?? null;
ok('.com: unlock -> PUT /lock {locked:false}', ($r['result'] ?? '') === 'success' && ($put['body'] ?? null) === ['locked' => false], [$r, $put]);
$mark = logMark();
$r = api('DomainUpdateLockingStatus', ['domainid' => $comId, 'lockstatus' => true]);
$put = requestsSince($mark, 'SaveRegistrarLock')[0] ?? null;
ok('.com: lock -> PUT /lock {locked:true}', ($r['result'] ?? '') === 'success' && ($put['body'] ?? null) === ['locked' => true], [$r, $put]);
$r = api('DomainGetLockingStatus', ['domainid' => $deId]);
info('.de lock status (no transfer lock at DENIC): ' . json_encode($r));

section('EPP / auth code');
$r = api('DomainRequestEPP', ['domainid' => $deId]);
info('.de: ' . json_encode($r));
ok('.de: EPP code returned', ($r['result'] ?? '') === 'success' && !empty($r['eppcode']), $r);
$r = api('DomainRequestEPP', ['domainid' => $comId]);
info('.com: ' . json_encode($r));
// The API hands out auth codes only for .de/.eu or a code stored with the domain (transfers)
ok('.com (registered, no stored code): API error passed through', ($r['result'] ?? '') === 'error' && str_contains(json_encode($r), '.de and .eu'), $r);

section("WHOIS contacts ($com)");
$r = api('DomainGetWhoisInfo', ['domainid' => $comId]);
ok('DomainGetWhoisInfo returns Registrant/Admin/Technical/Billing', isset($r['Registrant']['First_Name'], $r['Admin'], $r['Technical'], $r['Billing']), $r);
info('Registrant: ' . json_encode($r['Registrant'] ?? null, JSON_UNESCAPED_UNICODE));
ok('Registrant street + house number joined, phone +CC.NUMBER', ($r['Registrant']['Phone_Number'] ?? '') === '+49.301234567' && str_ends_with((string) ($r['Registrant']['Address_1'] ?? ''), ' 12a'), $r['Registrant'] ?? null);

section('ID protection');
$mark = logMark();
$r = api('DomainToggleIdProtect', ['domainid' => $comId, 'idprotect' => false]);
ok('.com: disable ID protection', ($r['result'] ?? '') === 'success', [$r, requestsSince($mark)]);
$p = paramsSince($mark, 'IDProtectToggle');
ok('param protectenable passed (false)', $p !== null && array_key_exists('protectenable', $p) && !$p['protectenable'], $p['protectenable'] ?? '(missing)');
$contactPatch = array_values(array_filter(requestsSince($mark), fn ($q) => $q['method'] === 'PATCH' && str_starts_with($q['endpoint'], '/api/v1/contacts/')))[0] ?? null;
ok('Vautron TLD: contact protection flag cleared', ($contactPatch['body']['protection'] ?? null) === false && ($contactPatch['success'] ?? false), requestsSince($mark));
$r = api('DomainToggleIdProtect', ['domainid' => $comId, 'idprotect' => true]);
ok('.com: enable ID protection', ($r['result'] ?? '') === 'success', $r);
$aiId = domainId($domains['ai']);
$mark = logMark();
$r = api('DomainToggleIdProtect', ['domainid' => $aiId, 'idprotect' => true]);
$wpp = array_values(array_filter(requestsSince($mark), fn ($q) => str_ends_with($q['endpoint'], '/whois-privacy')))[0] ?? null;
ok('.ai (OpenProvider): WHOIS privacy enabled via /whois-privacy', ($r['result'] ?? '') === 'success' && ($wpp['success'] ?? false), [$r, $wpp]);

section("Child nameservers / glue ($com)");
$host = 'ns1.' . $com;
$r = registrarCall($comId, 'RegisterNameserver', ['nameserver' => $host, 'ipaddress' => '192.0.2.10']);
ok('RegisterNameserver', ($r['success'] ?? false) === true, $r);
$r = registrarCall($comId, 'ModifyNameserver', ['nameserver' => $host, 'currentipaddress' => '192.0.2.10', 'newipaddress' => '2001:db8::10']);
ok('ModifyNameserver (to IPv6)', ($r['success'] ?? false) === true, $r);
$hosts = backendClient()->listHosts($com);
info('hosts at the API (sandbox always lists none): ' . json_encode($hosts['data'] ?? $hosts));
$r = registrarCall($comId, 'DeleteNameserver', ['nameserver' => $host]);
ok('DeleteNameserver', ($r['success'] ?? false) === true, $r);

section("DNS ($de)");
$r = registrarCall($deId, 'SaveDNS', ['dnsrecords' => [
    ['hostname' => '@', 'type' => 'A', 'address' => '192.0.2.1'],
    ['hostname' => 'www', 'type' => 'CNAME', 'address' => $de . '.'],
    ['hostname' => '@', 'type' => 'MX', 'address' => 'mail.' . $de, 'priority' => '10'],
    ['hostname' => '@', 'type' => 'TXT', 'address' => 'v=spf1 mx -all'],
    ['hostname' => 'go', 'type' => 'URL', 'address' => 'https://www.example.org/'],
]]);
ok('SaveDNS with A/CNAME/MX/TXT/URL', ($r['success'] ?? false) === true, $r);
$r = registrarCall($deId, 'GetDNS');
info('GetDNS: ' . json_encode($r, JSON_UNESCAPED_SLASHES));
$types = array_map(fn ($x) => ($x['type'] ?? '') . ' ' . ($x['hostname'] ?? ''), array_filter($r, 'is_array'));
ok('GetDNS returns the records, Redirect shown as URL', in_array('A @', $types, true) && in_array('MX @', $types, true) && in_array('URL go', $types, true), $types);

section("Renewal ($com)");
$before = backendDomain($com)['data']['expiresAt'] ?? null;
$r = api('DomainRenew', ['domainid' => $comId, 'regperiod' => 1]);
ok('DomainRenew succeeds', ($r['result'] ?? '') === 'success', $r);
$after = backendDomain($com)['data']['expiresAt'] ?? null;
ok('API expiry moved by 1 year', $before && $after && abs(strtotime($after) - strtotime('+1 year', strtotime($before))) < 2 * 86400, [$before, $after]);

section("Renewal by paying the renewal invoice ({$domains['ai']}, 2-year term)");
$aiId = domainId($domains['ai']);
$aiBefore = backendDomain($domains['ai'])['data']['expiresAt'] ?? null;
$mark = logMark();
$order = api('AddOrder', ['clientid' => clientId(), 'paymentmethod' => 'banktransfer', 'noemail' => true, 'domainrenewals' => [$domains['ai'] => 2]]);
ok('renewal order created', ($order['result'] ?? '') === 'success', $order);
$invoiceTotal = Capsule::table('tblinvoices')->where('id', $order['invoiceid'] ?? 0)->value('total');
info('renewal invoice total: ' . $invoiceTotal);
$pay = api('AddInvoicePayment', ['invoiceid' => $order['invoiceid'] ?? 0, 'transid' => 'e2e-' . time(), 'gateway' => 'banktransfer', 'amount' => $invoiceTotal]);
ok('invoice paid', ($pay['result'] ?? '') === 'success', $pay);
$renew = array_values(array_filter(requestsSince($mark, 'RenewDomain'), fn ($q) => str_ends_with($q['endpoint'], '/renew')))[0] ?? null;
ok('payment triggered POST /renew with years=2', ($renew['success'] ?? false) && ($renew['body']['years'] ?? null) === 2, [$renew, logSince($mark, null, 'params') ? array_column(logSince($mark, null, 'params'), 'caller') : []]);
$aiAfter = backendDomain($domains['ai'])['data']['expiresAt'] ?? null;
ok('API expiry moved by 2 years', $aiBefore && $aiAfter && abs(strtotime($aiAfter) - strtotime('+2 years', strtotime($aiBefore))) < 2 * 86400, [$aiBefore, $aiAfter]);

section('Status sync (cron DomainStatusSync)');
Capsule::table('tbldomains')->where('id', $comId)->update(['expirydate' => '2000-01-01', 'nextduedate' => '2000-01-01']);
Capsule::table('tbldomains')->whereIn('id', [$comId])->update(['synced' => 0]);
$mark = logMark();
$out = shell_exec('cd /var/www/html && php -q crons/cron.php do --DomainStatusSync -vvv 2>&1');
info('Sync calls: ' . count(logSince($mark, 'Sync', 'params')));
info('cron: ' . trim(preg_replace('/\s+/', ' ', (string) $out)));
$row = Capsule::table('tbldomains')->find($comId);
$expected = gmdate('Y-m-d', strtotime($after));
ok('expiry date synced from the API', $row->expirydate === $expected, ['whmcs' => $row->expirydate, 'api' => $expected]);
info('next due date: ' . $row->nextduedate . ', status: ' . $row->status);

section('Admin custom buttons');
foreach (['ResetAuthCode' => $deId, 'DisableManagedDns' => $deId, 'EnableManagedDns' => $deId] as $fn => $id) {
    $r = registrarCall($id, $fn);
    ok($fn, ($r['success'] ?? false) === true, $r);
}
done();

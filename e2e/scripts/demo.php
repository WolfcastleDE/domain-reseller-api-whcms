<?php
/**
 * Demo data for the marketplace screenshots: musterfirma.de with a few DNS records.
 * Writes the domain id to /shared/demo_domain.json.
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

$domain = 'musterfirma.de';
section("Demo domain $domain");
if (!domainId($domain)) {
    $r = api('AddOrder', ['clientid' => clientId(), 'paymentmethod' => 'banktransfer', 'noemail' => true, 'domain' => [$domain], 'domaintype' => ['register'],
        'regperiod' => [1], 'dnsmanagement' => [true], 'idprotection' => [true],
        'domainfields' => [base64_encode(serialize(['Address Confirmation' => 'on', 'Agree to DE Terms' => 'on']))]]);
    $a = api('AcceptOrder', ['orderid' => $r['orderid'] ?? 0, 'registrar' => 'domainresellerapi', 'sendregistrar' => true, 'sendemail' => false]);
    ok('demo domain registered', ($a['result'] ?? '') === 'success', [$r, $a]);
}
$id = domainId($domain);
Capsule::table('tbldomains')->where('id', $id)->update(['dnsmanagement' => 1, 'idprotection' => 1]);
$r = registrarCall($id, 'SaveDNS', ['dnsrecords' => [
    ['hostname' => '@', 'type' => 'A', 'address' => '203.0.113.10'],
    ['hostname' => '@', 'type' => 'AAAA', 'address' => '2001:db8:10::1'],
    ['hostname' => 'www', 'type' => 'CNAME', 'address' => 'musterfirma.de.'],
    ['hostname' => '@', 'type' => 'MX', 'address' => 'mail.musterfirma.de', 'priority' => '10'],
    ['hostname' => '@', 'type' => 'TXT', 'address' => 'v=spf1 mx -all'],
    ['hostname' => 'shop', 'type' => 'URL', 'address' => 'https://shop.example.com/'],
]]);
ok('demo DNS records saved', ($r['success'] ?? false) === true, $r);
file_put_contents('/shared/demo_domain.json', json_encode(['domain' => $domain, 'id' => $id]));
done();

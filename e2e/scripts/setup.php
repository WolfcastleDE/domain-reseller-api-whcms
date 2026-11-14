<?php
/**
 * Environment setup that is not part of the module test itself: EUR as default currency
 * (the API prices in EUR), Bank Transfer as payment gateway, a test client, domain sync on.
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

section('WHMCS environment setup');

// Default currency EUR (TLD sync needs the API currency to exist)
Capsule::table('tblcurrencies')->where('default', 1)->update(['code' => 'EUR', 'prefix' => '', 'suffix' => ' €', 'format' => 3, 'rate' => 1]);
ok('default currency is EUR', Capsule::table('tblcurrencies')->where('default', 1)->value('code') === 'EUR');

// Bank transfer gateway
if (!Capsule::table('tblpaymentgateways')->where('gateway', 'banktransfer')->exists()) {
    foreach ([['name', 'Bank Transfer', 1], ['type', 'Invoices', 0], ['visible', 'on', 0], ['instructions', 'E2E bank transfer', 0]] as [$setting, $value, $order]) {
        Capsule::table('tblpaymentgateways')->insert(['gateway' => 'banktransfer', 'setting' => $setting, 'value' => $value, 'order' => $order]);
    }
}
ok('payment gateway banktransfer active', Capsule::table('tblpaymentgateways')->where('gateway', 'banktransfer')->exists());

setting('CompanyName', 'Example Hosting GmbH');
setting('Email', 'billing@example.test');
setting('DomainSyncEnabled', 'on');
setting('DomainSyncNotifyOnly', '');
setting('AllowIDNDomains', 'on');
setting('Language', 'english');
setting('CaptchaSetting', '');  // no captcha in the domain checker / cart

// Test client (German address so street/house number splitting and phone formatting are exercised)
if (!clientId()) {
    $r = api('AddClient', [
        'firstname' => 'Erika',
        'lastname' => 'Mustermann',
        'email' => E2E_CLIENT_EMAIL,
        'address1' => 'Musterstraße 12a',
        'city' => 'Berlin',
        'state' => 'Berlin',
        'postcode' => '10115',
        'country' => 'DE',
        'phonenumber' => '030 1234567',
        'password2' => 'e2e-password-123',
        'skipvalidation' => true,
        'noemail' => true,
    ]);
    ok('test client created', ($r['result'] ?? '') === 'success', $r);
}
ok('test client exists', clientId() > 0);

// After the TLD sync: offer DNS management, ID protection and EPP codes for the imported TLDs
// (WHMCS imports TLDs with these addons switched off)
$imported = Capsule::table('tbldomainpricing')->where('autoreg', 'domainresellerapi')->count();
if ($imported > 0) {
    Capsule::table('tbldomainpricing')->where('autoreg', 'domainresellerapi')->update(['dnsmanagement' => 1, 'idprotection' => 1, 'eppcode' => 1]);
    // Addon prices 0.00 so the test orders stay cheap
    setting('DomainDNSManagement', '0.00');
    setting('DomainIDProtection', '0.00');
    ok("DNS management + ID protection enabled for {$imported} imported TLDs", true);
}
done();

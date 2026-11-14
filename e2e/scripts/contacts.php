<?php
/**
 * WHOIS contact changes that need an owner change (registrant person -> organisation):
 * refused with a clear message while "Allow Owner Changes" is off, executed when it is on.
 * Also a role-only change (admin contact differs from the registrant).
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

$domains = json_decode((string) @file_get_contents('/shared/e2e_domains.json'), true) ?: [];
$com = $domains['com'] ?? null;
if (!$com) {
    ok('domains from register.php available', false);
    done();
}
$id = domainId($com);

function setOwnerChange(bool $on): void
{
    $value = $on ? 'on' : '';
    $row = Capsule::table('tblregistrars')->where('registrar', 'domainresellerapi')->where('setting', 'ConfirmOwnerChange')->first();
    $encrypted = encrypt($value);
    if ($row) {
        Capsule::table('tblregistrars')->where('id', $row->id)->update(['value' => $encrypted]);
    } else {
        Capsule::table('tblregistrars')->insert(['registrar' => 'domainresellerapi', 'setting' => 'ConfirmOwnerChange', 'value' => $encrypted]);
    }
}

$current = api('DomainGetWhoisInfo', ['domainid' => $id]);
$person = [];
foreach (($current['Registrant'] ?? []) as $k => $v) {
    $person[str_replace('_', ' ', $k)] = $v;
}
$organisation = array_merge($person, ['Company Name' => 'Beispiel Hosting GmbH']);
$details = fn (array $registrant, ?array $admin = null) => ['Registrant' => $registrant, 'Admin' => $admin ?? $registrant, 'Technical' => $registrant, 'Billing' => $registrant];

section("Admin contact differs from the registrant ($com)");
$mark = logMark();
$admin = array_merge($person, ['First Name' => 'Max', 'Last Name' => 'Admin', 'Email Address' => 'admin-contact@e2e.test']);
$r = registrarCall($id, 'SaveContactDetails', ['contactdetails' => $details($person, $admin)]);
ok('SaveContactDetails succeeds', ($r['success'] ?? false) === true, [$r, requestsSince($mark)]);
$after = api('DomainGetWhoisInfo', ['domainid' => $id]);
ok('admin contact now differs, registrant unchanged', ($after['Admin']['Last_Name'] ?? '') === 'Admin' && ($after['Registrant']['Last_Name'] ?? '') === ($person['Last Name'] ?? ''), [$after['Admin'] ?? null, $after['Registrant'] ?? null]);

section('Owner change without permission');
setOwnerChange(false);
$mark = logMark();
$r = registrarCall($id, 'SaveContactDetails', ['contactdetails' => $details($organisation, $admin)]);
$assign = array_values(array_filter(requestsSince($mark), fn ($q) => str_ends_with($q['endpoint'], '/contacts') && $q['method'] === 'PATCH'))[0] ?? null;
info('PATCH /domains/{d}/contacts: ' . json_encode($assign ? ['status' => $assign['status'], 'body' => $assign['body'], 'error' => $assign['error']] : null));
ok('refused with a hint to "Allow Owner Changes"', str_contains((string) ($r['error'] ?? ''), 'Allow Owner Changes'), $r);
$created = array_column(array_filter(requestsSince($mark), fn ($q) => $q['method'] === 'POST' && $q['endpoint'] === '/api/v1/contacts/'), 'response');
$deleted = array_filter(requestsSince($mark), fn ($q) => $q['method'] === 'DELETE' && str_starts_with($q['endpoint'], '/api/v1/contacts/') && $q['success']);
ok('contacts created for the refused change are deleted again', count($created) > 0 && count($deleted) === count(array_unique(array_column($created, 'handle'))), ['created' => array_column($created, 'handle'), 'deleted' => array_column($deleted, 'endpoint')]);
$after = api('DomainGetWhoisInfo', ['domainid' => $id]);
ok('registrant still a person', ($after['Registrant']['Company_Name'] ?? 'x') === '', $after['Registrant'] ?? null);

section('Owner change with "Allow Owner Changes"');
setOwnerChange(true);
$mark = logMark();
$r = registrarCall($id, 'SaveContactDetails', ['contactdetails' => $details($organisation, $admin)]);
$p = paramsSince($mark, 'SaveContactDetails');
ok('module receives ConfirmOwnerChange', !empty($p['ConfirmOwnerChange']), $p['ConfirmOwnerChange'] ?? '(missing)');
ok('owner change executed', ($r['success'] ?? false) === true, [$r, requestsSince($mark)]);
$after = api('DomainGetWhoisInfo', ['domainid' => $id]);
ok('registrant is now the organisation', ($after['Registrant']['Company_Name'] ?? '') === 'Beispiel Hosting GmbH', $after['Registrant'] ?? null);
setOwnerChange(false);
done();

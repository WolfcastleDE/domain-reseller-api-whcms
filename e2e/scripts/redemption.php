<?php
/**
 * Expiry/redemption: status sync of an expired domain, restore through a WHMCS renewal in the
 * redemption period (RenewDomain with isInRedemptionGracePeriod), the admin buttons
 * "Restore Domain" / "Cancel Deletion" and RequestDelete.
 *
 * The sandbox has no registry that lets domains expire, so the backend state is set in its
 * test database (expired / redemption).
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

function backendDb(): PDO
{
    return new PDO('mysql:host=db;dbname=domain_reseller;charset=utf8mb4', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$domains = json_decode((string) @file_get_contents('/shared/e2e_domains.json'), true) ?: [];
$it = $domains['it'] ?? null;
if (!$it) {
    ok('domains from register.php available', false);
    done();
}
$id = domainId($it);
$expired = gmdate('Y-m-d H:i:s', strtotime('-10 days'));

section("Status sync of an expired domain ($it)");
backendDb()->prepare("UPDATE domains SET status='redemption', expires_at=? WHERE name=? AND is_sandbox=1")->execute([$expired, $it]);
$mark = logMark();
Capsule::table('tbldomains')->where('id', $id)->update(['synced' => 0]);
shell_exec('cd /var/www/html && php -q crons/cron.php do --DomainStatusSync 2>&1');
$row = Capsule::table('tbldomains')->find($id);
ok('Sync called', count(logSince($mark, 'Sync', 'params')) >= 1);
info('WHMCS after sync: status=' . $row->status . ' expiry=' . $row->expirydate);
ok('WHMCS expiry date = API expiry', $row->expirydate === substr($expired, 0, 10), $row->expirydate);
// WHMCS changes the status itself from the (synced) expiry date in its UpdateDomainExpiryStatus task
shell_exec('cd /var/www/html && php -q crons/cron.php do --UpdateDomainExpiryStatus 2>&1');
$row = Capsule::table('tbldomains')->find($id);
ok('after UpdateDomainExpiryStatus: status Grace/Redemption/Expired', in_array($row->status, ['Expired', 'Redemption', 'Grace'], true), $row->status);
info('WHMCS status: ' . $row->status);

section('Restore via renewal in the redemption period');
Capsule::table('tbldomains')->where('id', $id)->update(['status' => 'Redemption']);
$mark = logMark();
$r = api('DomainRenew', ['domainid' => $id, 'regperiod' => 1]);
$params = paramsSince($mark, 'RenewDomain');
info('RenewDomain isInRedemptionGracePeriod=' . json_encode($params['isInRedemptionGracePeriod'] ?? '(missing)') . ' isInGracePeriod=' . json_encode($params['isInGracePeriod'] ?? '(missing)'));
$restore = array_values(array_filter(requestsSince($mark, 'RenewDomain'), fn ($q) => str_ends_with($q['endpoint'], '/restore')))[0] ?? null;
ok('DomainRenew in redemption -> POST /restore succeeded', ($r['result'] ?? '') === 'success' && ($restore['success'] ?? false), [$r, requestsSince($mark)]);
$api = backendDomain($it);
ok('API status active again', ($api['data']['status'] ?? '') === 'active', $api['data']['status'] ?? $api);

section('Admin button "Restore Domain" (redemption)');
backendDb()->prepare("UPDATE domains SET status='redemption', expires_at=? WHERE name=? AND is_sandbox=1")->execute([$expired, $it]);
$r = registrarCall($id, 'RestoreDomain');
ok('RestoreDomain succeeds', ($r['success'] ?? false) === true, $r);

section("RequestDelete + Cancel Deletion ($it)");
$mark = logMark();
$r = registrarCall($id, 'RequestDelete');
ok('RequestDelete (cancel at end of term) succeeds', ($r['success'] ?? false) === true, [$r, requestsSince($mark)]);
$api = backendDomain($it);
info('API status after RequestDelete: ' . ($api['data']['status'] ?? json_encode($api)));
$r = registrarCall($id, 'CancelDeletion');
ok('CancelDeletion succeeds', ($r['success'] ?? false) === true, $r);
$api = backendDomain($it);
ok('API status active after Cancel Deletion', ($api['data']['status'] ?? '') === 'active', $api['data']['status'] ?? $api);
done();

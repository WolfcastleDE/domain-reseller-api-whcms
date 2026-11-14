<?php
/**
 * Transfer with auth code (AddOrder + AcceptOrder -> TransferDomain) and transfer sync
 * (cron DomainTransferSync -> TransferSync), completed and failed.
 *
 * The sandbox leaves transfers in pending_transfer forever (there is no registry), so the
 * registry outcome is simulated by updating the domain row in the backend's test database.
 */
require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

function backendDb(): PDO
{
    return new PDO('mysql:host=db;dbname=domain_reseller;charset=utf8mb4', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function transferOrder(string $domain, string $eppcode): array
{
    $r = api('AddOrder', ['clientid' => clientId(), 'paymentmethod' => 'banktransfer', 'noemail' => true,
        'domain' => [$domain], 'domaintype' => ['transfer'], 'regperiod' => [1], 'eppcode' => [$eppcode]]);
    if (($r['result'] ?? '') !== 'success') {
        return ['add' => $r];
    }
    return ['add' => $r, 'accept' => api('AcceptOrder', ['orderid' => $r['orderid'], 'registrar' => 'domainresellerapi', 'sendregistrar' => true, 'sendemail' => false])];
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
$ok = "e2e-transfer-{$suffix}.com";
$fail = "e2e-transfer-fail-{$suffix}.net";

section("Transfer with auth code ($ok)");
$mark = logMark();
$r = transferOrder($ok, 'Auth#Code-123');
ok('AddOrder + AcceptOrder (transfer) succeed', ($r['accept']['result'] ?? '') === 'success', $r);
$params = paramsSince($mark, 'TransferDomain');
ok('TransferDomain received the EPP code (param eppcode)', ($params['eppcode'] ?? null) === 'Auth#Code-123', $params['eppcode'] ?? $params);
$transfer = array_values(array_filter(requestsSince($mark, 'TransferDomain'), fn ($q) => $q['endpoint'] === '/api/v1/domains/transfer'))[0] ?? null;
ok('POST /domains/transfer with authCode + owner handle succeeded', ($transfer['success'] ?? false) && ($transfer['body']['authCode'] ?? '') === 'Auth#Code-123' && !empty($transfer['body']['contacts']['owner']), $transfer);
$status = Capsule::table('tbldomains')->where('domain', $ok)->value('status');
ok('WHMCS status Pending Transfer', $status === 'Pending Transfer', $status);
$api = backendDomain($ok);
ok('API status pending_transfer', ($api['data']['status'] ?? '') === 'pending_transfer', $api['data'] ?? $api);

section('Transfer without auth code');
$r = registrarCall(domainId($ok), 'TransferDomain', ['eppcode' => '', 'transfersecret' => '']);
ok('module refuses a transfer without auth code', str_contains((string) ($r['error'] ?? ''), 'auth code'), $r);

section('Transfer sync: still pending');
$mark = logMark();
shell_exec('cd /var/www/html && php -q crons/cron.php do --DomainTransferSync 2>&1');
ok('TransferSync called', count(logSince($mark, 'TransferSync', 'params')) >= 1);
ok('WHMCS status still Pending Transfer', Capsule::table('tbldomains')->where('domain', $ok)->value('status') === 'Pending Transfer');

section('Transfer sync: completed');
$expiry = gmdate('Y-m-d H:i:s', strtotime('+1 year +3 months'));
backendDb()->prepare("UPDATE domains SET status='active', expires_at=? WHERE name=? AND is_sandbox=1")->execute([$expiry, $ok]);
shell_exec('cd /var/www/html && php -q crons/cron.php do --DomainTransferSync 2>&1');
$row = Capsule::table('tbldomains')->where('domain', $ok)->first();
ok('WHMCS status Active after completed transfer', $row->status === 'Active', $row->status);
ok('expiry date taken from the API', $row->expirydate === substr($expiry, 0, 10), [$row->expirydate, $expiry]);

section("Transfer sync: failed ($fail)");
$r = transferOrder($fail, 'wrong-code');
ok('transfer order accepted', ($r['accept']['result'] ?? '') === 'success', $r);
// What the backend's failDomainTransfer() persists: state "deleted" is stored as pending_delete
backendDb()->prepare("UPDATE domains SET status='pending_delete' WHERE name=? AND is_sandbox=1")->execute([$fail]);
shell_exec('cd /var/www/html && php -q crons/cron.php do --DomainTransferSync 2>&1');
$status = Capsule::table('tbldomains')->where('domain', $fail)->value('status');
ok('WHMCS no longer shows the failed transfer as Pending Transfer', $status !== 'Pending Transfer', $status);
info('WHMCS status after failed transfer: ' . $status);

file_put_contents('/shared/e2e_transfer_domain', $ok);
done();

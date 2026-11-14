<?php
/**
 * Shared bootstrap for the CLI test scripts: loads WHMCS (init.php) and offers small helpers.
 * Run inside the whmcs container: php /e2e/scripts/<script>.php
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
chdir('/var/www/html');
require '/var/www/html/init.php';

use WHMCS\Database\Capsule;

const E2E_ADMIN = 'admin';
const E2E_CLIENT_EMAIL = 'kunde@e2e.test';
const E2E_LOG = '/shared/drapi-e2e.jsonl';

$GLOBALS['e2eFailures'] = 0;

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

function ok(string $label, bool $condition, $detail = null): bool
{
    echo ($condition ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $label;
    if (!$condition && $detail !== null) {
        echo ' :: ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES));
    }
    echo "\n";
    if (!$condition) {
        $GLOBALS['e2eFailures']++;
    }
    return $condition;
}

function info(string $text): void
{
    echo "       {$text}\n";
}

function api(string $command, array $values = []): array
{
    return localAPI($command, $values, E2E_ADMIN);
}

function done(): void
{
    exit($GLOBALS['e2eFailures'] > 0 ? 1 : 0);
}

/** Offset of the capture log, to read only entries written after it. */
function logMark(): int
{
    clearstatcache();
    return is_file(E2E_LOG) ? filesize(E2E_LOG) : 0;
}

/** Captured entries (params / request) since $mark, optionally filtered by caller. */
function logSince(int $mark, ?string $caller = null, ?string $type = null): array
{
    $fh = fopen(E2E_LOG, 'r');
    fseek($fh, $mark);
    $entries = [];
    while (($line = fgets($fh)) !== false) {
        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            continue;
        }
        if (($caller === null || $entry['caller'] === $caller) && ($type === null || $entry['type'] === $type)) {
            $entries[] = $entry;
        }
    }
    fclose($fh);
    return $entries;
}

function requestsSince(int $mark, ?string $caller = null): array
{
    return logSince($mark, $caller, 'request');
}

function paramsSince(int $mark, string $caller): ?array
{
    $entries = logSince($mark, $caller, 'params');
    return $entries ? end($entries)['params'] : null;
}

function clientId(): int
{
    return (int) Capsule::table('tblclients')->where('email', E2E_CLIENT_EMAIL)->value('id');
}

function domainId(string $domain): int
{
    return (int) Capsule::table('tbldomains')->where('domain', $domain)->orderByDesc('id')->value('id');
}

function setting(string $name, string $value): void
{
    \WHMCS\Config\Setting::setValue($name, $value);
}

/**
 * Call a registrar function the way WHMCS does internally (RegCallFunction builds the params
 * from the domain, the registrar settings and $extra).
 */
function registrarCall(int $domainId, string $function, array $extra = []): array
{
    require_once ROOTDIR . '/includes/registrarfunctions.php';
    $domain = \WHMCS\Database\Capsule::table('tbldomains')->find($domainId);
    [$sld, $tld] = explode('.', $domain->domain, 2);
    $params = array_merge(['domainid' => $domainId, 'sld' => $sld, 'tld' => $tld, 'regperiod' => $domain->registrationperiod, 'registrar' => $domain->registrar], $extra);
    $result = RegCallFunction($params, $function);
    return is_array($result) ? $result : ['raw' => $result];
}

/** API (backend) state of a domain, read with the module's own client and settings. */
function backendDomain(string $domain): array
{
    $settings = getRegistrarConfigOptions('domainresellerapi');
    $client = new \WHMCS\Module\Registrar\DomainResellerApi\ApiClient($settings['ApiKey'], $settings['ApiUrl'], !empty($settings['Sandbox']));
    return $client->getDomain($domain);
}

function backendClient(): \WHMCS\Module\Registrar\DomainResellerApi\ApiClient
{
    $settings = getRegistrarConfigOptions('domainresellerapi');
    return new \WHMCS\Module\Registrar\DomainResellerApi\ApiClient($settings['ApiKey'], $settings['ApiUrl'], !empty($settings['Sandbox']));
}

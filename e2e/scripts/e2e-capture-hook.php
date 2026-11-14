<?php

/**
 * E2E only: installed as includes/hooks/drapi_e2e_capture.php by whmcs-entrypoint.sh.
 *
 * Uses the module's test seam ApiClient::$factory to record, for every module call,
 * the parameters WHMCS passes to the module and every API request the module sends.
 * One JSON object per line in /shared/drapi-e2e.jsonl (the API key is masked).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$drapiLib = ROOTDIR . '/modules/registrars/domainresellerapi/lib/ApiClient.php';
if (!is_file($drapiLib)) {
    return;
}
require_once $drapiLib;

use WHMCS\Module\Registrar\DomainResellerApi\ApiClient;

if (!class_exists('DrapiE2eRecordingClient', false)) {
    class DrapiE2eRecordingClient extends ApiClient
    {
        public string $caller = '';

        public static function log(array $entry): void
        {
            $entry['time'] = date('c');
            $entry['pid'] = getmypid();
            @file_put_contents('/shared/drapi-e2e.jsonl', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }

        protected function request(string $method, string $endpoint, array $params = [], array $body = []): array
        {
            $result = parent::request($method, $endpoint, $params, $body);
            self::log([
                'type' => 'request',
                'caller' => $this->caller,
                'method' => $method,
                'endpoint' => $endpoint,
                'query' => $params,
                'body' => $body,
                'status' => $result['httpCode'] ?? null,
                'success' => $result['success'] ?? null,
                'error' => $result['error'] ?? null,
                'response' => $result['data'] ?? null,
            ]);
            return $result;
        }
    }
}

ApiClient::$factory = static function (array $params): ApiClient {
    // The registrar function that asked for the client
    $caller = '';
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
        if (isset($frame['function']) && preg_match('/^domainresellerapi_([A-Z]\w+)$/', $frame['function'], $m) && $m[1] !== 'getClient') {
            $caller = $m[1];
            break;
        }
    }
    $logged = $params;
    foreach (['ApiKey', 'password'] as $secret) {
        if (isset($logged[$secret])) {
            $logged[$secret] = '***';
        }
    }
    if (isset($logged['original']) && is_array($logged['original'])) {
        // Untransliterated values WHMCS keeps next to the converted ones: log only those that differ
        $logged['original'] = array_filter($logged['original'], static fn ($v, $k) => is_scalar($v) ? (string) $v !== (string) ($params[$k] ?? '') : $v != ($params[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        unset($logged['original']['ApiKey']);
    }
    DrapiE2eRecordingClient::log(['type' => 'params', 'caller' => $caller, 'params' => $logged]);

    $client = new DrapiE2eRecordingClient(
        (string) ($params['ApiKey'] ?? ''),
        !empty($params['ApiUrl']) ? (string) $params['ApiUrl'] : 'https://domain-reseller-api.de',
        !empty($params['Sandbox']),
        max(5, min(120, (int) ($params['Timeout'] ?? 30)))
    );
    $client->caller = $caller;
    return $client;
};

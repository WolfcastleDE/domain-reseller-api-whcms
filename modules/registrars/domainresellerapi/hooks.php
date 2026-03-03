<?php

/**
 * Domain Reseller API - WHMCS Hooks
 *
 * Handles incoming webhook notifications from the Domain Reseller API
 * and integrates with WHMCS domain lifecycle events.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Collect sensitive parameter keys that should be masked in logs.
 */
function domainresellerapi_getSensitiveKeys(): array
{
    return [
        'ApiKey', 'eppcode', 'transfersecret',
        'firstname', 'lastname', 'email', 'fullphonenumber', 'phonenumber',
        'address1', 'address2', 'city', 'postcode', 'state',
        'adminfirstname', 'adminlastname', 'adminemail', 'adminphonenumber', 'adminfullphonenumber',
        'adminaddress1', 'adminaddress2', 'admincity', 'adminpostcode', 'adminstate',
        'techfirstname', 'techlastname', 'techemail', 'techphonenumber', 'techfullphonenumber',
        'techaddress1', 'techaddress2', 'techcity', 'techpostcode', 'techstate',
    ];
}

/**
 * Mask sensitive fields in params before logging.
 */
function domainresellerapi_maskParams(array $params): array
{
    $sensitiveKeys = domainresellerapi_getSensitiveKeys();
    $masked = $params;
    foreach ($sensitiveKeys as $key) {
        if (isset($masked[$key]) && $masked[$key] !== '') {
            $masked[$key] = '***';
        }
    }
    return $masked;
}

/**
 * After a domain registration order is accepted, log API interaction.
 */
add_hook('AfterRegistrarRegistration', 1, function (array $vars) {
    if (($vars['params']['registrar'] ?? '') !== 'domainresellerapi') {
        return;
    }

    logModuleCall(
        'domainresellerapi',
        'AfterRegistrarRegistration',
        domainresellerapi_maskParams($vars['params']),
        $vars['results'] ?? [],
        '',
        [$vars['params']['ApiKey'] ?? '']
    );
});

/**
 * After a domain transfer order is accepted, log API interaction.
 */
add_hook('AfterRegistrarTransfer', 1, function (array $vars) {
    if (($vars['params']['registrar'] ?? '') !== 'domainresellerapi') {
        return;
    }

    logModuleCall(
        'domainresellerapi',
        'AfterRegistrarTransfer',
        domainresellerapi_maskParams($vars['params']),
        $vars['results'] ?? [],
        '',
        [$vars['params']['ApiKey'] ?? '']
    );
});

/**
 * After a domain renewal, log the API interaction.
 */
add_hook('AfterRegistrarRenewal', 1, function (array $vars) {
    if (($vars['params']['registrar'] ?? '') !== 'domainresellerapi') {
        return;
    }

    logModuleCall(
        'domainresellerapi',
        'AfterRegistrarRenewal',
        domainresellerapi_maskParams($vars['params']),
        $vars['results'] ?? [],
        '',
        [$vars['params']['ApiKey'] ?? '']
    );
});

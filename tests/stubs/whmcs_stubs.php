<?php

/**
 * WHMCS stub: global functions and WHMCS constant.
 */

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

if (!function_exists('logModuleCall')) {
    function logModuleCall($module, $action, $request, $response, $processedData = '', $replaceVars = []): void
    {
        // No-op in tests
    }
}

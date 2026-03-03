<?php

declare(strict_types=1);

// Load WHMCS stubs first (defines WHMCS constant + logModuleCall)
require_once __DIR__ . '/stubs/whmcs_stubs.php';

// Load WHMCS class stubs (each in their own namespace file)
require_once __DIR__ . '/stubs/ResultsList.php';
require_once __DIR__ . '/stubs/ImportItem.php';
require_once __DIR__ . '/stubs/Domain.php';
require_once __DIR__ . '/stubs/Capsule.php';

// Load composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the main module file (global functions)
require_once __DIR__ . '/../modules/registrars/domainresellerapi/domainresellerapi.php';

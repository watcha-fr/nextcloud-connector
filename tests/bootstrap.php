<?php

if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__.'/../../../lib/base.php';

// Fix for "Autoload path not allowed: .../tests/lib/testcase.php"
// Only present in a source checkout of the server. A production image ships no
// tests/ directory, and requiring it unconditionally made the whole suite
// unrunnable there — which is where these tests actually need to run.
if (is_dir(OC::$SERVERROOT . '/tests')) {
    \OC::$loader->addValidRoot(OC::$SERVERROOT . '/tests');
}

// Fix for "Autoload path not allowed: .../watcha/tests/testcase.php"
\OC_App::loadApp('watcha');

OC_Hook::clear();

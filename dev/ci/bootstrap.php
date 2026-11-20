<?php
/**
 * Magelan_AiVisibility - unit test bootstrap for the CI harness.
 *
 * The Magento mirror is 340 MB, and every module in this workspace needs the same one. This
 * harness therefore uses its own vendor directory when it has one (which is what CI installs) and
 * otherwise borrows the sibling module's, so a developer who already has one module set up can run
 * these tests without a second download. Nothing of the module itself comes from there: the classes
 * under test are registered below, from this working copy.
 */
declare(strict_types=1);

error_reporting(E_ALL);

// UTC on purpose: a test that cares about time says so, and a harness in the developer's own
// timezone hides exactly the bugs those tests exist to catch.
date_default_timezone_set('UTC');

$candidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../../AdventCalendar/dev/ci/vendor/autoload.php',
];

$loader = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        break;
    }
}

if ($loader === null) {
    fwrite(STDERR, "No vendor directory. Run composer install in dev/ci.\n");
    exit(1);
}

// The module under test, from this working copy rather than from whatever the mirror may hold.
$loader->addPsr4('Magelan\\AiVisibility\\', dirname(__DIR__, 2) . '/');

// Magento's translation function, normally loaded by app/functions.php.
if (!function_exists('__')) {
    require_once dirname(realpath($candidate)) . '/magento/framework/Phrase/__.php';
}

// Magento generates <Class>Factory at runtime; without an installation, declare a minimal one so it
// can be mocked. The base class is only located, not loaded: loading it would compile Magento core
// files the tests never use.
spl_autoload_register(static function (string $class) use ($loader): void {
    if (!str_ends_with($class, 'Factory') || $loader->findFile(substr($class, 0, -7)) === false) {
        return;
    }

    $separator = (int)strrpos($class, '\\');
    eval(sprintf(
        'namespace %s; class %s { public function create(array $data = []) { return null; } }',
        substr($class, 0, $separator),
        substr($class, $separator + 1)
    ));
});

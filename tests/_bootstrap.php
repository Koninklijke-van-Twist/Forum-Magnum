<?php

declare(strict_types=1);

/**
 * Gedeelde testopzet: tijdelijke datamap, libs laden zonder login, mini-asserts.
 * Draaien: for f in tests/*_test.php; do php "$f" || exit 1; done
 */

$tmp = sys_get_temp_dir() . '/fm-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($tmp, 0777, true);
putenv('FM_DATA_DIR=' . $tmp);
register_shutdown_function(static function () use ($tmp): void {
    exec('rm -rf ' . escapeshellarg($tmp));
});

$web = dirname(__DIR__) . '/web/lib';
require_once $web . '/i18n.php';
require_once $web . '/store.php';
require_once $web . '/attachments.php';
require_once $web . '/users.php';
require_once $web . '/mentions.php';
require_once $web . '/markdown.php';
require_once $web . '/layout.php';

$GLOBALS['fmTestCount'] = 0;
$GLOBALS['fmTestFailures'] = 0;

function check(bool $cond, string $message): void
{
    $GLOBALS['fmTestCount']++;
    if (!$cond) {
        $GLOBALS['fmTestFailures']++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function check_same(mixed $expected, mixed $actual, string $message): void
{
    check($expected === $actual, $message . ' (verwacht ' . var_export($expected, true) . ', kreeg ' . var_export($actual, true) . ')');
}

function check_throws(callable $fn, string $class, string $message): void
{
    try {
        $fn();
        check(false, $message . ' (geen exception)');
    } catch (Throwable $e) {
        check($e instanceof $class, $message . ' (' . get_class($e) . ': ' . $e->getMessage() . ')');
    }
}

function finish(string $name): void
{
    $n = $GLOBALS['fmTestCount'];
    $f = $GLOBALS['fmTestFailures'];
    if ($f > 0) {
        fwrite(STDERR, "$name: $f van $n checks mislukt\n");
        exit(1);
    }
    echo "$name: $n checks OK\n";
}

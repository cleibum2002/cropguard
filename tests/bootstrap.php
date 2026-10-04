<?php
// tests/bootstrap.php
// A tiny, dependency-free test runner for CropGuard (plain PHP has no built-in
// test tool, unlike Laravel's `php artisan test`). Shared by tests/unit.php and
// tests/integration.php. Prints the familiar PASS / ✓ layout so the results can
// be screenshotted for Appendix M.
//
// Run from the project folder, e.g.:
//   D:\Xampp\php\php.exe tests\unit.php
//   D:\Xampp\php\php.exe tests\integration.php

// Safety lock 1: terminal only. This folder is deployed along with the rest of
// the repo, so without this anyone could run the tests by opening the URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Safety lock 2: never on the live server. The integration tests create and
// delete their own test accounts, which must only ever happen locally.
if (getenv('RAILWAY_ENVIRONMENT')) {
    fwrite(STDERR, "Refusing to run tests on Railway.\n");
    exit(1);
}

error_reporting(E_ALL);
// Keeps safeErrorMessage()'s error_log() line out of the screenshot.
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cropguard_tests.log');
ini_set('display_errors', '1');

if (function_exists('sapi_windows_vt100_support')) {
    @sapi_windows_vt100_support(STDOUT, true); // enables colours in Windows terminals
}

define('CG_ROOT', dirname(__DIR__));

final class TestRun
{
    private static array $tests = [];
    private static int $assertions = 0;

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = [$name, $fn];
    }

    public static function countAssertion(): void
    {
        self::$assertions++;
    }

    public static function run(string $suite): void
    {
        $start   = microtime(true);
        $results = [];
        foreach (self::$tests as [$name, $fn]) {
            $t = microtime(true);
            try {
                $fn();
                $results[] = [$name, true, null, microtime(true) - $t];
            } catch (Throwable $e) {
                $results[] = [$name, false, $e->getMessage(), microtime(true) - $t];
            }
        }
        $total  = microtime(true) - $start;
        $failed = count(array_filter($results, fn($r) => !$r[1]));
        $passed = count($results) - $failed;

        $badge = $failed ? "\033[41;97;1m FAIL \033[0m" : "\033[42;30;1m PASS \033[0m";
        echo "\n  $badge \033[1m$suite\033[0m\n";
        foreach ($results as [$name, $ok, $msg, $secs]) {
            $mark = $ok ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
            $time = sprintf('%.2fs', $secs);
            echo '  ' . $mark . ' ' . str_pad($name, 66) . "\033[90m" . str_pad($time, 7, ' ', STR_PAD_LEFT) . "\033[0m\n";
            if (!$ok) {
                echo "      \033[31m→ $msg\033[0m\n";
            }
        }

        $summary = "\033[32;1m$passed passed\033[0m";
        if ($failed) {
            $summary = "\033[31;1m$failed failed\033[0m, " . $summary;
        }
        echo "\n  \033[90mTests:\033[0m    $summary \033[90m(" . self::$assertions . " assertions)\033[0m\n";
        echo "  \033[90mDuration:\033[0m " . sprintf('%.2fs', $total) . "\n\n";

        exit($failed ? 1 : 0);
    }
}

function test(string $name, callable $fn): void
{
    TestRun::add($name, $fn);
}

// ── Assertions ─────────────────────────────────────────────────────────────
function assertSame($expected, $actual, string $label = ''): void
{
    TestRun::countAssertion();
    if ($expected !== $actual) {
        throw new Exception(($label ? "$label: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue($value, string $label = ''): void
{
    TestRun::countAssertion();
    if ($value !== true) {
        throw new Exception(($label ? "$label: " : '') . 'expected true, got ' . var_export($value, true));
    }
}

function assertFalse($value, string $label = ''): void
{
    TestRun::countAssertion();
    if ($value !== false) {
        throw new Exception(($label ? "$label: " : '') . 'expected false, got ' . var_export($value, true));
    }
}

function assertNull($value, string $label = ''): void
{
    TestRun::countAssertion();
    if ($value !== null) {
        throw new Exception(($label ? "$label: " : '') . 'expected no error, got ' . var_export($value, true));
    }
}

function assertNotNull($value, string $label = ''): void
{
    TestRun::countAssertion();
    if ($value === null) {
        throw new Exception(($label ? "$label: " : '') . 'expected a rejection, but it was accepted');
    }
}

function assertContains(string $needle, string $haystack, string $label = ''): void
{
    TestRun::countAssertion();
    if (stripos($haystack, $needle) === false) {
        throw new Exception(($label ? "$label: " : '') . "expected to find \"$needle\" in \"$haystack\"");
    }
}

// ── Loading the real functions ─────────────────────────────────────────────
// Most of CropGuard's helper functions live inside endpoint files, which run
// their request-handling code (database, JSON output) as soon as they're
// included. So instead of including them, this reads the file, cuts out just
// the one function's source text, and loads that. The test therefore checks
// the exact code that's deployed, without changing any production file.
function loadFunctionFrom(string $relativeFile, string $functionName): void
{
    if (function_exists($functionName)) {
        return;
    }
    $path = CG_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
    $tokens = token_get_all(file_get_contents($path));
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        // Next meaningful token must be the function's name.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $functionName) {
            continue;
        }
        // Collect source up to the brace that closes the function body.
        $source = '';
        $depth  = 0;
        $opened = false;
        for ($k = $i; $k < $count; $k++) {
            $tok = $tokens[$k];
            $text = is_array($tok) ? $tok[1] : $tok;
            $source .= $text;
            if ($text === '{' || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($text === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    eval($source);
                    return;
                }
            }
        }
    }
    throw new Exception("Could not find function $functionName() in $relativeFile");
}

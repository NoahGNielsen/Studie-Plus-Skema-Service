<?php

declare(strict_types=1);

/*
 * Minimal test-runner uden afhængigheder:  php tests/run.php
 * Bruger kun mockdata — kontakter aldrig Studie+ og kræver ingen database.
 * Påkrævede udvidelser: sodium, mbstring, json.
 */

require dirname(__DIR__) . '/src/app/bootstrap.php';
require __DIR__ . '/support/InMemoryStore.php';
require __DIR__ . '/support/FakeScheduleSource.php';

final class AssertionFailed extends RuntimeException
{
}

/** @var array<string, Closure> $GLOBALS['__tests'] */
$GLOBALS['__tests'] = [];

function test(string $name, Closure $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'forventede ' . var_export($expected, true) . ', fik ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = 'forventede true'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "'$needle' blev ikke fundet");
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "'$needle' burde ikke forekomme");
    }
}

/** @param class-string<Throwable> $class */
function assertThrows(string $class, Closure $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("forventede $class, fik " . $e::class);
    }
    throw new AssertionFailed("forventede $class, men intet blev kastet");
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FEJL $name\n       " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

echo "\n$passed bestået, $failed fejlet\n";
exit($failed === 0 ? 0 : 1);

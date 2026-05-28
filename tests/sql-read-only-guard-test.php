<?php

declare(strict_types=1);

/**
 * Standalone test for SqlReadOnlyGuard — run with: php tests/sql-read-only-guard-test.php
 *
 * No PHPUnit/WordPress required; the guard is pure PHP. Covers the security
 * rules and the {prefix}/LIMIT handling behind the wp_db_query tool.
 */

require __DIR__ . '/../src/Helpers/SqlReadOnlyGuard.php';

use WpMcp\Helpers\SqlReadOnlyGuard;

$prefix = 'zz_';
$passed = 0;
$failed = 0;

function check(string $label, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

/** Assert the guard accepts a statement and returns the expected effective SQL. */
function expectAllow(string $sql, string $expectedEffective, string $label): void
{
    global $prefix;
    try {
        $result = SqlReadOnlyGuard::prepare($sql, $prefix);
        check($label, $result['sql'] === $expectedEffective);
        if ($result['sql'] !== $expectedEffective) {
            echo "        expected: {$expectedEffective}\n        got:      {$result['sql']}\n";
        }
    } catch (\Throwable $e) {
        check($label, false);
        echo "        unexpectedly rejected: {$e->getMessage()}\n";
    }
}

/** Assert the guard rejects a statement, optionally checking the message substring. */
function expectReject(string $sql, string $label, string $messageContains = ''): void
{
    global $prefix;
    try {
        SqlReadOnlyGuard::prepare($sql, $prefix);
        check($label, false);
        echo "        expected rejection but it was allowed\n";
    } catch (\InvalidArgumentException $e) {
        $ok = $messageContains === '' || str_contains($e->getMessage(), $messageContains);
        check($label, $ok);
        if (! $ok) {
            echo "        message did not contain \"{$messageContains}\": {$e->getMessage()}\n";
        }
    }
}

echo "SqlReadOnlyGuard tests\n";

// --- Allowed read-only statements ---
expectAllow(
    'SELECT * FROM {prefix}options LIMIT 1',
    'SELECT * FROM zz_options LIMIT 1',
    'SELECT with explicit LIMIT and {prefix} substitution'
);
expectAllow(
    'SELECT * FROM {prefix}options',
    'SELECT * FROM zz_options LIMIT 200',
    'SELECT without LIMIT gets default LIMIT 200 injected'
);
expectAllow(
    "SHOW TABLES LIKE '%icl%'",
    "SHOW TABLES LIKE '%icl%'",
    'SHOW TABLES is allowed and no LIMIT injected'
);
expectAllow(
    "SELECT element_id, element_type, language_code, source_language_code, trid FROM {prefix}icl_translations WHERE element_type = 'tax_faq-category'",
    "SELECT element_id, element_type, language_code, source_language_code, trid FROM zz_icl_translations WHERE element_type = 'tax_faq-category' LIMIT 200",
    'WPML diagnostic query allowed with LIMIT injected'
);
expectAllow(
    'SHOW CREATE TABLE {prefix}icl_translations',
    'SHOW CREATE TABLE zz_icl_translations',
    'SHOW CREATE TABLE allowed (CREATE blocklist is SELECT-only)'
);
expectAllow(
    'DESCRIBE {prefix}icl_translations',
    'DESCRIBE zz_icl_translations',
    'DESCRIBE allowed'
);
expectAllow(
    'EXPLAIN SELECT * FROM {prefix}posts',
    'EXPLAIN SELECT * FROM zz_posts',
    'EXPLAIN allowed, no LIMIT injection'
);
expectAllow(
    "SELECT meta_value FROM {prefix}postmeta WHERE meta_key = 'setting_count'",
    "SELECT meta_value FROM zz_postmeta WHERE meta_key = 'setting_count' LIMIT 200",
    'Identifier containing "SET"/"setting" not blocked (word boundaries)'
);
expectAllow(
    "SELECT * FROM {prefix}options; ",
    'SELECT * FROM zz_options LIMIT 200',
    'Single trailing semicolon stripped, not treated as multi-statement'
);

// --- Rejected statements ---
expectReject('DELETE FROM {prefix}options', 'DELETE rejected', 'only read-only');
expectReject('SELECT 1; DROP TABLE x', 'Multi-statement (SELECT; DROP) rejected', 'multiple statements');
expectReject('UPDATE {prefix}options SET option_value = 1', 'UPDATE rejected', 'only read-only');
expectReject('INSERT INTO {prefix}options VALUES (1)', 'INSERT rejected', 'only read-only');
expectReject('TRUNCATE {prefix}options', 'TRUNCATE rejected', 'only read-only');
expectReject(
    "SELECT * FROM {prefix}options INTO OUTFILE '/tmp/x'",
    'SELECT ... INTO OUTFILE rejected',
    'OUTFILE'
);
expectReject(
    'SELECT * FROM {prefix}options; DROP TABLE {prefix}posts',
    'SELECT with trailing DROP statement rejected',
    'multiple statements'
);
expectReject(
    '/* sneaky */ DELETE FROM {prefix}options',
    'DELETE hidden behind a block comment still rejected',
    'only read-only'
);
expectReject('   ', 'Whitespace-only statement rejected', 'empty statement');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

/**
 * Pure-PHP guard that validates a single read-only SQL statement and enforces a
 * row limit. No WordPress dependencies — the caller supplies the table prefix so
 * this can be unit-tested in isolation.
 */
class SqlReadOnlyGuard
{
    private const ALLOWED_FIRST_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    /**
     * DML/DDL tokens that may not appear in a SELECT. They are allowed under
     * SHOW/DESCRIBE/EXPLAIN where they can only be identifiers or a non-executed
     * explained statement (e.g. `SHOW CREATE TABLE`, `EXPLAIN DELETE`).
     */
    private const BLOCKED_TOKENS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE',
        'TRUNCATE', 'GRANT', 'REPLACE', 'CALL', 'SET', 'LOAD',
    ];

    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT = 1000;

    /**
     * Validate and prepare a statement.
     *
     * @return array{sql: string, first_keyword: string} The effective SQL to run.
     *
     * @throws \InvalidArgumentException when a rule blocks the statement, with a
     *                                   message naming the rule that fired.
     */
    public static function prepare(string $sql, string $prefix, int $limit = self::DEFAULT_LIMIT): array
    {
        // 1. Substitute the {prefix} placeholder first — affects all later matching.
        $sql = str_replace('{prefix}', $prefix, $sql);

        // 2. Strip comments so they cannot hide or trigger rules.
        $stripped = self::stripComments($sql);

        // 3. Trim, drop a single trailing semicolon, then reject any remaining one.
        $stripped = trim($stripped);
        $stripped = rtrim($stripped, "; \t\n\r\0\x0B");

        if ($stripped === '') {
            throw new \InvalidArgumentException('Rejected: empty statement after removing comments and whitespace.');
        }

        if (str_contains($stripped, ';')) {
            throw new \InvalidArgumentException('Rejected: multiple statements are not allowed (found a ";" separating statements).');
        }

        // 4. First keyword must be on the read-only allowlist.
        if (! preg_match('/^\s*([A-Za-z]+)/', $stripped, $m)) {
            throw new \InvalidArgumentException('Rejected: could not determine the leading SQL keyword.');
        }
        $firstKeyword = strtoupper($m[1]);

        if (! in_array($firstKeyword, self::ALLOWED_FIRST_KEYWORDS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Rejected: only read-only statements are allowed. Statement starts with "%s"; allowed: %s.',
                $firstKeyword,
                implode(', ', self::ALLOWED_FIRST_KEYWORDS)
            ));
        }

        // 5a. INTO OUTFILE / DUMPFILE is the only writable path under an allowed
        //     first keyword, so it is always blocked.
        if (preg_match('/\bINTO\s+(OUT|DUMP)FILE\b/i', $stripped)) {
            throw new \InvalidArgumentException('Rejected: "INTO OUTFILE"/"INTO DUMPFILE" can write to the filesystem and is not allowed.');
        }

        // 5b. DML/DDL token blocklist applies only to SELECT. Under
        //     SHOW/DESCRIBE/EXPLAIN these tokens cannot execute a write, and
        //     blocking them would forbid useful queries like SHOW CREATE TABLE.
        if ($firstKeyword === 'SELECT') {
            foreach (self::BLOCKED_TOKENS as $token) {
                if (preg_match('/\b' . $token . '\b/i', $stripped)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Rejected: the keyword "%s" is not allowed in a SELECT statement.',
                        $token
                    ));
                }
            }
        }

        // 6. Inject a LIMIT for SELECT statements that lack one.
        $limit = self::clampLimit($limit);
        $effective = $stripped;
        if ($firstKeyword === 'SELECT' && ! preg_match('/\bLIMIT\b/i', $stripped)) {
            $effective .= ' LIMIT ' . $limit;
        }

        return ['sql' => $effective, 'first_keyword' => $firstKeyword];
    }

    public static function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAX_LIMIT);
    }

    private static function stripComments(string $sql): string
    {
        // Block comments /* ... */ (non-greedy, spans newlines).
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        // Line comments: -- to end of line, and # to end of line.
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/#[^\r\n]*/', ' ', $sql) ?? $sql;

        return $sql;
    }
}

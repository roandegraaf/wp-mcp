<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;
use WpMcp\Helpers\SqlReadOnlyGuard;

class DatabaseTool extends AbstractTool
{
    private const MAX_CELL_LENGTH = 2000;

    #[McpTool(
        name: 'wp_db_query',
        description: 'Run a READ-ONLY SQL query against the WordPress database for diagnostics (e.g. inspecting WPML icl_translations or raw table shape not exposed by other tools). Only SELECT, SHOW, DESCRIBE/DESC and EXPLAIN are permitted; writes/DDL and multiple statements are rejected. Use the {prefix} placeholder for the table prefix (this install uses "zz_"), e.g. "SELECT * FROM {prefix}options LIMIT 1". The response includes the active prefix and the effective SQL that ran (a LIMIT is injected into SELECTs that lack one).'
    )]
    public function dbQuery(
        #[Schema(description: 'The read-only SQL to run. Supports a {prefix} placeholder substituted with the WordPress table prefix. Must be a single SELECT/SHOW/DESCRIBE/EXPLAIN statement.')]
        string $sql,
        #[Schema(description: 'Row limit injected into SELECT queries that have no explicit LIMIT. Default 200, max 1000.', minimum: 1, maximum: 1000)]
        int $limit = SqlReadOnlyGuard::DEFAULT_LIMIT,
    ): string {
        global $wpdb;

        $prefix = $wpdb->prefix;

        try {
            $prepared = SqlReadOnlyGuard::prepare($sql, $prefix, $limit);
        } catch (\InvalidArgumentException $e) {
            return ResponseFormatter::toJson([
                'error'   => true,
                'message' => $e->getMessage(),
                'prefix'  => $prefix,
            ]);
        }

        $effectiveSql = $prepared['sql'];

        // Read path only — never $wpdb->query (which permits writes). Capture DB
        // errors verbatim instead of letting them print/escape.
        $previousSuppress = $wpdb->suppress_errors(true);
        $previousShow = $wpdb->hide_errors();
        $wpdb->last_error = '';

        $rows = $wpdb->get_results($effectiveSql, ARRAY_A);

        $dbError = $wpdb->last_error;
        $wpdb->suppress_errors($previousSuppress);
        if ($previousShow) {
            $wpdb->show_errors();
        }

        if ($dbError !== '') {
            return ResponseFormatter::toJson([
                'error'         => true,
                'message'       => $dbError,
                'effective_sql' => $effectiveSql,
                'prefix'        => $prefix,
            ]);
        }

        if (! is_array($rows)) {
            $rows = [];
        }

        $rows = $this->truncateCells($rows);

        return ResponseFormatter::toJson([
            'prefix'        => $prefix,
            'effective_sql' => $effectiveSql,
            'row_count'     => count($rows),
            'rows'          => $rows,
        ]);
    }

    /**
     * Truncate oversized cell values so a single huge column (serialized blobs,
     * post_content) doesn't blow up the response.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function truncateCells(array $rows): array
    {
        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                if (is_string($value) && strlen($value) > self::MAX_CELL_LENGTH) {
                    $full = strlen($value);
                    // mb_strcut avoids splitting a multibyte sequence, which would
                    // otherwise produce invalid UTF-8 and break json_encode.
                    $rows[$i][$column] = mb_strcut($value, 0, self::MAX_CELL_LENGTH, 'UTF-8')
                        . sprintf('…[truncated, %d chars total]', $full);
                }
            }
        }

        return $rows;
    }
}

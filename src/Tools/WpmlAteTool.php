<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

/**
 * Tools for WPML's Advanced Translation Editor (ATE) / Translation Management:
 * the cloned-site "moved or copied" migration and the translation-job editor.
 *
 * Everything here depends on the WPML Translation Management + ATE classes,
 * which are private, unversioned WPML internals. Each entry point guards the
 * specific classes/functions it needs and fails with a clear message rather
 * than a fatal when WPML moves or renames them.
 */
class WpmlAteTool extends AbstractTool
{
    private const LOCK_OPTION = 'otgs_wpml_tm_ate_cloned_site_lock';
    private const OLD_JOBS_EDITOR_OPTION = 'wpml-old-jobs-editor';

    private function requireWpml(): void
    {
        if (! function_exists('icl_get_languages')) {
            throw new \RuntimeException('WPML is required but not active.');
        }
    }

    /**
     * Ensure WPML Translation Management + ATE are present before touching ATE internals.
     */
    private function requireAte(): void
    {
        $this->requireWpml();

        if (! class_exists('WPML_TM_ATE_Status')) {
            throw new \RuntimeException('WPML Translation Management / ATE is not active on this site.');
        }
        if (! \WPML_TM_ATE_Status::is_enabled()) {
            throw new \RuntimeException('The Advanced Translation Editor (ATE) is not the selected translation method on this site.');
        }
    }

    #[McpTool(name: 'wp_ate_site_status', description: 'Report WPML ATE "cloned site" status: whether the site is locked because WPML detected it was moved or copied to a new URL, the URL currently registered with WPML\'s AMS, and the URL of the current request. Read-only — use before wp_ate_report_site_move.')]
    public function ateSiteStatus(): string
    {
        $this->requireAte();

        $lockClass = 'WPML\\TM\\ATE\\ClonedSites\\Lock';
        if (! class_exists($lockClass)) {
            throw new \RuntimeException('WPML ClonedSites support not found in this WPML version (' . $lockClass . ' missing).');
        }

        $option = get_option(self::LOCK_OPTION, false);
        try {
            $isLocked = (bool) call_user_func([$lockClass, 'isLocked']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not determine cloned-site lock state (WPML container may be unavailable in this context): ' . $e->getMessage());
        }

        $urlRegistered = '';
        $urlRequested = '';
        $identicalUrlBeforeMovement = false;

        // getLockData() is an instance method; instantiate directly (no ctor deps).
        try {
            $lock = new $lockClass();
            if (method_exists($lock, 'getLockData')) {
                $data = $lock->getLockData();
                $urlRegistered = $data['urlCurrentlyRegisteredInAMS'] ?? '';
                $urlRequested = $data['urlUsedToMakeRequest'] ?? '';
                $identicalUrlBeforeMovement = (bool) ($data['identicalUrlBeforeMovement'] ?? false);
            }
        } catch (\Throwable $e) {
            // Fall back to the raw option below.
        }

        return ResponseFormatter::toJson([
            'ate_enabled'                   => \WPML_TM_ATE_Status::is_enabled(),
            'ate_activated'                 => class_exists('WPML_TM_ATE_Status') && method_exists('WPML_TM_ATE_Status', 'is_active')
                ? (bool) \WPML_TM_ATE_Status::is_active()
                : null,
            'is_locked'                     => $isLocked,
            'lock_option_present'           => (bool) $option,
            'url_registered_in_ams'         => $urlRegistered,
            'url_used_to_make_request'      => $urlRequested,
            'identical_url_before_movement' => $identicalUrlBeforeMovement,
            'message'                       => $isLocked
                ? 'Site is locked: WPML detected a move/copy. Resolve with wp_ate_report_site_move (mode "move" or "copy").'
                : 'No cloned-site lock active.',
        ]);
    }

    #[McpTool(name: 'wp_ate_report_site_move', description: 'Resolve WPML\'s "site moved or copied" lock by reporting the change to WPML\'s AMS — the programmatic equivalent of the admin notice button. mode "move" tells WPML the site relocated to a new URL (the #1 fix after migrating a site); mode "copy" registers it as a separate copy. This makes a remote AMS request (requires a site key and network access) and only runs when the site is actually locked. Check wp_ate_site_status first.')]
    public function ateReportSiteMove(
        #[Schema(description: 'Resolution mode: "move" (site relocated to this URL) or "copy" (this is a separate copy of the original).', enum: ['move', 'copy'])]
        string $mode = 'move',
    ): string {
        $this->requireAte();

        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['move', 'copy'], true)) {
            throw new \RuntimeException('mode must be "move" or "copy".');
        }

        $lockClass = 'WPML\\TM\\ATE\\ClonedSites\\Lock';
        $reportClass = 'WPML\\TM\\ATE\\ClonedSites\\Report';
        $makeFn = 'WPML\\Container\\make';

        if (! class_exists($lockClass) || ! class_exists($reportClass)) {
            throw new \RuntimeException('WPML ClonedSites support not found in this WPML version.');
        }
        if (! function_exists($makeFn)) {
            throw new \RuntimeException('WPML dependency container (WPML\\Container\\make) is unavailable; cannot build the cloned-sites reporter.');
        }

        try {
            $locked = (bool) call_user_func([$lockClass, 'isLocked']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not determine cloned-site lock state: ' . $e->getMessage());
        }
        if (! $locked) {
            return ResponseFormatter::toJson([
                'mode'       => $mode,
                'reported'   => false,
                'message'    => 'Site is not in a moved/copied (locked) state; nothing to report. Use wp_ate_site_status to confirm.',
            ]);
        }

        try {
            $report = call_user_func($makeFn, $reportClass);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not resolve the WPML cloned-sites reporter: ' . $e->getMessage());
        }

        $method = $mode === 'move' ? 'move' : 'copy';
        if (! method_exists($report, $method)) {
            throw new \RuntimeException("WPML Report::{$method}() is not available in this WPML version.");
        }

        $result = $report->{$method}();

        if (is_wp_error($result)) {
            throw new \RuntimeException('WPML rejected the ' . $mode . ' report: ' . $result->get_error_message());
        }

        try {
            $stillLocked = (bool) call_user_func([$lockClass, 'isLocked']);
        } catch (\Throwable $e) {
            $stillLocked = false;
        }

        return ResponseFormatter::toJson([
            'mode'         => $mode,
            'reported'     => (bool) $result,
            'still_locked' => $stillLocked,
            'message'      => $result && ! $stillLocked
                ? "Site successfully reported as '{$mode}'. Cloned-site lock cleared."
                : "Report submitted (result: " . var_export($result, true) . "). Verify with wp_ate_site_status.",
        ]);
    }

    #[McpTool(name: 'wp_get_jobs_editor_distribution', description: 'Report how WPML translation jobs are split across editors (ate vs wpml/classic) and automatic vs manual, plus the current "editor for old translations" setting. Read-only diagnostic for planning an editor migration.')]
    public function getJobsEditorDistribution(): string
    {
        $this->requireWpml();

        global $wpdb;

        $table = $wpdb->prefix . 'icl_translate_job';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            throw new \RuntimeException('WPML translation jobs table not found. Is Translation Management active?');
        }

        $rows = $wpdb->get_results(
            "SELECT COALESCE(NULLIF(editor, ''), 'none') AS editor,
                    automatic,
                    COUNT(*) AS cnt
             FROM {$table}
             GROUP BY editor, automatic"
        );

        $byEditor = [];
        $total = 0;
        foreach ($rows as $row) {
            $editor = (string) $row->editor;
            $cnt = (int) $row->cnt;
            $total += $cnt;
            if (! isset($byEditor[$editor])) {
                $byEditor[$editor] = ['total' => 0, 'automatic' => 0, 'manual' => 0];
            }
            $byEditor[$editor]['total'] += $cnt;
            if ((int) $row->automatic === 1) {
                $byEditor[$editor]['automatic'] += $cnt;
            } else {
                $byEditor[$editor]['manual'] += $cnt;
            }
        }

        $oldJobsEditor = get_option(self::OLD_JOBS_EDITOR_OPTION, null);

        return ResponseFormatter::toJson([
            'total_jobs'              => $total,
            'by_editor'               => $byEditor,
            'old_jobs_editor_setting' => $oldJobsEditor,
            'note'                    => "editor 'ate' = Advanced Translation Editor, 'wpml' = classic editor, 'none' = not yet opened. To make existing/old jobs open in ATE, set old_jobs_editor to 'ate' with wp_set_old_jobs_editor. Re-sending content for automatic (ATE/DeepL) translation is a remote AMS operation performed from the WPML Translation Dashboard and is not exposed here.",
        ]);
    }

    #[McpTool(name: 'wp_set_old_jobs_editor', description: 'Set which editor WPML uses when re-opening EXISTING/old translation jobs: "ate" (Advanced Translation Editor) or "wpml" (classic). Setting "ate" is the supported way to migrate old translations onto ATE — WPML creates the ATE job lazily when each translation is next opened. This is a local setting; it does not itself send content to the remote ATE/DeepL service.')]
    public function setOldJobsEditor(
        #[Schema(description: 'Editor for old jobs: "ate" or "wpml".', enum: ['ate', 'wpml'])]
        string $editor,
    ): string {
        $this->requireWpml();

        $editor = strtolower(trim($editor));
        if (! in_array($editor, ['ate', 'wpml'], true)) {
            throw new \RuntimeException('editor must be "ate" or "wpml".');
        }

        $previous = get_option(self::OLD_JOBS_EDITOR_OPTION, null);
        update_option(self::OLD_JOBS_EDITOR_OPTION, $editor);

        return ResponseFormatter::toJson([
            'previous' => $previous,
            'editor'   => $editor,
            'message'  => $editor === 'ate'
                ? 'Old translation jobs will now open in the Advanced Translation Editor (ATE jobs are created on first open).'
                : 'Old translation jobs will now open in the classic WPML editor.',
        ]);
    }
}

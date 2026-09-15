<?php

declare(strict_types=1);

/**
 * Standalone test for the revision block diff and the error-log since filter —
 * run with: php tests/revision-diff-test.php
 *
 * No PHPUnit/WordPress required; both routines are pure PHP over arrays.
 */

require __DIR__ . '/../src/Helpers/BlockHelper.php';
require __DIR__ . '/../src/Tools/AbstractTool.php';
require __DIR__ . '/../src/Tools/SiteHealthTool.php';

use WpMcp\Helpers\BlockHelper;
use WpMcp\Tools\SiteHealthTool;

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
    }
}

function acfBlock(string $name, array $data, string $innerHtml = ''): array
{
    $withKeys = [];
    foreach ($data as $key => $value) {
        $withKeys[$key] = $value;
        $withKeys['_' . $key] = 'field_' . md5($key);
    }
    return ['blockName' => $name, 'attrs' => ['name' => $name, 'data' => $withKeys], 'innerBlocks' => [], 'innerHTML' => $innerHtml, 'innerContent' => []];
}

function coreBlock(string $name, array $attrs, string $innerHtml): array
{
    return ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $innerHtml, 'innerContent' => [$innerHtml]];
}

echo "diffParsedBlocks\n";

$from = [
    ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => ["\n\n"]],
    acfBlock('acf/hero', ['title' => 'Hallo', 'category' => '88']),
    acfBlock('acf/faq-list', ['category' => '88', 'limit' => 10, 'buttons' => 1, 'buttons_0_label' => 'Mehr']),
    coreBlock('core/paragraph', [], '<p>old</p>'),
];
$to = [
    acfBlock('acf/hero', ['title' => 'Hallo', 'category' => 88]),
    acfBlock('acf/faq-list', ['category' => '90', 'limit' => 10, 'buttons' => 2, 'buttons_0_label' => 'Mehr', 'buttons_1_label' => 'Weniger']),
    coreBlock('core/paragraph', [], '<p>new</p>'),
    coreBlock('core/spacer', ['height' => '40px'], ''),
];

$diff = BlockHelper::diffParsedBlocks($from, $to);
$byIndex = array_column($diff['blocks'], null, 'index');

check('summary counts', $diff['summary'] === ['added' => 1, 'removed' => 0, 'changed' => 2, 'unchanged' => 1, 'replaced' => 0], json_encode($diff['summary']));
check('empty whitespace block is ignored', ! isset($byIndex[0]) || $byIndex[0]['name'] !== null);
check('string/int equal values are unchanged', ! isset($byIndex[0]));
check('faq category changed 88 -> 90', ($byIndex[1]['fields']['category'] ?? null) === ['status' => 'changed', 'from' => '88', 'to' => '90']);
check('added repeater row reported', ($byIndex[1]['fields']['buttons_1_label']['status'] ?? null) === 'added');
check('field-key companions are not diffed', ! isset($byIndex[1]['fields']['_category']));
check('unchanged field omitted', ! isset($byIndex[1]['fields']['limit']));
check('core block inner html change', ($byIndex[2]['inner_html'] ?? null) === ['from' => '<p>old</p>', 'to' => '<p>new</p>']);
check('appended block is added', ($byIndex[3]['status'] ?? null) === 'added' && $byIndex[3]['name'] === 'core/spacer');

$removed = BlockHelper::diffParsedBlocks($to, $from);
check('reverse diff reports removal', $removed['summary']['removed'] === 1 && $removed['blocks'][count($removed['blocks']) - 1]['status'] === 'removed');

$replaced = BlockHelper::diffParsedBlocks([acfBlock('acf/hero', ['a' => 1])], [acfBlock('acf/cta', ['a' => 1])]);
check('different block name at same index is replaced', $replaced['summary']['replaced'] === 1);

echo "filterLinesSince\n";

$lines = [
    '[14-Sep-2026 09:00:00 UTC] PHP Warning:  old entry',
    'Stack trace of old entry',
    '[15-Sep-2026 10:00:00 UTC] PHP Fatal error:  new entry',
    '#0 continuation of new entry',
    '[15-Sep-2026 11:30:00 UTC] PHP Notice:  newer entry',
];
$since = strtotime('2026-09-15 10:00:00 UTC');
$filtered = SiteHealthTool::filterLinesSince($lines, $since);

check('drops entries before since', ! in_array($lines[0], $filtered, true) && ! in_array($lines[1], $filtered, true));
check('keeps entry at since (inclusive)', in_array($lines[2], $filtered, true));
check('continuation line follows its entry', in_array($lines[3], $filtered, true));
check('keeps later entries', $filtered === array_slice($lines, 2));
check('untimestamped leading lines are dropped', SiteHealthTool::filterLinesSince(['no timestamp', $lines[4]], $since) === [$lines[4]]);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

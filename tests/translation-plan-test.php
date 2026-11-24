<?php

declare(strict_types=1);

/**
 * Standalone test for translation decisions (post parent mapping, menu copy
 * plan, ACF options id per language) — run with: php tests/translation-plan-test.php
 */

require __DIR__ . '/../src/Helpers/TranslationPlan.php';
require __DIR__ . '/../src/Helpers/WpmlHelper.php';

use WpMcp\Helpers\TranslationPlan;
use WpMcp\Helpers\WpmlHelper;

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

function item(int $id, int $parent, string $type = 'post_type', ?int $target = 1): array
{
    return ['id' => $id, 'parent' => $parent, 'type' => $type, 'target_object_id' => $target];
}

echo "postParent\n";
check('no parent', TranslationPlan::postParent(0, null) === ['parent_id' => 0, 'status' => 'none']);
check('no parent ignores a stray translation', TranslationPlan::postParent(0, 99) === ['parent_id' => 0, 'status' => 'none']);
check('parent mapped', TranslationPlan::postParent(10, 20) === ['parent_id' => 20, 'status' => 'mapped']);
check('parent untranslated', TranslationPlan::postParent(10, null) === ['parent_id' => 0, 'status' => 'parent_not_translated']);
check('parent lookup returned 0', TranslationPlan::postParent(10, 0) === ['parent_id' => 0, 'status' => 'parent_not_translated']);

echo "menuItems: hierarchy kept\n";
$plan = TranslationPlan::menuItems([
    item(1, 0),
    item(2, 1),
    item(3, 2),
    item(4, 0, 'custom', null),
    item(5, 4, 'taxonomy', 7),
]);
check('all copied', array_column($plan['create'], 'id') === [1, 2, 3, 4, 5], json_encode($plan['create']));
check('parents kept', array_column($plan['create'], 'parent', 'id') === [1 => 0, 2 => 1, 3 => 2, 4 => 0, 5 => 4]);
check('custom link without target is not skipped', $plan['skipped'] === []);
check('nothing reparented', $plan['reparented'] === []);

echo "menuItems: skipped items and their children\n";
$plan = TranslationPlan::menuItems([
    item(1, 0),
    item(2, 1, 'post_type', null),
    item(3, 2),
    item(4, 2, 'taxonomy', null),
    item(5, 4),
    item(6, 0, 'post_type', null),
    item(7, 6, 'custom', null),
]);
check('untranslated post_type/taxonomy items skipped', array_column($plan['skipped'], 'id') === [2, 4, 6], json_encode($plan['skipped']));
check('skip reason', $plan['skipped'][0]['reason'] === 'no_translation');
$parents = array_column($plan['create'], 'parent', 'id');
check('child of skipped item moves to nearest copied ancestor', $parents[3] === 1);
check('grandchild under two skipped levels moves up', $parents[5] === 1);
check('child of skipped root item becomes root', $parents[7] === 0);
check('reparent report', $plan['reparented'] === [
    ['id' => 3, 'from' => 2, 'to' => 1],
    ['id' => 5, 'from' => 4, 'to' => 1],
    ['id' => 7, 'from' => 6, 'to' => 0],
], json_encode($plan['reparented']));

echo "menuItems: order puts parents first\n";
$plan = TranslationPlan::menuItems([
    item(3, 2),
    item(2, 1),
    item(1, 0),
]);
check('parents created before children', array_column($plan['create'], 'id') === [1, 2, 3], json_encode($plan['create']));

echo "menuItems: broken data\n";
$plan = TranslationPlan::menuItems([
    item(1, 99),
    item(2, 3),
    item(3, 2),
]);
$parents = array_column($plan['create'], 'parent', 'id');
check('missing parent becomes root', $parents[1] === 0);
check('cycle is broken, every item still created', count($plan['create']) === 3 && in_array(0, [$parents[2], $parents[3]], true), json_encode($plan['create']));

echo "acfOptionsPostId\n";
check('default language', WpmlHelper::acfOptionsPostId('nl', 'nl') === 'options');
check('empty language', WpmlHelper::acfOptionsPostId('', 'nl') === 'options');
check('other language', WpmlHelper::acfOptionsPostId('de', 'nl') === 'options_de');
check('regional code', WpmlHelper::acfOptionsPostId('pt-br', 'nl') === 'options_pt-br');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

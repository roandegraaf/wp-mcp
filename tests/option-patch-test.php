<?php

declare(strict_types=1);

/**
 * Standalone test for array/object option updates — run with: php tests/option-patch-test.php
 */

require __DIR__ . '/../src/Helpers/OptionPatch.php';

use WpMcp\Helpers\OptionPatch;

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

function rejects(callable $fn): ?string
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return null;
}

$settings = [
    'default_language'       => 'nl',
    'hidden_languages'       => ['de', 'fr'],
    'setup_complete'         => 1,
    'translation-management' => [
        'custom_fields_translation' => ['title' => 2, '_title' => 1],
        'doc_translation_method'    => 1,
    ],
];

echo "rejects plain values for array options\n";
foreach (['nl', '"nl"', '42', 'true', 'null', '{broken'] as $input) {
    check("rejects {$input}", rejects(fn() => OptionPatch::apply($settings, $input, true)) !== null);
}
check('error mentions JSON object', str_contains((string) rejects(fn() => OptionPatch::apply($settings, 'nl', true)), 'JSON object'));

echo "merge keeps other keys\n";
$result = OptionPatch::apply($settings, '{"translation-management":{"custom_fields_translation":{"intro":2}}}', true);
$value = $result['value'];
check('untouched top-level keys survive', $value['default_language'] === 'nl' && $value['setup_complete'] === 1 && $value['hidden_languages'] === ['de', 'fr']);
check('nested sibling keys survive', $value['translation-management']['doc_translation_method'] === 1);
check('nested map merged', $value['translation-management']['custom_fields_translation'] === ['title' => 2, '_title' => 1, 'intro' => 2]);
check('changed_keys lists only the touched key', $result['changed_keys'] === ['translation-management'], json_encode($result['changed_keys']));
check('type stays array', is_array($value));

echo "lists replace instead of merging by index\n";
$result = OptionPatch::apply($settings, '{"hidden_languages":["es"]}', true);
check('list replaced, not merged', $result['value']['hidden_languages'] === ['es'], json_encode($result['value']['hidden_languages']));
$result = OptionPatch::apply($settings, '{"hidden_languages":[]}', true);
check('empty list clears', $result['value']['hidden_languages'] === []);

echo "merge mode rejects a top-level list\n";
check('list patch rejected with merge', rejects(fn() => OptionPatch::apply($settings, '["a"]', true)) !== null);

echo "no-op merge\n";
$result = OptionPatch::apply($settings, '{"default_language":"nl"}', true);
check('same value reports no changed keys', $result['changed_keys'] === []);
$result = OptionPatch::apply($settings, '{}', true);
check('empty object is a no-op', $result['changed_keys'] === [] && $result['value'] === $settings);

echo "replace mode\n";
$result = OptionPatch::apply($settings, '{"default_language":"en"}', false);
check('replace drops other keys', $result['value'] === ['default_language' => 'en']);
check('replace reports removed keys', in_array('setup_complete', $result['changed_keys'], true) && in_array('default_language', $result['changed_keys'], true));
$result = OptionPatch::apply([1, 2, 3], '[4,5]', false);
check('list option replaced with list', $result['value'] === [4, 5]);

echo "object options keep their type\n";
$object = (object) ['a' => 1, 'b' => ['x' => 1]];
$result = OptionPatch::apply($object, '{"b":{"y":2}}', true);
check('object stays object', is_object($result['value']));
check('object merged', $result['value']->a === 1 && $result['value']->b === ['x' => 1, 'y' => 2], json_encode($result['value']));

echo "JSON types survive\n";
$result = OptionPatch::apply(['n' => 1], '{"n":2,"f":1.5,"b":false,"s":"2"}', true);
check('int/float/bool/string preserved', $result['value'] === ['n' => 2, 'f' => 1.5, 'b' => false, 's' => '2']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

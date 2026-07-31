<?php

declare(strict_types=1);

/**
 * Reconstructs the exact field topology from the bug report (post 7054, block 6)
 * and asserts the resolver reproduces the "correct key" column for all 17 names.
 *
 * The stub registry deliberately lists acf/themas-slider FIRST so that a global
 * name lookup reproduces the original corruption — proving the test has teeth.
 */

// ---------------------------------------------------------------- fixtures --

function f(string $name, string $key, string $type = 'text', array $extra = []): array
{
    return array_merge(['name' => $name, 'key' => $key, 'type' => $type], $extra);
}

// Group targeting acf/themas-slider — same NAMES, different keys. Listed first.
$themasSlider = [
    'key'      => 'group_themas_slider',
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/themas-slider']]],
    'fields'   => [
        f('subtitle', 'field_themas_slider_subtitle'),
        f('title', 'field_themas_slider_title'),
        f('heading', 'field_themas_slider_heading'),
        f('heading_size', 'field_themas_slider_heading_size'),
        f('content_items', 'field_themas_slider_content_items'),
        f('buttons', 'field_themas_slider_buttons', 'repeater', ['sub_fields' => [
            f('color', 'field_themas_slider_buttons_color'),
            f('link', 'field_themas_slider_buttons_link', 'link'),
        ]]),
        f('add_id', 'field_themas_slider_add_id'),
        f('id', 'field_themas_slider_id'),
        f('pt', 'field_themas_slider_pt'),
        f('pb', 'field_themas_slider_pb'),
        f('background', 'field_themas_slider_background'),
    ],
];

// Group targeting acf/werkzaamheden — collides on image_text_position only.
$werkzaamheden = [
    'key'      => 'group_werkzaamheden',
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/werkzaamheden']]],
    'fields'   => [f('image_text_position', 'field_6894739cc2d1a')],
];

// The block under test. Keys are shown post-splice, exactly as acf_get_fields()
// returns them: seamless clones already carry field_<clone>_field_<original>.
$tekstMetAfbeelding = [
    'key'      => 'group_tekst_met_afbeelding',
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/tekst-met-afbeelding']]],
    'fields'   => [
        f('subtitle', 'field_67975537b5c9b_field_688a018facdee'),
        f('title', 'field_67975537b5c9b_field_67974cffef2eb'),
        f('heading', 'field_67975537b5c9b_field_67975a44d57c7'),
        f('heading_size', 'field_67975537b5c9b_field_688a02b08e9c9'),
        f('content_items', 'field_67975537b5c9b_field_67975a5fd57c8'),
        // Cloned repeater: parent key is composite, sub_fields keep original keys.
        f('buttons', 'field_67975537b5c9b_field_67974dadef2ee', 'repeater', ['sub_fields' => [
            f('color', 'field_67da97319c6b5'),
            f('link', 'field_67974dcdef2f0', 'link'),
        ]]),
        f('add_id', 'field_6797607b00502_field_67a48b3acc244'),
        f('id', 'field_6797607b00502_field_67a480c841198'),
        f('pt', 'field_6797607b00502_field_67975fa3bde96'),
        f('pb', 'field_6797607b00502_field_67975fd2bde97'),
        f('background', 'field_6797607b00502_field_67975fe6bde98'),
        f('image_text_position', 'field_65439a5a4d455'),
        f('design', 'field_6894b97cb9b8a'),
        f('full_width', 'field_689b27048244f', 'true_false'),
        // Repeater whose "image" sub-field is itself a seamless clone.
        f('media_items', 'field_688b29b781371_parent', 'repeater', ['sub_fields' => [
            f('image', 'field_688b29b781371_field_6867bff2f51bf', 'image'),
        ]]),
    ],
];

$REGISTRY = [$themasSlider, $werkzaamheden, $tekstMetAfbeelding];

// ------------------------------------------------------------- ACF stubs ----

function acf_get_field_groups(array $filter = []): array
{
    global $REGISTRY;
    $block = $filter['block'] ?? null;
    if ($block === null) {
        return $REGISTRY;
    }

    return array_values(array_filter($REGISTRY, function ($group) use ($block) {
        foreach ($group['location'] as $ruleGroup) {
            foreach ($ruleGroup as $rule) {
                if ($rule['param'] === 'block' && ($rule['value'] === $block || $rule['value'] === 'all')) {
                    return true;
                }
            }
        }
        return false;
    }));
}

function acf_get_fields(array $group): array
{
    return $group['fields'] ?? [];
}

require __DIR__ . '/../src/Helpers/AcfFieldKeyResolver.php';
require __DIR__ . '/../src/Helpers/BlockHelper.php';

use WpMcp\Helpers\AcfFieldKeyResolver;
use WpMcp\Helpers\BlockHelper;

// ---------------------------------------------------------------- harness ---

$pass = 0;
$fail = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
        echo "       expected: " . var_export($expected, true) . "\n";
        echo "       actual:   " . var_export($actual, true) . "\n";
    }
}

function prepare(array $data, string $blockName, array $existing = []): array
{
    $m = new ReflectionMethod(BlockHelper::class, 'prepareAcfBlockData');
    $m->setAccessible(true);
    return $m->invoke(null, $data, $blockName, $existing);
}

$BLOCK = 'acf/tekst-met-afbeelding';

// == 1. All 17 names resolve to the block's own field group ===================
echo "\n[1] Resolver reproduces the report's 'correct key' column\n";

$expected = [
    'subtitle'            => 'field_67975537b5c9b_field_688a018facdee',
    'title'               => 'field_67975537b5c9b_field_67974cffef2eb',
    'heading'             => 'field_67975537b5c9b_field_67975a44d57c7',
    'heading_size'        => 'field_67975537b5c9b_field_688a02b08e9c9',
    'content_items'       => 'field_67975537b5c9b_field_67975a5fd57c8',
    'buttons'             => 'field_67975537b5c9b_field_67974dadef2ee',
    'add_id'              => 'field_6797607b00502_field_67a48b3acc244',
    'id'                  => 'field_6797607b00502_field_67a480c841198',
    'pt'                  => 'field_6797607b00502_field_67975fa3bde96',
    'pb'                  => 'field_6797607b00502_field_67975fd2bde97',
    'background'          => 'field_6797607b00502_field_67975fe6bde98',
    'image_text_position' => 'field_65439a5a4d455',
    'design'              => 'field_6894b97cb9b8a',
    'full_width'          => 'field_689b27048244f',
    'buttons_0_color'     => 'field_67da97319c6b5',
    'buttons_0_link'      => 'field_67974dcdef2f0',
    'media_items_0_image' => 'field_688b29b781371_field_6867bff2f51bf',
];

foreach ($expected as $name => $key) {
    check($name, AcfFieldKeyResolver::resolveKey($BLOCK, $name), $key);
}

// == 2. Higher row indices and multi-digit indices ============================
echo "\n[2] Arbitrary row indices\n";
check('buttons_3_color', AcfFieldKeyResolver::resolveKey($BLOCK, 'buttons_3_color'), 'field_67da97319c6b5');
check('buttons_12_link', AcfFieldKeyResolver::resolveKey($BLOCK, 'buttons_12_link'), 'field_67974dcdef2f0');

// == 3. Cross-block isolation =================================================
echo "\n[3] Sibling blocks keep their own keys\n";
check('themas subtitle', AcfFieldKeyResolver::resolveKey('acf/themas-slider', 'subtitle'), 'field_themas_slider_subtitle');
check(
    'werkzaamheden image_text_position',
    AcfFieldKeyResolver::resolveKey('acf/werkzaamheden', 'image_text_position'),
    'field_6894739cc2d1a',
);
check('themas has no design field', AcfFieldKeyResolver::resolveKey('acf/themas-slider', 'design'), null);

// == 4. Full write path ========================================================
echo "\n[4] prepareAcfBlockData writes block-scoped keys\n";

$written = prepare(['subtitle' => 'Hello', 'image_text_position' => 'left'], $BLOCK);
check('_subtitle', $written['_subtitle'] ?? null, 'field_67975537b5c9b_field_688a018facdee');
check('_image_text_position', $written['_image_text_position'] ?? null, 'field_65439a5a4d455');
check('value preserved', $written['subtitle'] ?? null, 'Hello');

// == 5. Existing keys are preserved verbatim, never re-derived ================
echo "\n[5] Existing companion keys survive untouched\n";

$existing = [
    'subtitle'  => 'Old',
    '_subtitle' => 'field_legacy_handwritten_key',
];
$written = prepare(['subtitle' => 'New'], $BLOCK, $existing);
check('key preserved', $written['_subtitle'] ?? null, 'field_legacy_handwritten_key');
check('value updated', $written['subtitle'] ?? null, 'New');

// == 6. Caller-supplied key wins ==============================================
echo "\n[6] Explicit caller key takes precedence\n";

$written = prepare(['subtitle' => 'X', '_subtitle' => 'field_explicit'], $BLOCK, $existing);
check('explicit wins', $written['_subtitle'] ?? null, 'field_explicit');

// == 7. Round trip is byte-identical ==========================================
echo "\n[7] Read -> write round trip preserves every key\n";

$stored = [];
foreach ($expected as $name => $key) {
    $stored[$name] = 'value-' . $name;
    $stored['_' . $name] = $key;
}
// wp_list_post_blocks strips the "_" companions; simulate that read shape.
$readBack = [];
foreach ($stored as $k => $v) {
    if (! str_starts_with($k, '_')) {
        $readBack[$k] = $v;
    }
}
$roundTripped = array_merge($stored, prepare($readBack, $BLOCK, $stored));
check('round trip identical', $roundTripped, $stored);

// == 8. Unresolvable name fails loudly ========================================
echo "\n[8] Unknown names fail loudly instead of borrowing\n";

try {
    prepare(['totally_unknown_field' => 'x'], $BLOCK);
    check('throws', 'no exception', 'RuntimeException');
} catch (RuntimeException $e) {
    check('throws', true, true);
    $msg = $e->getMessage();
    check('names the field', str_contains($msg, 'totally_unknown_field'), true);
    check('names the block', str_contains($msg, $BLOCK), true);
    check('suggests escape hatch', str_contains($msg, '_totally_unknown_field'), true);
}

// Unknown name on a block with NO field groups gets the registration hint.
try {
    prepare(['whatever' => 'x'], 'acf/not-registered');
    check('unregistered throws', 'no exception', 'RuntimeException');
} catch (RuntimeException $e) {
    check('unregistered hint', str_contains($e->getMessage(), 'No ACF field group targets block'), true);
}

// ...but an unregistered block whose keys are all already stored still works.
$ok = prepare(['foo' => 'bar'], 'acf/not-registered', ['foo' => 'old', '_foo' => 'field_abc']);
check('preservation rescues unregistered block', $ok['_foo'] ?? null, 'field_abc');

// == 9. Bug 2 — nested repeater arrays are rejected ===========================
echo "\n[9] Nested repeater arrays rejected with a flattened example\n";

try {
    prepare(['buttons' => [['color' => 'primary', 'link' => ['url' => '/a']]]], $BLOCK);
    check('nested rejected', 'no exception', 'RuntimeException');
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    check('nested rejected', true, true);
    check('mentions repeater', str_contains($msg, 'repeater'), true);
    check('shows count', str_contains($msg, '"buttons":1'), true);
    check('shows flattened cell', str_contains($msg, '"buttons_0_color":"primary"'), true);
}

// Flattened form still passes through untouched.
$flat = prepare(['buttons' => 1, 'buttons_0_color' => 'primary'], $BLOCK);
check('flattened accepted', $flat['buttons_0_color'] ?? null, 'primary');
check('flattened key correct', $flat['_buttons_0_color'] ?? null, 'field_67da97319c6b5');

// Legitimately array-valued fields are NOT mistaken for repeater rows.
$img = prepare(['media_items_0_image' => ['ID' => 5, 'url' => '/a.jpg']], $BLOCK);
check('assoc array untouched', $img['_media_items_0_image'] ?? null, 'field_688b29b781371_field_6867bff2f51bf');

// == 10. Regression: the old global lookup would have failed these ============
echo "\n[10] Confirm the fixture actually reproduces the bug for a global lookup\n";

$globalLookup = function (string $name) use ($REGISTRY): ?string {
    foreach ($REGISTRY as $group) {
        foreach ($group['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field['key'];
            }
        }
    }
    return null;
};
check('global lookup is wrong for subtitle', $globalLookup('subtitle'), 'field_themas_slider_subtitle');
check(
    'scoped lookup differs',
    AcfFieldKeyResolver::resolveKey($BLOCK, 'subtitle') !== $globalLookup('subtitle'),
    true,
);

// == 11. Real read path: extractAcfBlockData -> prepareAcfBlockData ==========
echo "\n[11] Round trip through the actual read path\n";

function extractRead(array $blockData): array
{
    $m = new ReflectionMethod(BlockHelper::class, 'extractAcfBlockData');
    $m->setAccessible(true);
    return $m->invoke(null, ['attrs' => ['data' => $blockData]]);
}

// extractAcfBlockData only strips "_" keys whose value looks like a field key,
// so a "_" entry holding anything else survives the read as a normal value.
$storedWithNote = [
    'subtitle'  => 'Hi',
    '_subtitle' => 'field_67975537b5c9b_field_688a018facdee',
    '_note'     => 'not a field key',
];
$read = extractRead($storedWithNote);
check('_note survives read', $read['_note'] ?? null, 'not a field key');
check('_subtitle stripped on read', array_key_exists('_subtitle', $read), false);

$writtenBack = prepare($read, $BLOCK, $storedWithNote);
check('_note passed through once', $writtenBack['_note'] ?? null, 'not a field key');
check('no doubled companion', array_key_exists('__note', $writtenBack), false);
check('_subtitle still preserved', $writtenBack['_subtitle'] ?? null, 'field_67975537b5c9b_field_688a018facdee');

// == 12. Integer-like keys survive the overlay merge ==========================
echo "\n[12] Integer-like keys are not renumbered\n";

// json_decode turns {"0": "x"} into an int key; array_merge would renumber it.
$existingNumeric = ['0' => 'old', '_0' => 'field_numeric_name'];
$preparedNumeric = prepare(['0' => 'new'], $BLOCK, $existingNumeric);

$merged = $existingNumeric;
foreach ($preparedNumeric as $k => $v) {
    $merged[$k] = $v;
}
check('numeric key keeps identity', $merged[0] ?? null, 'new');
check('numeric companion preserved', $merged['_0'] ?? null, 'field_numeric_name');
check('no stray appended entry', count($merged), 2);

// Demonstrate the overlay is what saves it: array_merge would not.
$byArrayMerge = array_merge($existingNumeric, $preparedNumeric);
check('array_merge would duplicate', count($byArrayMerge) > 2, true);

// == 13. Untouched fields keep position and key ==============================
echo "\n[13] Untouched entries keep order and keys\n";

$before = [
    'title'   => 'A',
    '_title'  => 'field_67975537b5c9b_field_67974cffef2eb',
    'design'  => 'wide',
    '_design' => 'field_6894b97cb9b8a',
];
$after = $before;
foreach (prepare(['title' => 'B'], $BLOCK, $before) as $k => $v) {
    $after[$k] = $v;
}
check('order unchanged', array_keys($after), array_keys($before));
check('untouched key intact', $after['_design'] ?? null, 'field_6894b97cb9b8a');
check('touched value updated', $after['title'] ?? null, 'B');

// ------------------------------------------------------------------ result --

echo "\n" . str_repeat('=', 60) . "\n";
echo "passed: {$pass}   failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);

<?php

declare(strict_types=1);

namespace WpMcp\Helpers;

final class TranslationPlan
{
    /**
     * @return array{parent_id: int, status: 'none'|'mapped'|'parent_not_translated'}
     */
    public static function postParent(int $sourceParentId, ?int $translatedParentId): array
    {
        if ($sourceParentId <= 0) {
            return ['parent_id' => 0, 'status' => 'none'];
        }
        if ($translatedParentId !== null && $translatedParentId > 0) {
            return ['parent_id' => $translatedParentId, 'status' => 'mapped'];
        }
        return ['parent_id' => 0, 'status' => 'parent_not_translated'];
    }

    /**
     * Decide which menu items to copy, in which order, and under which parent.
     *
     * Each input item: id, parent (source parent item id, 0 = root), type
     * ("post_type" | "taxonomy" | "custom" | ...), target_object_id (the
     * translated object for post_type/taxonomy items, null when none exists).
     * Items whose object has no translation are skipped; their children move up
     * to the nearest copied ancestor.
     *
     * @param list<array{id: int, parent: int, type: string, target_object_id: ?int}> $items
     * @return array{
     *     create: list<array{id: int, parent: int}>,
     *     skipped: list<array{id: int, reason: string}>,
     *     reparented: list<array{id: int, from: int, to: int}>
     * }
     */
    public static function menuItems(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        $skipped = [];
        $skippedIds = [];
        foreach ($items as $item) {
            if (in_array($item['type'], ['post_type', 'taxonomy'], true) && $item['target_object_id'] === null) {
                $skipped[] = ['id' => $item['id'], 'reason' => 'no_translation'];
                $skippedIds[$item['id']] = true;
            }
        }

        $effectiveParent = [];
        $reparented = [];
        foreach ($items as $item) {
            if (isset($skippedIds[$item['id']])) {
                continue;
            }
            $parent = $item['parent'];
            $seen = [];
            while ($parent !== 0 && (isset($skippedIds[$parent]) || ! isset($byId[$parent])) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $parent = isset($byId[$parent]) ? $byId[$parent]['parent'] : 0;
            }
            if (isset($seen[$parent])) {
                $parent = 0;
            }
            $effectiveParent[$item['id']] = $parent;
            if ($parent !== $item['parent']) {
                $reparented[] = ['id' => $item['id'], 'from' => $item['parent'], 'to' => $parent];
            }
        }

        $create = [];
        $placed = [];
        $pending = array_keys($effectiveParent);
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $i => $id) {
                $parent = $effectiveParent[$id];
                if ($parent === 0 || isset($placed[$parent])) {
                    $create[] = ['id' => $id, 'parent' => $parent];
                    $placed[$id] = true;
                    unset($pending[$i]);
                    $progress = true;
                }
            }
            if (! $progress) {
                foreach ($pending as $id) {
                    $create[] = ['id' => $id, 'parent' => 0];
                    $reparented[] = ['id' => $id, 'from' => $effectiveParent[$id], 'to' => 0];
                    $placed[$id] = true;
                }
                $pending = [];
            }
        }

        return ['create' => $create, 'skipped' => $skipped, 'reparented' => $reparented];
    }
}

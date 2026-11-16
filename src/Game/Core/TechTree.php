<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Builds the technology graph shown on the tech tree page: one node per element (building, research, ship, defence,
 * missile) with its requirements, the player's levels and a status, plus one tab per category whose rows are the
 * requirement depth (roots first) and whose nodes are ordered to keep the connecting lines apart.
 *
 * Pure data in, data out: no database, no globals, so it can be unit tested.
 */
final class TechTree
{
    /** category => id of the headline translation (tech[0] "Buildings", tech[100] "Research", ...) */
    public const HEADLINES = ['build' => 0, 'tech' => 100, 'fleet' => 200, 'defense' => 400, 'missile' => 500];

    /** barycenter sweeps (down + up) applied to every tab */
    private const ORDER_PASSES = 4;

    /**
     * @param array<string, list<int>> $groups       category => element ids (only enabled elements)
     * @param array<int, array<int,int>> $requirements element id => [required id => required level]
     * @param array<int, int|float> $levels          element id => level the player owns
     * @param array<int, string> $names              element id => translated name
     * @return array{nodes: array<int, array<string, mixed>>, tabs: list<array<string, mixed>>}
     */
    public function build(array $groups, array $requirements, array $levels, array $names): array
    {
        $nodes = $this->nodes($groups, $requirements, $levels, $names);
        $this->assignDepths($nodes);

        $tabs = [];
        foreach ($groups as $group => $ids) {
            if ($ids === []) {
                continue;
            }
            $tabs[] = [
                'group' => $group,
                'headline' => self::HEADLINES[$group] ?? 0,
                'rows' => $this->rows($nodes, $ids),
            ];
        }

        return ['nodes' => $nodes, 'tabs' => $tabs];
    }

    /**
     * One node per element; requirements on unknown (disabled) elements are dropped, "met" compares the own level.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nodes(array $groups, array $requirements, array $levels, array $names): array
    {
        $nodes = [];
        foreach ($groups as $group => $ids) {
            foreach ($ids as $id) {
                $nodes[$id] = [
                    'id' => $id,
                    'name' => $names[$id] ?? (string) $id,
                    'group' => $group,
                    'image' => 'gebaeude/' . $id . '.' . ($id >= 600 && $id <= 699 ? 'jpg' : 'gif'),
                    'level' => (int) ($levels[$id] ?? 0),
                    'requirements' => [],
                    'status' => 'locked',
                    'depth' => 0,
                ];
            }
        }
        foreach ($nodes as $id => &$node) {
            $allMet = true;
            foreach ($requirements[$id] ?? [] as $requiredId => $needed) {
                if (!isset($nodes[$requiredId])) {
                    continue;
                }
                $own = (int) ($levels[$requiredId] ?? 0);
                $met = $own >= $needed;
                $allMet = $allMet && $met;
                $node['requirements'][] = ['id' => $requiredId, 'level' => (int) $needed, 'own' => $own, 'met' => $met];
            }
            $node['status'] = $node['level'] > 0 ? 'owned' : ($allMet ? 'available' : 'locked');
        }
        unset($node);

        return $nodes;
    }

    /** depth = longest requirement path from a root (an element without requirements has depth 0) */
    private function assignDepths(array &$nodes): void
    {
        $depth = [];
        $resolve = function (int $id, array $path) use (&$resolve, &$depth, &$nodes): int {
            if (isset($depth[$id])) {
                return $depth[$id];
            }
            $best = 0;
            foreach ($nodes[$id]['requirements'] as $requirement) {
                if (!isset($path[$requirement['id']])) { // guard against cyclic data
                    $best = max($best, $resolve($requirement['id'], $path + [$id => true]) + 1);
                }
            }

            return $depth[$id] = $best;
        };
        foreach (array_keys($nodes) as $id) {
            $nodes[$id]['depth'] = $resolve($id, []);
        }
    }

    /**
     * Rows of one tab: the category's elements plus the foreign ones they directly need (or that connect two visible
     * nodes), grouped by depth (empty depths are skipped) and ordered to reduce crossings.
     *
     * @param list<int> $ids elements of the category
     * @return list<list<int>> element ids per row, top row first
     */
    private function rows(array $nodes, array $ids): array
    {
        $members = $this->visibleMembers($nodes, $ids);

        $byDepth = [];
        foreach (array_keys($members) as $id) {
            $byDepth[$nodes[$id]['depth']][] = $id;
        }
        ksort($byDepth);
        $rows = array_values($byDepth);
        foreach ($rows as &$row) {
            sort($row);
        }
        unset($row);

        return $this->orderRows($rows, $nodes, $members);
    }

    /**
     * Ids shown in a tab: the category's own elements, the foreign elements they directly require, and foreign
     * elements that sit on a requirement path between two visible nodes (so no line ends in nowhere).
     *
     * @param list<int> $ids elements of the category
     * @return array<int, true>
     */
    private function visibleMembers(array $nodes, array $ids): array
    {
        $members = array_fill_keys($ids, true);
        foreach ($ids as $id) {
            foreach ($nodes[$id]['requirements'] as $requirement) {
                $members[$requirement['id']] = true;
            }
        }
        $ancestors = [];
        foreach (array_keys($nodes) as $id) {
            $ancestors[$id] = $this->ancestors($nodes, $id);
        }
        $visible = array_keys($members);
        foreach (array_keys($nodes) as $candidate) {
            if (isset($members[$candidate])) {
                continue;
            }
            $below = array_filter($visible, static fn(int $v): bool => isset($ancestors[$v][$candidate]));
            $above = array_filter($visible, static fn(int $v): bool => isset($ancestors[$candidate][$v]));
            if ($below !== [] && $above !== []) {
                $members[$candidate] = true;
            }
        }

        return $members;
    }

    /** @return array<int, true> all elements an element transitively requires */
    private function ancestors(array $nodes, int $id): array
    {
        $found = [];
        $stack = [$id];
        while ($stack !== []) {
            foreach ($nodes[array_pop($stack)]['requirements'] as $requirement) {
                if (!isset($found[$requirement['id']])) {
                    $found[$requirement['id']] = true;
                    $stack[] = $requirement['id'];
                }
            }
        }

        return $found;
    }

    /**
     * Barycenter heuristic: alternately sort every row by the mean position of the connected nodes in the rows above
     * (down sweep) and below (up sweep); positions are relative to the row width so rows of different size compare.
     *
     * @param list<list<int>> $rows
     * @return list<list<int>>
     */
    private function orderRows(array $rows, array $nodes, array $members): array
    {
        $parents = [];
        $children = [];
        foreach ($rows as $row) {
            foreach ($row as $id) {
                foreach ($nodes[$id]['requirements'] as $requirement) {
                    if (isset($members[$requirement['id']])) {
                        $parents[$id][] = $requirement['id'];
                        $children[$requirement['id']][] = $id;
                    }
                }
            }
        }

        for ($pass = 0; $pass < self::ORDER_PASSES; $pass++) {
            $down = $pass % 2 === 0;
            $indexes = $down ? range(1, count($rows) - 1) : range(count($rows) - 2, 0);
            foreach ($indexes as $index) {
                if (isset($rows[$index])) {
                    $rows[$index] = $this->sortRow($rows[$index], $rows, $down ? $parents : $children);
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $row
     * @param list<list<int>> $rows
     * @param array<int, list<int>> $neighbours connected node ids of every node (towards the sweep's source rows)
     * @return list<int>
     */
    private function sortRow(array $row, array $rows, array $neighbours): array
    {
        $position = [];
        foreach ($rows as $other) {
            foreach ($other as $i => $id) {
                $position[$id] = ($i + 0.5) / count($other);
            }
        }
        $keys = [];
        foreach ($row as $id) {
            $values = array_map(static fn(int $n): float => $position[$n], $neighbours[$id] ?? []);
            $keys[$id] = $values === [] ? $position[$id] : array_sum($values) / count($values);
        }
        usort($row, static fn(int $a, int $b): int => $keys[$a] <=> $keys[$b] ?: $a <=> $b);

        return $row;
    }
}

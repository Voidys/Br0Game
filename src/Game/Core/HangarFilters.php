<?php

namespace App\Game\Core;

/**
 * Reads and writes the hangar filter list stored in users_settings.hangar_filters.
 *
 * The JSON array mixes group keys (civil, military, recycler, all) from the
 * settings page with pinned ship element IDs from the hangar plus/x controls.
 * Both save paths must merge so one side does not wipe the other.
 * The hangar plus-menu is only shown when users_settings.hangar_filters_enabled is 1.
 */
class HangarFilters
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public const GROUPS = ['civil', 'military', 'recycler', 'all'];

    public const DEFAULT_GROUPS = ['civil', 'military', 'all'];

    /**
     * Decode a stored JSON value into a sanitized filter list.
     */
    public function parse(mixed $raw): array
    {
        if (is_array($raw)) {
            return $this->sanitize($raw);
        }

        if (!is_string($raw) || $raw === '') {
            return self::DEFAULT_GROUPS;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_GROUPS;
        }

        return $this->sanitize($decoded);
    }

    /**
     * Keep only known group keys and valid fleet IDs, in a stable group order.
     */
    public function sanitize(array $items): array
    {
        $reslist = $this->state->reslist;
        $fleet = $reslist['fleet'] ?? [];

        $groups = [];
        foreach (self::GROUPS as $group) {
            if (in_array($group, $items, true)) {
                $groups[] = $group;
            }
        }

        $ships = [];
        $seen = [];
        foreach ($items as $item) {
            if (in_array($item, self::GROUPS, true)) {
                continue;
            }

            $shipId = (int) $item;
            if ($shipId <= 0 || isset($seen[$shipId])) {
                continue;
            }

            if (!isset($fleet[$shipId]) && !in_array($shipId, $fleet, true)) {
                continue;
            }

            $ships[] = $shipId;
            $seen[$shipId] = true;
        }

        return array_merge($groups, $ships);
    }

    /**
     * Replace group keys and keep previously pinned ships.
     */
    public function mergeGroups(array $current, array $groups): array
    {
        $merged = array_merge($this->sanitize($groups), $this->shipIds($current));

        return $merged === [] ? ['all'] : $merged;
    }

    /**
     * Replace pinned ships and keep previously stored groups.
     */
    public function mergeShips(array $current, array $ships): array
    {
        $merged = array_merge($this->groups($current), $this->shipIds($this->sanitize($ships)));

        return $merged === [] ? ['all'] : $merged;
    }

    /**
     * The group keys contained in a filter list.
     */
    public function groups(array $filters): array
    {
        return array_values(array_filter(
            $filters,
            static fn($item) => in_array($item, self::GROUPS, true)
        ));
    }

    /**
     * The pinned ship ids (numeric entries) of a filter list.
     */
    public function shipIds(array $filters): array
    {
        $ids = [];
        foreach ($filters as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }

    public function hasGroup(array $filters, string $group): bool
    {
        return in_array($group, $filters, true);
    }

    /**
     * JSON string for storage; falls back to the default groups if encoding fails.
     */
    public function encode(array $filters): string
    {
        $json = json_encode(array_values($filters));

        return $json === false ? json_encode(self::DEFAULT_GROUPS) : $json;
    }
}

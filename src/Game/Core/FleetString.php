<?php

namespace App\Game\Core;

/**
 * (De)serialisation of the fleet string format used in fleets.ships and log_fleets.ships: "id,count;id,count".
 * Pure functions, no dependencies.
 */
class FleetString
{
    /**
     * Parses a fleet string into [shipId => count]; repeated ids are summed, entries without an id are skipped.
     *
     * @return array<int|string, int|float|string>
     */
    public function unserialize(string $fleetAmount): array
    {
        if (empty($fleetAmount)) {
            return [];
        }

        $fleetTyps = explode(';', $fleetAmount);

        $fleetAmount = [];

        foreach ($fleetTyps as $fleetTyp) {
            $temp = explode(',', $fleetTyp);

            if (empty($temp[0])) {
                continue;
            }

            if (!isset($fleetAmount[$temp[0]])) {
                $fleetAmount[$temp[0]] = 0;
            }

            $fleetAmount[$temp[0]] += $temp[1];
        }

        return $fleetAmount;
    }

    /**
     * Builds the fleet string from [shipId => count]; entries with count <= 0 are dropped, counts are written without
     * exponent notation (floatToString).
     *
     * @param array<int|string, int|float|string> $fleet
     */
    public function serialize(array $fleet): string
    {
        $serialized = '';
        if (empty($fleet)) {
            return $serialized;
        }

        foreach ($fleet as $elementID => $amount) {
            if ($amount > 0) {
                $serialized .= $elementID . ',' . floatToString($amount) . ';';
            }
        }

        return empty($fleet) ? $serialized : substr($serialized, 0, -1);
    }
}

<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Outcome of integrating a planet's production over a time span (ResourceProduction::produceFor()): per resource the amount
 * that was added (negative: consumed), the capped stock after it, and the planet's energy figures the production ran with.
 *
 * Immutable and independent of the state array: the caller applies it (stock += delta; the running "builded" sums += delta),
 * see ResourceProduction::produce() for the array adapter. For a moon nothing is produced: the deltas are 0 and the stocks
 * are the unchanged ones.
 */
final readonly class ProductionResult
{
    /**
     * @param float|int $metalDelta       metal added to the stock (consumption would be negative), after the storage/zero caps
     * @param float|int $crystalDelta     crystal added to the stock, after the caps
     * @param float|int $deuteriumDelta   deuterium added to the stock (negative when the fusion plant burns more than is made), after the caps
     * @param float     $metal            metal stock after the production
     * @param float     $crystal          crystal stock after the production
     * @param float     $deuterium        deuterium stock after the production
     * @param float     $energy           energy production of the planet (unchanged by the integration)
     * @param float     $energyUsed       energy consumption of the planet (unchanged by the integration)
     */
    public function __construct(
        public int|float $metalDelta,
        public int|float $crystalDelta,
        public int|float $deuteriumDelta,
        public float $metal,
        public float $crystal,
        public float $deuterium,
        public float $energy,
        public float $energyUsed,
    ) {}

    /**
     * Amount added to the stock of a resource.
     *
     * @param int $resourceId RESOURCE_METAL, RESOURCE_CRYSTAL or RESOURCE_DEUT
     */
    public function delta(int $resourceId): int|float
    {
        return match ($resourceId) {
            RESOURCE_METAL => $this->metalDelta,
            RESOURCE_CRYSTAL => $this->crystalDelta,
            RESOURCE_DEUT => $this->deuteriumDelta,
            default => 0,
        };
    }

    /**
     * Stock of a resource after the production.
     *
     * @param int $resourceId RESOURCE_METAL, RESOURCE_CRYSTAL or RESOURCE_DEUT
     */
    public function stock(int $resourceId): float
    {
        return match ($resourceId) {
            RESOURCE_METAL => $this->metal,
            RESOURCE_CRYSTAL => $this->crystal,
            RESOURCE_DEUT => $this->deuterium,
            default => 0.0,
        };
    }
}

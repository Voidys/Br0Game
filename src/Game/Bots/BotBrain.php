<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Decision logic of a bot: builds a list of everything that is currently
 * possible, scores it like an experienced human would, then applies
 * personality, noise, skill and mistake rolls.
 *
 * A decision is an array:
 *   kind      'act' | 'mistake' | 'wait'
 *   type      'build' | 'research' | 'ship' | 'defense'   (not for wait)
 *   planet    planet id
 *   element   element id
 *   count     amount (ships/defense)
 *   cat       weight category
 *   note      free text for the log (wait reason)
 */
class BotBrain
{
    use GameUtilities;

    private const BUILDINGS = [1, 2, 3, 4, 12, 14, 15, 21, 22, 23, 24, 31, 33];

    /** research id => [base score, softcap] (score = base * cap / (cap + level)) */
    private const RESEARCH = [
        113 => [70, 6], 115 => [55, 4], 108 => [55, 6], 106 => [40, 3], 117 => [48, 5], 124 => [58, 5],
        114 => [30, 4], 118 => [25, 3], 109 => [38, 5], 110 => [36, 5], 111 => [36, 5], 120 => [20, 4],
        121 => [14, 3], 122 => [10, 3], 131 => [34, 5], 132 => [34, 5], 133 => [32, 5], 123 => [12, 2],
    ];

    /** defense id => [base score, softcap] */
    private const DEFENSE = [401 => [28, 30], 402 => [24, 20], 403 => [16, 12], 404 => [9, 6], 405 => [6, 4], 407 => [14, 1], 408 => [10, 1]];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * Picks the next action of a bot. Takes every currently possible action (candidates()), perturbs the scores by
     * `randomness` percent so identical bots diverge, then in this order: with probability mistakeChance() takes a random
     * affordable action marked 'mistake'; 4 % of the time does nothing ('distracted'); otherwise draws among the best three
     * (the lower the skill, the more weight the 2nd and 3rd get). An unaffordable pick means the bot saves up
     * and waits. No candidates also yields a wait decision. Pure decision: nothing is written here.
     *
     * @param array<string, mixed> $settings    normalized profile settings (see BotProfiles)
     * @param array<string, float> $personality per-bot multipliers (`patience`, one per weight category), 1.0 = neutral
     * @param float                $skill       0-1, current skill of the bot
     * @return array<string, mixed> decision, see the class comment
     */
    public function choose(BotActions $ctx, array $settings, array $personality, float $skill): array
    {
        $candidates = $this->candidates($ctx, $settings, $personality, $skill);
        if (empty($candidates)) {
            return ['kind' => 'wait', 'note' => 'nothing to do'];
        }

        // noise so bots with identical state diverge
        $noise = max(0, (float) $settings['randomness']);
        foreach ($candidates as &$c) {
            $c['score'] *= 1 + (mt_rand(-1000, 1000) / 1000) * ($noise / 100);
        }
        unset($c);

        usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
        $affordable = array_values(array_filter($candidates, static fn($c) => $c['affordable']));

        // mistake roll: skilled bots (and, with skill gain, bots that grew) err less
        if (!empty($affordable) && mt_rand(1, 10000) <= self::mistakeChance($settings, $skill) * 100) {
            $pick = $affordable[array_rand($affordable)];
            $pick['kind'] = 'mistake';

            return $pick;
        }

        // sometimes a human just does not do anything useful
        if (mt_rand(1, 100) <= 4) {
            return ['kind' => 'wait', 'note' => 'distracted'];
        }

        // weighted pick among the top 3, skill makes the best option dominate
        $top = array_slice($candidates, 0, 3);
        $slack = 1 - max(0.0, min(1.0, $skill));
        $weights = [1.0, $slack * 0.8 + 0.01, $slack * 0.4 + 0.005];
        $sum = 0.0;
        foreach ($top as $i => $_) {
            $sum += $weights[$i];
        }
        $roll = mt_rand() / mt_getrandmax() * $sum;
        $pick = $top[0];
        foreach ($top as $i => $c) {
            $roll -= $weights[$i];
            if ($roll <= 0) {
                $pick = $c;
                break;
            }
        }

        if (!$pick['affordable']) {
            return ['kind' => 'wait', 'note' => 'saving for ' . $pick['label']];
        }

        $pick['kind'] = 'act';

        return $pick;
    }

    /**
     * Mistake probability in percent (0-100). Bots with a skill gain (e.g. the noob profile) make `mistake_chance` percent
     * mistakes at `skill_start` and fall linearly to 0 at `skill_max`; others keep the constant base value.
     */
    public static function mistakeChance(array $settings, float $skill): float
    {
        $base = (float) $settings['mistake_chance'];
        $gain = (float) $settings['skill_gain_per_day'];
        if ($gain > 0) {
            $range = max(0.01, (float) $settings['skill_max'] - (float) $settings['skill_start']);

            return max(0.0, min(100.0, $base * (((float) $settings['skill_max'] - $skill) / $range)));
        }

        return max(0.0, min(100.0, $base));
    }

    /**
     * Lists everything the bot could do now, each with a score: buildings (mines kept in a human-like ratio, energy
     * when the balance is negative, storage when nearly full, facilities by milestone; only if the build queue is
     * free), research (base score with diminishing returns per level; needs a lab and an empty research queue) and
     * ships/defense (when units can be queued; colony ship only if a colony is possible). The score is multiplied by the
     * profile weight and personality factor of the category (sqrt of the relative weight), and by a waiting penalty
     * 1 / (1 + hoursToAfford / (3 * patience)) where hours come from the planet's hourly income (production plus the
     * universe's basic income). `affordable` says whether the price is covered right now.
     *
     * @return list<array<string, mixed>> decisions with `type`, `planet`, `element`, `count`, `cat`, `score`, `affordable`, `label`
     */
    private function candidates(BotActions $ctx, array $settings, array $personality, float $skill): array
    {
        $resource = $this->state->resource;
        $config = $ctx->config;
        $user = $ctx->user;
        $patience = (float) ($personality['patience'] ?? 1.0);

        $weights = $settings['weights'];
        $avg = max(1.0, array_sum($weights) / max(1, count($weights)));
        $wf = static function (string $cat) use ($weights, $avg, $personality): float {
            return sqrt(max(0.5, (float) ($weights[$cat] ?? $avg)) / $avg) * (float) ($personality[$cat] ?? 1.0);
        };

        $out = [];
        $planetCount = count($ctx->planets);
        $maxPlanets = $this->playerUtil()->maxPlanetCount($user);
        $colonyOk = $planetCount < $maxPlanets && $ctx->canColonize();

        foreach ($ctx->planets as $pid => $planet) {
            $income = [];
            foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $rid => $name) {
                $income[$rid] = (float) $planet[$name . '_perhour']
                    + (float) $config->{$name . '_basic_income'} * ((float) $config->resource_multiplier / 2500);
            }
            // penalty by hours needed to afford, patient bots tolerate more waiting
            $penalty = static function (array $cost) use ($planet, $resource, $income, $patience): array {
                $ttf = 0.0;
                foreach ($cost as $rid => $amount) {
                    if (!isset($income[$rid])) {
                        continue;
                    }
                    $missing = $amount - floor((float) $planet[$resource[$rid]]);
                    if ($missing > 0) {
                        $ttf = max($ttf, $income[$rid] > 0 ? $missing / $income[$rid] : 99.0);
                    }
                }

                return [$ttf <= 0, 1 / (1 + $ttf / (3 * max(0.3, $patience)))];
            };
            $name = $ctx->planetLabel($planet);

            $this->buildingCandidates($out, $ctx, $planet, $pid, $name, $penalty, $wf);
            $this->researchCandidates($out, $ctx, $planet, $pid, $name, $penalty, $wf, $skill);
            $this->unitCandidates($out, $ctx, $planet, $pid, $name, $penalty, $wf, $skill, $colonyOk);
        }

        return $out;
    }

    /**
     * Score and category of building an element one level higher on a planet: mines follow the reference mine level,
     * energy buildings the energy balance, storage the fill level, facilities fixed targets (robotics up to half the
     * metal mine, ...). Elements the bot does not build give null.
     *
     * @param array<string, mixed> $planet planet row
     * @param int $level the level that would be built
     * @param float $bal energy balance
     * @param int $metal metal mine level
     * @param int $crystal crystal mine level
     * @param int $deut deuterium synthesizer level
     * @param int $ref reference mine level the mines are balanced against
     * @param int $free free fields on the planet
     * @return array{0: int|float, 1: string}|null score and category
     */
    private function buildingScore(int $el, array $planet, int $level, float $bal, int $metal, int $crystal, int $deut, int $ref, int $free): ?array
    {
        $resource = $this->state->resource;
        $lv = static fn(int $id): int => (int) $planet[$resource[$id]];
        switch ($el) {
            case 1:
            case 2:
            case 3:
                $target = [1 => $ref + 1, 2 => $ref - 1, 3 => $ref - 3][$el];
                $score = 40 + 14 * max(-2, $target - $lv($el));
                if ($bal < 0) {
                    $score *= 0.3;
                }
                $cat = 'mines';
                break;
            case 4:
                $score = $bal < 0 ? 120 : ($bal < 15 ? 70 : 25);
                $cat = 'energy';
                break;
            case 12:
                $score = ($bal < 15 && $deut >= 8) ? 60 : 10;
                $cat = 'energy';
                break;
            case 22:
            case 23:
            case 24:
                $rid = [22 => 901, 23 => 902, 24 => 903][$el];
                $max = (float) ($planet[$resource[$rid] . '_max'] ?? 0);
                $fill = $max > 0 ? (float) $planet[$resource[$rid]] / $max : 0;
                $score = 12 + 110 * max(0, $fill - 0.55);
                $cat = 'storage';
                break;
            case 14:
                $target = min(10, intdiv($metal, 2));
                $score = $level <= 2 ? ($metal >= 4 ? 105 : 40) : ($level - 1 < $target ? 55 + 6 * ($target - $level + 1) : 10);
                $cat = 'facilities';
                break;
            case 21:
                $score = $level == 1 ? ($metal >= 6 ? 95 : 20) : max(4, 30 - 5 * ($level - 1));
                $cat = 'facilities';
                break;
            case 31:
                $score = $level == 1 ? ($metal >= 4 ? 95 : 40) : ($level <= 8 ? 48 - 5 * ($level - 1) : 6);
                $cat = 'facilities';
                break;
            case 15:
                $score = $level <= 4 ? 30 - 6 * ($level - 1) : 0;
                $cat = 'facilities';
                break;
            case 33:
                $score = $free <= 3 ? 75 : 0;
                $cat = 'facilities';
                break;
            default:
                return null;
        }

        return [$score, $cat];
    }

    /**
     * Building candidates of one planet (when a build slot is free): every buildable element with its score, weighted
     * by the bot's category weights and discounted by the time to afford it.
     *
     * @param list<array<string, mixed>> $out candidate list, appended to
     * @param array<string, mixed> $planet planet row
     * @param callable(array<int, int|float>): array{0: bool, 1: float} $penalty affordability and waiting discount of a cost
     * @param callable(string): float $wf category weight factor
     */
    private function buildingCandidates(array &$out, BotActions $ctx, array $planet, int|string $pid, string $name, callable $penalty, callable $wf): void
    {
        $resource = $this->state->resource;
        $user = $ctx->user;
        $lv = static fn(int $id): int => (int) $planet[$resource[$id]];

        if ($ctx->canStartBuilding($planet)) {
            $bal = (float) $planet['energy'] + (float) $planet['energy_used'];
            $metal = $lv(1);
            $crystal = $lv(2);
            $deut = $lv(3);
            $ref = max($metal, $crystal + 2, $deut + 4);
            $free = (int) $this->playerUtil()->calculateMaxPlanetFields($planet) - (int) $planet['field_current'];

            foreach (self::BUILDINGS as $el) {
                $level = $ctx->buildableLevel($planet, $el);
                if ($level === null) {
                    continue;
                }
                $cost = $this->buildFunctions()->getElementPrice($user, $planet, $el, false, $level);

                $scored = $this->buildingScore($el, $planet, $level, $bal, $metal, $crystal, $deut, $ref, $free);
                if ($scored === null) {
                    continue;
                }
                [$score, $cat] = $scored;
                if ($score <= 0) {
                    continue;
                }
                [$ok, $factor] = $penalty($cost);
                $out[] = [
                    'type' => 'build', 'planet' => $pid, 'element' => $el, 'count' => 1, 'cat' => $cat,
                    'score' => $score * $wf($cat) * $factor, 'affordable' => $ok,
                    'label' => $resource[$el] . ' ' . $level . ' on ' . $name,
                ];
            }
        }
    }

    /**
     * Research candidates of one planet (with a lab, an empty research queue and no lab under construction): score
     * falls with the level, the combat techs weigh with the bot's skill.
     *
     * @param list<array<string, mixed>> $out candidate list, appended to
     * @param array<string, mixed> $planet planet row
     * @param callable(array<int, int|float>): array{0: bool, 1: float} $penalty affordability and waiting discount of a cost
     * @param callable(string): float $wf category weight factor
     */
    private function researchCandidates(array &$out, BotActions $ctx, array $planet, int|string $pid, string $name, callable $penalty, callable $wf, float $skill): void
    {
        $resource = $this->state->resource;
        $user = $ctx->user;
        $lv = static fn(int $id): int => (int) $planet[$resource[$id]];

        if ($lv(31) > 0 && $ctx->isResearchQueueEmpty() && !$ctx->labInConstruction()) {
            foreach (self::RESEARCH as $el => [$base, $cap]) {
                $level = $ctx->researchLevel($planet, $el);
                if ($level === null) {
                    continue;
                }
                $cost = $this->buildFunctions()->getElementPrice($user, $planet, $el, false, $level);
                [$ok, $factor] = $penalty($cost);
                $score = $base * $cap / ($cap + $level - 1);
                if ($el >= 109 && $el <= 111) {
                    $score *= 0.6 + 0.6 * $skill;
                }
                $out[] = [
                    'type' => 'research', 'planet' => $pid, 'element' => $el, 'count' => 1, 'cat' => 'research',
                    'score' => $score * $wf('research') * $factor, 'affordable' => $ok,
                    'label' => $resource[$el] . ' ' . $level . ' on ' . $name,
                ];
            }
        }
    }

    /**
     * Ship and defense candidates of one planet (when units can be queued): ship scores follow the owned numbers and the
     * bot's skill, a colony ship is offered while a colony is possible; the batches come from addUnits().
     *
     * @param list<array<string, mixed>> $out candidate list, appended to
     * @param array<string, mixed> $planet planet row
     * @param callable(array<int, int|float>): array{0: bool, 1: float} $penalty affordability and waiting discount of a cost
     * @param callable(string): float $wf category weight factor
     */
    private function unitCandidates(array &$out, BotActions $ctx, array $planet, int|string $pid, string $name, callable $penalty, callable $wf, float $skill, bool $colonyOk): void
    {
        $resource = $this->state->resource;
        $lv = static fn(int $id): int => (int) $planet[$resource[$id]];

        if ($ctx->canQueueUnits($planet)) {
            $ships = [
                202 => 52 * 6 / (6 + $lv(202) + $lv(203)),
                203 => 42 * 4 / (4 + $lv(203)),
                204 => (14 + 14 * $skill) * 10 / (10 + $lv(204)),
                205 => (14 + 14 * $skill) * 10 / (10 + $lv(205)),
                206 => (16 + 14 * $skill) * 10 / (10 + $lv(206)),
                207 => (16 + 14 * $skill) * 10 / (10 + $lv(207)),
                209 => $lv(209) < 3 ? 14 : 3,
                210 => $lv(210) < 1 ? 36 : ($lv(210) < 5 ? 12 : 3),
            ];
            if ($colonyOk && $lv(208) == 0) {
                $ships[208] = 46;
            }
            foreach ($ships as $el => $score) {
                $this->addUnits($out, $ctx, $planet, $pid, $el, $score, 'ship', 'ships', $wf('ships'), $penalty, $name);
            }

            foreach (self::DEFENSE as $el => [$base, $cap]) {
                $score = $base * $cap / ($cap + $lv($el));
                $this->addUnits($out, $ctx, $planet, $pid, $el, $score, 'defense', 'defense', $wf('defense'), $penalty, $name);
            }
        }
    }

    /**
     * Adds a ship/defense candidate: batch size is 15-60 % of what the planet can afford (at least 1; exactly 1 for
     * colony ship, solar satellite-like single units and one-per-planet defenses), capped by `max_fleet_per_build`; the score
     * is weighted and penalised like in candidates(). Skips elements that are not allowed or score 0.
     *
     * @param list<array<string, mixed>> $out     candidate list, appended to
     * @param callable                   $penalty fn(array $cost): array{0: bool, 1: float} affordable now + waiting factor
     */
    private function addUnits(array &$out, BotActions $ctx, array $planet, int $pid, int $el, float $score, string $type, string $cat, float $wf, callable $penalty, string $name): void
    {
        $resource = $this->state->resource;
        if ($score <= 0 || !$ctx->unitsAllowed($planet, $el)) {
            return;
        }

        $single = $this->buildFunctions()->getElementPrice($ctx->user, $planet, $el);
        $maxAfford = (int) $this->buildFunctions()->getMaxConstructibleElements($ctx->user, $planet, $el, $single);
        if (in_array($el, [208, 210]) || in_array($el, $this->state->reslist['one'])) {
            $count = 1;
        } elseif ($maxAfford >= 1) {
            $count = max(1, (int) floor($maxAfford * (mt_rand(15, 60) / 100)));
        } else {
            $count = 1;
        }
        $count = min($count, (int) $ctx->config->max_fleet_per_build);
        $cost = $this->buildFunctions()->getElementPrice($ctx->user, $planet, $el, false, $count);
        [$ok, $factor] = $penalty($cost);

        $out[] = [
            'type' => $type, 'planet' => $pid, 'element' => $el, 'count' => $count, 'cat' => $cat,
            'score' => $score * $wf * $factor, 'affordable' => $ok,
            'label' => $count . ' x ' . $resource[$el] . ' on ' . $name,
        ];
    }
}

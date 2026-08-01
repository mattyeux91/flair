<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;

/**
 * Fait tourner la simulation du noyau sur une population deja construite,
 * et releve un SkillSample par joueur actif et par categorie a chaque fin
 * d'annee simulee (pas a chaque tick - inutile pour des courbes annuelles,
 * couteux en memoire). Ne reimplemente aucune logique de vieillissement :
 * observe seulement ce que le pipeline football ecrit
 * (Flair\Kernel\Football\Systems\RetirementSystem et
 * Flair\Kernel\Football\Systems\PlayerDevelopmentSystem).
 */
final class Sampler
{
    private const int TICKS_PER_YEAR = 365;

    /** @param list<int> $playerIds */
    public function run(WorldState $world, array $playerIds, int $years, int $worldSeed, Ruleset $ruleset): AggregateResult
    {
        $simulation = new Simulation(new Pipeline([new RetirementSystem(), new PlayerDevelopmentSystem()]));

        /** @var list<SkillSample> $samples */
        $samples = [];
        /** @var list<int> $retirementAges */
        $retirementAges = [];
        /** @var array<int, true> $retired */
        $retired = [];

        for ($year = 1; $year <= $years; $year++) {
            for ($day = 1; $day <= self::TICKS_PER_YEAR; $day++) {
                $tick = ($year - 1) * self::TICKS_PER_YEAR + $day;
                $result = $simulation->step($world, new TickContext(
                    tick: $tick,
                    seed: $worldSeed,
                    intents: [],
                    ruleset: $ruleset,
                ));

                foreach ($result->events as $event) {
                    if ($event instanceof PlayerRetired && !isset($retired[$event->playerId])) {
                        $retired[$event->playerId] = true;
                        $retirementAges[] = $event->ageYears;
                    }
                }
            }

            $this->sampleYearEnd($world, $playerIds, $retired, $year, $samples);
        }

        return AggregateResult::fromSamples($samples, $retirementAges);
    }

    /**
     * @param list<int> $playerIds
     * @param array<int, true> $retired
     * @param list<SkillSample> $samples
     */
    private function sampleYearEnd(WorldState $world, array $playerIds, array $retired, int $year, array &$samples): void
    {
        $now = new SimDate($year * self::TICKS_PER_YEAR);

        foreach ($playerIds as $playerId) {
            if (isset($retired[$playerId])) {
                continue;
            }

            $person = $world->components(Person::class)->get($playerId);
            if ($person === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);

            $physical = $world->components(PlayerPhysicalSkills::class)->get($playerId);
            if ($physical !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'physical', $this->average([
                    $physical->pace, $physical->stamina, $physical->strength, $physical->reflexes,
                ]));
            }

            $technical = $world->components(PlayerTechnicalSkills::class)->get($playerId);
            if ($technical !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'technical', $this->average([
                    $technical->technique, $technical->passing, $technical->finishing,
                    $technical->defending, $technical->positioning, $technical->handling, $technical->distribution,
                ]));
            }

            $mental = $world->components(PlayerMentalSkills::class)->get($playerId);
            if ($mental !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'mental', $this->average([
                    $mental->vision, $mental->composure, $mental->leadership, $mental->discipline, $mental->command,
                ]));
            }
        }
    }

    /** @param list<int> $values */
    private function average(array $values): float
    {
        return array_sum($values) / \count($values);
    }
}

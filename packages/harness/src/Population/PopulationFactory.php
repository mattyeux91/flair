<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;

/**
 * Construit une population synthetique de joueurs pour le harness - premier
 * jet de distributions uniformes, a affiner une fois qu'on aura regarde
 * tourner les premiers agregats (c'est tout le sens de cet outil). Les ages
 * de pic sont tires dans des fourchettes qui respectent deja l'ordre
 * qualitatif documente dans AgingBalance (physique < technique < mental).
 */
final class PopulationFactory
{
    /** @return list<int> identifiants des entites creees */
    public function populate(WorldState $world, int $count, int $seed, int $atTick = 1): array
    {
        $rng = new Rng($seed);
        $playerIds = [];

        for ($i = 0; $i < $count; $i++) {
            $playerIds[] = $this->createPlayer($world, $rng, $atTick);
        }

        return $playerIds;
    }

    private function createPlayer(WorldState $world, Rng $rng, int $atTick): int
    {
        $entity = $world->createEntity();

        $startAge = $this->uniform($rng, 17.0, 34.0);
        $birthDay = (int) round($atTick - $startAge * 365);
        $world->components(Person::class)->set($entity, new Person("Joueur {$entity}", new SimDate($birthDay)));

        $world->components(PlayerPotentials::class)->set($entity, new PlayerPotentials(
            ceiling: $this->uniformInt($rng, 50, 95),
            physicalPeakAge: $this->uniformInt($rng, 21, 26),
            technicalPeakAge: $this->uniformInt($rng, 23, 29),
            mentalPeakAge: $this->uniformInt($rng, 26, 30),
            growthRate: $this->uniform($rng, 0.2, 0.6),
            fragility: $this->uniform($rng, 0.1, 0.9),
        ));

        $physicalSkill = $this->uniformInt($rng, 30, 70);
        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(
            pace: $physicalSkill,
            stamina: $physicalSkill,
            strength: $physicalSkill,
            reflexes: $physicalSkill,
        ));

        $technicalSkill = $this->uniformInt($rng, 30, 70);
        $world->components(PlayerTechnicalSkills::class)->set($entity, new PlayerTechnicalSkills(
            technique: $technicalSkill,
            passing: $technicalSkill,
            finishing: $technicalSkill,
            defending: $technicalSkill,
            positioning: $technicalSkill,
            handling: $technicalSkill,
            distribution: $technicalSkill,
        ));

        $mentalSkill = $this->uniformInt($rng, 30, 70);
        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(
            vision: $mentalSkill,
            composure: $mentalSkill,
            leadership: $mentalSkill,
            discipline: $mentalSkill,
            command: $mentalSkill,
        ));

        return $entity;
    }

    private function uniform(Rng $rng, float $min, float $max): float
    {
        $fraction = $rng->nextUint32() / 0xFFFFFFFF;

        return $min + $fraction * ($max - $min);
    }

    private function uniformInt(Rng $rng, int $min, int $max): int
    {
        return (int) round($this->uniform($rng, (float) $min, (float) $max));
    }
}

<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Generation\PlayerFactory;

/**
 * Construit la population initiale du harness : des joueurs deja en cours
 * de carriere (17-34 ans), la ou `YouthIntakeSystem` ne produit que des
 * recrues de 17 ans.
 *
 * **Le potentiel est tire par `Kernel\Football\Generation\PlayerFactory`,
 * pas ici.** C'est la meme loi de talent que celle des promotions
 * annuelles, et ce partage n'est pas cosmetique : si la population de
 * depart et l'intake suivaient deux lois differentes, le monde convergerait
 * mecaniquement vers celle de l'intake et la pyramide des ages ne pourrait
 * pas etre stationnaire - le critere de sortie de la Phase 0 (docs/15- §4)
 * deviendrait ininterpretable.
 *
 * Ce qui reste local, et doit le rester : l'age et le **niveau de
 * competence correspondant a cet age**. Un joueur genere a 28 ans a deja
 * vecu dix ans de progression, il ne demarre pas aux competences d'un
 * debutant. Approximation assumee du premier jet : une interpolation
 * lineaire entre le niveau de recrue et le `ceiling`, atteint au pic
 * physique - `PlayerDevelopmentSystem` suivrait une trajectoire plus fine,
 * mais le rejouer ici reviendrait a en dupliquer la logique.
 */
final class PopulationFactory
{
    private const YOUNGEST_START_AGE = 17.0;
    private const OLDEST_START_AGE = 34.0;

    public function __construct(
        private readonly PlayerFactory $players = new PlayerFactory(),
    ) {
    }

    /** @return list<int> identifiants des entites creees */
    public function populate(WorldState $world, int $count, int $seed, int $atTick = 1, ?YouthIntakeBalance $talent = null): array
    {
        $rng = new Rng($seed);
        $talent ??= new YouthIntakeBalance();
        $playerIds = [];

        for ($i = 0; $i < $count; $i++) {
            $playerIds[] = $this->createPlayer($world, $rng, $atTick, $talent);
        }

        return $playerIds;
    }

    private function createPlayer(WorldState $world, Rng $rng, int $atTick, YouthIntakeBalance $talent): int
    {
        $entity = $world->createEntity();

        $startAge = $this->uniform($rng, self::YOUNGEST_START_AGE, self::OLDEST_START_AGE);
        $birthDay = (int) round($atTick - $startAge * 365);
        $world->components(Person::class)->set($entity, new Person("Joueur {$entity}", new SimDate($birthDay)));

        $potentials = $this->players->drawPotentials($rng, $talent);
        $world->components(PlayerPotentials::class)->set($entity, $potentials);

        $level = $this->levelAtAge($startAge, $potentials->ceiling, $potentials->physicalPeakAge, $talent);

        $physical = $this->jitter($rng, $level, $talent);
        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(
            pace: $physical,
            stamina: $physical,
            strength: $physical,
            reflexes: $physical,
        ));

        $technical = $this->jitter($rng, $level, $talent);
        $world->components(PlayerTechnicalSkills::class)->set($entity, new PlayerTechnicalSkills(
            technique: $technical,
            passing: $technical,
            finishing: $technical,
            defending: $technical,
            positioning: $technical,
            handling: $technical,
            distribution: $technical,
        ));

        $mental = $this->jitter($rng, $level, $talent);
        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(
            vision: $mental,
            composure: $mental,
            leadership: $mental,
            discipline: $mental,
            command: $mental,
        ));

        return $entity;
    }

    /** Interpolation lineaire du niveau de recrue vers le `ceiling`, atteint au pic et conserve ensuite (le declin est l'affaire de PlayerDevelopmentSystem, pas de la generation initiale). */
    private function levelAtAge(float $age, int $ceiling, int $peakAge, YouthIntakeBalance $talent): int
    {
        $rookieLevel = $ceiling * $talent->startingSkillRatio;
        $span = max(1.0, $peakAge - $talent->intakeAgeYears);
        $progress = min(1.0, max(0.0, ($age - $talent->intakeAgeYears) / $span));

        return (int) round($rookieLevel + $progress * ($ceiling - $rookieLevel));
    }

    private function jitter(Rng $rng, int $level, YouthIntakeBalance $talent): int
    {
        $offset = (int) round($this->uniform($rng, (float) -$talent->startingSkillJitter, (float) $talent->startingSkillJitter));

        return max(1, min(100, $level + $offset));
    }

    private function uniform(Rng $rng, float $min, float $max): float
    {
        $fraction = $rng->nextUint32() / 0xFFFFFFFF;

        return $min + $fraction * ($max - $min);
    }
}

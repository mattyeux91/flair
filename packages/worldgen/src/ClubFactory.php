<?php

declare(strict_types=1);

namespace Flair\Worldgen;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Football\Components\BoardPatience;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;

/**
 * Cree les clubs d'un monde - sans clubs (`Club` + `Facilities`), ni
 * `Football\TrainingSystem` (aucun `SquadMembership` a lire) ni
 * `Football\YouthIntakeSystem` (aucune entite ou promouvoir, cf.
 * `Football\YouthIntakeSystem::update`, qui itere `Club::class`) ne peuvent
 * produire le moindre effet - deux systemes entiers du pipeline restaient
 * incalibrables tant qu'aucun club n'existait.
 *
 * Qualite d'installations et tresorerie de depart uniformes sur tous les
 * clubs plutot que tirees : le harness agrege sur la population entiere et
 * non par club, donc une variance inter-clubs n'ajouterait aucun signal a ce
 * qui est mesure aujourd'hui - juste du bruit. A revisiter quand un
 * indicateur par club, ou un monde de production, en aura besoin.
 */
final class ClubFactory
{
    /** @return list<int> identifiants des entites club creees */
    public function create(WorldState $world, int $count, float $facilitiesQuality, int $startingBalanceCents): array
    {
        $clubIds = [];

        for ($i = 1; $i <= $count; $i++) {
            $entity = $world->createEntity();
            $world->components(Club::class)->set($entity, new Club("Club synthetique {$i}"));
            $world->components(Facilities::class)->set($entity, new Facilities($facilitiesQuality));
            $world->components(Finances::class)->set($entity, new Finances($startingBalanceCents));
            $clubIds[] = $entity;
        }

        return $clubIds;
    }

    /**
     * La patience du conseil d'administration de chaque club dans une
     * negociation de transfert (`Football\Components\BoardPatience`,
     * docs/17-marche-transferts.md point 2 reouvert) : uniforme sur `[mean -
     * spread, mean + spread]`, clampee a l'echelle absolue 1-100 - meme
     * formule que `StaffFactory::judgement()`, dupliquee plutot qu'extraite
     * (deux consommateurs, mais une formule de trois lignes ne justifie pas
     * une classe partagee).
     *
     * **Methode separee, appelee apres la population de joueurs** (voir
     * `WorldFactory::populate()`), jamais depuis `create()` ci-dessus :
     * `create()` ne tire aujourd'hui aucun nombre aleatoire et est appelee
     * *avant* la boucle des joueurs. Y ajouter des tirages y decalerait le
     * flux RNG partage de toute la population de joueurs, pas seulement du
     * staff - le meme risque que `WorldFactory::populate()` documente
     * deja pour la position du staff, en pire (le staff, lui, est deja apres
     * les joueurs).
     *
     * @param list<int> $clubIds
     */
    public function disperseBoardPatience(WorldState $world, Rng $rng, array $clubIds, int $mean, int $spread): void
    {
        foreach ($clubIds as $clubId) {
            $world->components(BoardPatience::class)->set($clubId, new BoardPatience(
                $this->patience($rng, $mean, $spread),
            ));
        }
    }

    private function patience(Rng $rng, int $mean, int $spread): int
    {
        $spread = max(0, $spread);
        $offset = $spread === 0 ? 0 : (int) ($rng->nextUint32() % (2 * $spread + 1)) - $spread;

        return max(1, min(100, $mean + $offset));
    }
}

<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Components\TrainingEffect;

/**
 * La qualite d'environnement d'entrainement (docs/14-algorithmes.md §2) :
 * purement periodique, aucun evenement ecoute, aucun RNG - une fonction
 * deterministe des composants lus, pas de tirage stochastique.
 *
 * Ne modelise que `h(entrainement)` (installations du club) de la formule
 * complete `modif = clamp(h × i(temps de jeu) × j(moral), 0.5, 2.0)`. `i`
 * et `j` dependent d'infrastructures qui n'existent pas encore
 * (`MatchSystem` pour le temps de jeu reel, un composant `Morale`) et
 * seront, le jour ou elles existeront, des composants-facteurs **separes**
 * (`PlayingTimeEffect`, `MoraleEffect`), chacun avec son propre
 * producteur - jamais fusionnes ici ni dans `TrainingEffect`, pour ne pas
 * reproduire le probleme a deux writers deja resolu pour les competences
 * (voir le docblock de `PlayerDevelopmentSystem`, seul writer des
 * composants de competences).
 *
 * Seul writer de `TrainingEffect`. Ne lit **jamais** `PlayerPotentials` ni
 * les composants de competences : ce systeme est aveugle a ce qui consomme
 * son resultat, symetrique de `PlayerDevelopmentSystem` qui est aveugle a
 * la provenance de `TrainingEffect` - cause (environnement) vs mecanisme
 * (progression biologique + hasard).
 *
 * Pour chaque entite joueur qui porte `SquadMembership` (affectee a un
 * club) : recupere `Facilities` sur l'entite club pointee par `clubId`
 * (absente -> ignoree, club mal forme), calcule
 * `clamp(ruleset()->balance->trainingRate × Facilities::$quality, 0.5,
 * 2.0)` et l'ecrit dans `TrainingEffect`. Un joueur sans `SquadMembership`
 * ne recoit aucune ecriture : le defaut neutre (1.0) que
 * `PlayerDevelopmentSystem` applique quand `TrainingEffect` est absent
 * couvre deja ce cas, pas de cas special a coder ici.
 *
 * Doit s'executer avant `PlayerDevelopmentSystem` dans le pipeline (ecrit
 * un composant lu plus loin, docs/11- §9).
 */
final class TrainingSystem implements System
{
    private const MIN_MODIFIER = 0.5;
    private const MAX_MODIFIER = 2.0;

    public function id(): string
    {
        return 'training';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            SquadMembership::class,
            Facilities::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            TrainingEffect::class,
        ];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
    }

    public function update(SystemContext $ctx): void
    {
        $trainingRate = $ctx->ruleset()->balance->trainingRate;

        foreach ($ctx->components(SquadMembership::class)->entities() as $entityId) {
            $membership = $ctx->components(SquadMembership::class)->get($entityId);
            if ($membership === null) {
                continue;
            }

            $facilities = $ctx->components(Facilities::class)->get($membership->clubId);
            if ($facilities === null) {
                continue;
            }

            $modifier = max(self::MIN_MODIFIER, min(self::MAX_MODIFIER, $trainingRate * $facilities->quality));

            $ctx->components(TrainingEffect::class)->set($entityId, new TrainingEffect($modifier));
        }
    }
}

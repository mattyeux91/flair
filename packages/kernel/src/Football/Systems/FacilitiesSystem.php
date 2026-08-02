<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\SeasonConcluded;

/**
 * L'evolution des installations d'un club (docs/14-algorithmes.md §7) : seul
 * writer de `Facilities`, purement reactif, aucun RNG.
 *
 * C'est le systeme qui **referme la boucle** de docs/14- §7 :
 *
 * ```
 * resultats -> revenus (FinanceSystem) -> installations (ici)
 *           -> TrainingEffect (TrainingSystem) + taille des promotions
 *              (YouthIntakeSystem) -> competences -> resultats
 * ```
 *
 * ## Deux mouvements opposes, un seul writer
 *
 * - `SeasonConcluded` : la qualite se degrade de `qualityDecayPerSeason`.
 *   C'est ce qui donne un cout permanent au maintien du niveau.
 * - `ClubInvestedInFacilities` : la somme depensee par le club est convertie
 *   en qualite au taux `centsPerQualityPoint`.
 *
 * Les deux ecrivent `Facilities` : les separer en deux systemes violerait
 * l'invariant "un seul writer par composant" verifie par
 * `Football\PipelineInvariantsTest` - meme raisonnement que la reunion des
 * revenus et des salaires dans `Football\FinanceSystem`.
 *
 * Les deux mouvements arrivent a un tick d'ecart et c'est voulu : la
 * degradation au tick ou `SeasonConcluded` est traite, l'investissement au
 * tick suivant (c'est `FinanceSystem`, qui traite le meme `SeasonConcluded`,
 * qui emet le Fait d'investissement). Un club qui investit exactement de quoi
 * compenser retrouve donc son niveau avec un jour de decalage, invisible a
 * l'echelle d'une saison.
 *
 * ## Position dans le pipeline : en tete, obligatoirement
 *
 * `Football\YouthIntakeSystem` et `Football\TrainingSystem` lisent
 * `Facilities` ; un systeme ne peut pas lire un composant ecrit plus loin
 * dans le pipeline. Ce systeme doit donc preceder les deux, donc ouvrir le
 * pipeline. C'est aussi la raison pour laquelle il ne lit **jamais**
 * `Finances` (ecrit par `FinanceSystem`, bien plus loin) et depend
 * entierement du payload de `ClubInvestedInFacilities` - voir le docblock de
 * cet evenement.
 *
 * ## Aucun evenement emis
 *
 * La degradation est une derive continue, sans seuil comportemental ni rien
 * de racontable (docs/16- §2) : le systeme se tait. L'investissement, lui, a
 * deja son Fait - emis par celui qui a pris la decision et sorti l'argent,
 * pas par celui qui applique la consequence.
 */
final class FacilitiesSystem implements System
{
    public function id(): string
    {
        return 'facilities';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Facilities::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            Facilities::class,
        ];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [
            SeasonConcluded::class,
            ClubInvestedInFacilities::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if ($event instanceof SeasonConcluded) {
            $decay = $ctx->ruleset()->balance->facilities->qualityDecayPerSeason;

            foreach ($ctx->components(Facilities::class)->entities() as $clubId) {
                $this->shiftQuality($ctx, $clubId, -$decay);
            }

            return;
        }

        if ($event instanceof ClubInvestedInFacilities) {
            $centsPerPoint = $ctx->ruleset()->balance->facilities->centsPerQualityPoint;

            if ($centsPerPoint <= 0) {
                return;
            }

            $this->shiftQuality($ctx, $event->clubId, $event->cents / $centsPerPoint);
        }
    }

    public function update(SystemContext $ctx): void
    {
    }

    /**
     * Un club sans `Facilities` est ignore plutot que dote d'une valeur par
     * defaut : ce systeme fait evoluer des installations existantes, il n'en
     * cree pas (`creates()` est vide). C'est le genesis qui les seme.
     */
    private function shiftQuality(SystemContext $ctx, int $clubId, float $delta): void
    {
        $facilities = $ctx->components(Facilities::class)->get($clubId);

        if ($facilities === null) {
            return;
        }

        $quality = max(
            Facilities::MIN_QUALITY,
            min(Facilities::MAX_QUALITY, $facilities->quality + $delta),
        );

        $ctx->components(Facilities::class)->set($clubId, new Facilities($quality));
    }
}

<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Singletons\MonetaryMass;

/**
 * Le grand livre monetaire (docs/14-algorithmes.md §6, docs/15-roadmap.md §4
 * Phase 2) : une injection (revenu de club periodique) et un puits
 * (salaires), tous deux plats et sans RNG dans ce premier lot.
 *
 * ## Un seul systeme, pas deux
 *
 * Revenus et salaires touchent tous les deux `Finances` en ajustant un
 * solde - la meme forme de mutation, contrairement a la retraite
 * (retrait d'archetype) et la progression des competences (mutation de
 * valeur) que `RetirementSystem`/`PlayerDevelopmentSystem` separent
 * justement parce qu'elles n'ont pas la meme forme. Deux systemes qui
 * ecriraient tous les deux `Finances` violeraient l'invariant "un seul
 * writer par composant" (`Football\PipelineInvariantsTest`). Ce systeme
 * reunit donc les deux mouvements, reactif pour l'un, periodique pour
 * l'autre.
 *
 * ## Reactif sur `SeasonStarted`, pas un jour-de-l'annee invente
 *
 * Le revenu de saison reagit a `SeasonStarted` (emis par
 * `Football\CalendarSystem`) plutot que de deriver son propre
 * `tick % 365` : reutilise le decoupage en saisons deja porte par le
 * calendrier au lieu d'en inventer un second. Aucun besoin du canal 1 ici
 * (docs/13- §2) - le credit n'a pas a etre visible le jour meme par un
 * autre systeme.
 *
 * ## Position dans le pipeline
 *
 * Apres `RetirementSystem` : ce systeme lit `Contract`, et
 * `RetirementSystem` le retire sur retraite. `PipelineInvariantsTest`
 * interdit a un systeme de lire un composant qu'un systeme plus loin dans
 * le pipeline retire - `FinanceSystem` doit donc venir apres, jamais avant.
 *
 * ## `MonetaryMass`, et pourquoi pas un test qui recalcule analytiquement
 *
 * `MonetaryMass` (premier singleton du domaine football) accumule les
 * memes montants que ceux ecrits dans `Finances`, dans le meme appel :
 * c'est un sous-produit direct de la boucle, jamais une reconstruction
 * independante. La population sous contrat n'est pas un input
 * deterministe (intake et retraite sont stochastiques,
 * `Football\YouthIntakeSystem`/`Football\RetirementSystem`) - un test qui
 * recalculerait le total attendu a partir du seul `Ruleset` devrait donc
 * dupliquer le suivi de population que fait deja `Harness\Metrics\Sampler`
 * ailleurs, avec son propre risque de divergence.
 *
 * ## Pas d'evenement emis
 *
 * Un versement de salaire ou un credit de saison est de la comptabilite de
 * routine : ni seuil comportemental franchi, ni irreversible, ni
 * racontable (docs/16-evenements-et-cascades.md §2), et aucun consommateur
 * n'existe encore. Emettre un Fait par joueur et par semaine sur 20 saisons
 * reproduirait exactement le piege que docs/15- §5 proscrit ("3 millions
 * d'evenements de bruit par saison").
 *
 * ## Limite connue, non corrigee dans ce lot
 *
 * Ce systeme credite tous les clubs portant `Finances` a chaque
 * `SeasonStarted`, sans distinguer de competition - correct tant qu'une
 * seule competition existe (Phase 0/1). Si une deuxieme competition demarre
 * sa saison le meme tick, chaque club serait credite deux fois : meme
 * limite, deja documentee, que `Football\CalendarSystem` aujourd'hui. A
 * revisiter quand `CompetitionMembership` existera.
 */
final class FinanceSystem implements System
{
    public function id(): string
    {
        return 'finance';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Finances::class,
            Contract::class,
            MonetaryMass::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            Finances::class,
            MonetaryMass::class,
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
            SeasonStarted::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if (!$event instanceof SeasonStarted) {
            return;
        }

        $finance = $ctx->ruleset()->balance->finance;
        $injected = 0;

        foreach ($ctx->components(Finances::class)->entities() as $clubId) {
            $finances = $ctx->components(Finances::class)->get($clubId);

            if ($finances === null) {
                continue;
            }

            $ctx->components(Finances::class)->set($clubId, new Finances($finances->balanceCents + $finance->clubIncomePerSeasonCents));
            $injected += $finance->clubIncomePerSeasonCents;
        }

        $mass = $ctx->singleton(MonetaryMass::class) ?? new MonetaryMass();
        $ctx->setSingleton(new MonetaryMass($mass->totalInjectionsCents + $injected, $mass->totalSinksCents));
    }

    public function update(SystemContext $ctx): void
    {
        $finance = $ctx->ruleset()->balance->finance;

        if ($ctx->tick % 7 !== $finance->wagePaymentDayOfWeek) {
            return;
        }

        $paid = 0;

        foreach ($ctx->components(Contract::class)->entities() as $playerId) {
            $contract = $ctx->components(Contract::class)->get($playerId);

            if ($contract === null) {
                continue;
            }

            $finances = $ctx->components(Finances::class)->get($contract->clubId);

            if ($finances === null) {
                continue;
            }

            $ctx->components(Finances::class)->set($contract->clubId, new Finances($finances->balanceCents - $contract->wagePerWeekCents));
            $paid += $contract->wagePerWeekCents;
        }

        $mass = $ctx->singleton(MonetaryMass::class) ?? new MonetaryMass();
        $ctx->setSingleton(new MonetaryMass($mass->totalInjectionsCents, $mass->totalSinksCents + $paid));
    }
}

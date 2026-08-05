<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\PlayerRetired;

/**
 * La relation d'emploi entre une personne et un club : seul writer et seul
 * remover de `Contract` et `SquadMembership`, purement reactif, aucun RNG,
 * aucune decision.
 *
 * C'est la moitie "appliquer tot" du couple qu'il forme avec
 * `Football\ContractSystem` (docs/13- §2, canal 2). Le decoupage n'est pas un
 * choix de style : `ContractSystem` doit lire les competences et `Finances`,
 * donc venir apres leurs writers ; ces deux composants-ci doivent etre ecrits
 * avant `TrainingSystem` et `MatchSystem`, qui les lisent. Aucun ordre de
 * pipeline ne satisfait les deux a la fois - le detail complet est dans le
 * docblock de `Football\Events\ContractSigned`, meme mur que
 * `ClubInvestedInFacilities`.
 *
 * ## Ce systeme ne decide rien
 *
 * Il n'a ni `Ruleset` a lire ni condition metier a evaluer : chaque Fait
 * qu'il recoit porte deja la decision prise (quel club, quel salaire, quelle
 * echeance). C'est ce qui le rend trivialement verifiable et ce qui garde la
 * politique de renouvellement dans un seul endroit.
 *
 * ## Il possede aussi la sortie de carriere
 *
 * `PlayerRetired` est traite ici, et **plus** dans `Football\RetirementSystem`
 * qui retirait `Contract` lui-meme jusqu'a ce lot. Deux removers du meme
 * composant sont interdits (`Football\PipelineInvariantsTest`), et entre les
 * deux proprietaires possibles celui-ci est le bon : `RetirementSystem`
 * possede l'archetype "joueur" (competences et potentiels), ce systeme
 * possede la relation d'emploi. La frontiere est nette et se tient quand des
 * roles non-joueurs existeront (un entraineur aussi a un employeur).
 *
 * Consequence a connaitre : le Fait arrivant au tick suivant, un retraite
 * garde son contrat un tick de plus qu'avant. Il peut donc etre paye une
 * derniere fois si sa retraite tombe le jour de paie - un versement reel,
 * comptabilise comme puits par `Football\FinanceSystem`, donc sans effet sur
 * l'invariant de conservation monetaire. En echange, ce systeme corrige la
 * limite inverse qui trainait : un retraite conservait indefiniment son
 * `SquadMembership`.
 *
 * ## Position dans le pipeline
 *
 * Juste apres `YouthIntakeSystem`, donc avant `TrainingSystem` (qui lit
 * `SquadMembership`) et avant `FinanceSystem` (qui lit `Contract`). Les
 * mouvements du mercato sont ainsi visibles par tout le reste du tick :
 * un joueur qui change de club s'entraine des ce jour-la dans ses nouvelles
 * installations.
 *
 * `YouthIntakeSystem` reste le `creates()` de ces deux composants et ce
 * systeme leur `writes()`/`removes()` : les deux categories sont verifiees
 * separement, et un createur ne pose ses composants que sur des entites qui
 * n'existaient pas quand qui que ce soit a itere.
 */
final class SquadSystem implements System
{
    public function id(): string
    {
        return 'squad';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Contract::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            Contract::class,
            SquadMembership::class,
        ];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [
            Contract::class,
            SquadMembership::class,
        ];
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
            ContractSigned::class,
            ContractExpired::class,
            PlayerRetired::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if ($event instanceof ContractSigned) {
            // `signedOn` est le tick de l'**application**, pas celui de la
            // decision : l'ecart est d'un tick (docs/13- §2, un evenement n'est
            // jamais traite dans le tick qui l'a produit), et c'est celui-la que
            // le monde doit retenir. L'evenement `ContractSigned` n'a donc rien
            // a porter de plus - le systeme applicateur connait le tick.
            $ctx->write(Contract::class)->set($event->playerId, new Contract(
                $event->clubId,
                $event->wagePerWeekCents,
                new SimDate($event->expiresOnEpochDay),
                new SimDate($ctx->tick),
            ));
            $ctx->write(SquadMembership::class)->set($event->playerId, new SquadMembership($event->clubId));

            return;
        }

        if ($event instanceof ContractExpired) {
            $this->release($ctx, $event->playerId, $event->clubId);

            return;
        }

        if ($event instanceof PlayerRetired) {
            $contract = $ctx->read(Contract::class)->get($event->playerId);
            $this->release($ctx, $event->playerId, $contract?->clubId);
        }
    }

    public function update(SystemContext $ctx): void
    {
    }

    /**
     * Delie un joueur de son club.
     *
     * Le club attendu est verifie avant de retirer quoi que ce soit : entre
     * l'emission du Fait et son traitement, un tick s'est ecoule, et un Fait
     * perime ne doit pas defaire un engagement plus recent. Aucun chemin ne
     * produit ce cas aujourd'hui (`ContractSystem` n'emet jamais les deux
     * Faits pour le meme joueur, cf. le docblock de `ContractExpired`), mais
     * l'ecart d'un tick est structurel et cette garde coute une comparaison.
     *
     * Un `$expectedClubId` nul - un retraite qui n'avait deja plus de contrat -
     * retire sans verifier : il n'y a rien a contredire.
     */
    private function release(SystemContext $ctx, int $playerId, ?int $expectedClubId): void
    {
        $contract = $ctx->read(Contract::class)->get($playerId);

        if ($contract !== null && $expectedClubId !== null && $contract->clubId !== $expectedClubId) {
            return;
        }

        $ctx->write(Contract::class)->remove($playerId);
        $ctx->write(SquadMembership::class)->remove($playerId);
    }
}

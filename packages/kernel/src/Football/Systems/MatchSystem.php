<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Generation\PoissonMatchEngine;
use Flair\Kernel\Football\Support\PositionModel;

/**
 * Joue les matchs du jour (docs/15- §4) : purement reactif, aucune logique
 * periodique - un match n'existe que parce qu'un `FixtureKickoff` arrive a
 * echeance (docs/13- §3).
 *
 * ## Force de club : les onze alignes, par poste
 *
 * Un club est note sur **le onze qu'il alignerait**, compose poste par poste
 * selon la formation du `Ruleset` (`PositionBalance`), et non plus sur la
 * moyenne de tout son effectif. L'ancienne moyenne donnait a un joueur sous le
 * niveau du groupe une valeur marginale **negative** : recruter un joueur de
 * rotation faisait baisser la note du club. Le profil correct est positif sur
 * les onze premiers et **nul** au-dela - la profondeur ne vaut rien plutot que
 * de couter, sa vraie valeur supposant blessures et rotation, qui n'existent
 * pas.
 *
 * Cette correction est une **precondition du marche** (docs/14- §5) plus
 * qu'une correction d'equilibre : un acheteur doit pouvoir repondre "ce joueur
 * vaut-il son prix pour moi ?", et cette reponse se fonde sur la contribution
 * marginale a la force de l'equipe.
 *
 * ## Un seul onze pour les deux notes
 *
 * Les places sont remplies **gloutonnement**, du poste le plus specialise au
 * moins specialise (gardien, attaquant, defenseur, milieu) : chaque place
 * prend le meilleur joueur restant a sa note **a ce poste-la**
 * (`Football\Support\PositionModel`). Un joueur ne peut occuper qu'une place.
 *
 * Le glouton n'est pas l'affectation optimale, et c'est assume : un vrai club
 * n'aligne pas non plus le onze mathematiquement optimal, et le cout d'un
 * algorithme hongrois ne se justifie pas ici.
 *
 * Les deux notes que Dixon-Coles attend (docs/14- §1) sont ensuite des
 * **moyennes ponderees sur ce meme onze**, chaque place contribuant selon son
 * poste (`PositionModel::sectorWeights()`) : un gardien pese en defense et pas
 * du tout en attaque, un attaquant l'exact miroir. Composer **deux** onze -
 * les onze meilleurs attaquants pour l'attaque, les onze meilleurs defenseurs
 * pour la defense - serait l'erreur miroir de celle qu'on corrige : un club
 * alignerait vingt-deux joueurs et un gros effectif redeviendrait meilleur.
 *
 * Les poids somment a 1 par poste et la moyenne est normalisee, donc les notes
 * restent sur l'echelle absolue 1-100 des competences (docs/12- §5) que
 * `PoissonMatchEngine` attend.
 *
 * ## Moins de onze joueurs
 *
 * Les places vides comptent a `REPLACEMENT_RATING`, le **plancher de
 * l'echelle**, et non a une valeur "realiste" : le joueur le plus faible du
 * monde note environ 20, donc toute constante realiste rendrait le onzieme
 * joueur inutile et ferait revenir le bug qu'on corrige. Ancree au plancher,
 * la propriete "un vrai joueur vaut toujours mieux qu'une place vide" est
 * vraie inconditionnellement.
 *
 * Un club incapable d'aligner onze joueurs devrait declarer forfait et
 * s'exposer a une sanction. Ce n'est pas modelise, et c'est assume : le monde
 * n'a qu'une division (rien ou releguer) et aucun mecanisme de defaillance de
 * club - faillite, dissolution et sanction relevent de la gouvernance de club
 * (docs/14- §7), hors perimetre. Le remplissage au plancher en est la
 * degenerescence la plus proche : le club est ecrase, ce qui echoue dans la
 * bonne direction plutot que de le rendre silencieusement plus fort. Le cas ne
 * se produit d'ailleurs jamais - effectif minimum 12 mesure sur 900
 * club-annees - et un invariant du harness le verifie en continu.
 *
 * Ce remplissage **absorbe le cas de l'effectif vide**, qui etait traite a
 * part par un rating neutre a 50.0 : un effectif vide, ce sont onze places de
 * remplacement. Une regle au lieu de deux.
 *
 * ## `MatchResult` en plus de `MatchPlayed`
 *
 * Ecrit `MatchResult` sur l'entite fixture (canal 1 - `Football\CompetitionSystem`,
 * declare juste apres dans le pipeline, doit alimenter le classement le
 * jour meme, docs/13- §2) **et** emet le Fait `MatchPlayed` (canal 2 - tout
 * consommateur qui n'a pas besoin d'une resolution le jour meme, comme un
 * futur digest narratif, docs/14- §9).
 */
final class MatchSystem implements System
{
    /**
     * Le plancher de l'echelle des competences (docs/12- §5), pas une note
     * "realiste" de remplacant - voir le docblock de la classe.
     */
    private const REPLACEMENT_RATING = 1.0;

    /** Du poste le plus specialise au moins specialise (voir `ratings()`). */
    private const SELECTION_ORDER = [
        Position::Goalkeeper,
        Position::Attacker,
        Position::Defender,
        Position::Midfielder,
    ];

    public function __construct(
        private readonly PoissonMatchEngine $engine = new PoissonMatchEngine(),
    ) {
    }

    public function id(): string
    {
        return 'match';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            SquadMembership::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            MatchResult::class,
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
            FixtureKickoff::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if (!$event instanceof FixtureKickoff) {
            return;
        }

        [$attackHome, $defenseHome] = $this->ratings($ctx, $event->homeClubId);
        [$attackAway, $defenseAway] = $this->ratings($ctx, $event->awayClubId);

        $rng = $ctx->rng($event->fixtureId);
        $score = $this->engine->play($rng, $attackHome, $defenseHome, $attackAway, $defenseAway, $ctx->ruleset()->balance->match);

        $ctx->write(MatchResult::class)->set($event->fixtureId, new MatchResult(
            $event->competitionId,
            $event->homeClubId,
            $event->awayClubId,
            $event->matchday,
            $score->homeGoals,
            $score->awayGoals,
        ));

        $ctx->emit(new MatchPlayed(
            $event->fixtureId,
            $event->competitionId,
            $event->homeClubId,
            $event->awayClubId,
            $score->homeGoals,
            $score->awayGoals,
        ), entityId: $event->fixtureId);
    }

    public function update(SystemContext $ctx): void
    {
    }

    /** @return array{0: float, 1: float} [attackRating, defenseRating] */
    private function ratings(SystemContext $ctx, int $clubId): array
    {
        $positions = $ctx->ruleset()->balance->position;
        $ratings = $this->squadRatings($ctx, $clubId);

        $attackSum = 0.0;
        $defenseSum = 0.0;
        $attackWeight = 0.0;
        $defenseWeight = 0.0;

        // Ordre du plus specialise au moins specialise : un gardien ne se
        // remplace pas, un milieu si. Le glouton doit donc servir les postes
        // rares en premier, sinon un excellent gardien finirait milieu.
        foreach (self::SELECTION_ORDER as $position) {
            [$toAttack, $toDefense] = PositionModel::sectorWeights($position);

            for ($slot = 0; $slot < PositionModel::slots($position, $positions); $slot++) {
                $rating = $this->takeBest($ratings, $position);

                $attackSum += $toAttack * $rating;
                $defenseSum += $toDefense * $rating;
                $attackWeight += $toAttack;
                $defenseWeight += $toDefense;
            }
        }

        return [
            $attackWeight > 0.0 ? $attackSum / $attackWeight : self::REPLACEMENT_RATING,
            $defenseWeight > 0.0 ? $defenseSum / $defenseWeight : self::REPLACEMENT_RATING,
        ];
    }

    /**
     * La note de chaque joueur de l'effectif a chacun des quatre postes.
     *
     * Iteration sur `SquadMembership` triee par `EntityId` croissant
     * (`ComponentStore::entities()`), ce qui donne l'ordre total dont
     * `takeBest()` a besoin pour departager les egalites (docs/12- §2).
     *
     * @return array<int, array<string, float>> playerId -> [valeur du poste -> note]
     */
    private function squadRatings(SystemContext $ctx, int $clubId): array
    {
        $ratings = [];

        foreach ($ctx->read(SquadMembership::class)->entities() as $playerId) {
            $membership = $ctx->read(SquadMembership::class)->get($playerId);

            if ($membership === null || $membership->clubId !== $clubId) {
                continue;
            }

            $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
            $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
            $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);

            if ($physical === null || $technical === null || $mental === null) {
                continue;
            }

            foreach (Position::cases() as $position) {
                $ratings[$playerId][$position->value] = PositionModel::ratingAt($position, $physical, $technical, $mental);
            }
        }

        return $ratings;
    }

    /**
     * Sort de l'effectif le meilleur joueur restant a ce poste et rend sa
     * note, ou `REPLACEMENT_RATING` s'il ne reste personne - une place vide.
     *
     * Le joueur retenu est **retire** du tableau : un joueur n'occupe qu'une
     * place, ce qui est exactement ce qui empeche un club d'aligner ses onze
     * meilleurs attaquants **et** ses onze meilleurs defenseurs.
     *
     * @param array<int, array<string, float>> $ratings
     */
    private function takeBest(array &$ratings, Position $position): float
    {
        $bestId = null;
        $best = 0.0;

        foreach ($ratings as $playerId => $byPosition) {
            // `>` strict sur un tableau deja trie par EntityId croissant : a
            // note egale, le plus petit identifiant gagne.
            if ($bestId === null || $byPosition[$position->value] > $best) {
                $bestId = $playerId;
                $best = $byPosition[$position->value];
            }
        }

        if ($bestId === null) {
            return self::REPLACEMENT_RATING;
        }

        unset($ratings[$bestId]);

        return $best;
    }
}

<?php

declare(strict_types=1);

namespace Flair\Api\Read\Digest;

use Flair\Api\Format\Money;
use Flair\Api\Read\History\ClubMentions;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;

/**
 * Un Fait, en une phrase francaise.
 *
 * ## Pourquoi ce n'est pas un moteur de detecteurs
 *
 * docs/14- §9 esquisse des detecteurs declaratifs en JSON, facon Crusader
 * Kings (`{"when": {"event": "PlayerDebut", "playerAge": {"<": 18}}, "template":
 * "..."}`). **C'est le `NarrativeSystem` de la Phase 6, pas ce lot** : un moteur
 * de regles avec un seul consommateur serait une abstraction posee par
 * anticipation, exactement l'ecart deja tranche au point 3 du lot marche
 * (interface de domaine plutot que le `WorldView` generique de l'esquisse).
 *
 * Ici, un `match` sur classe qui lit l'objet **typé** rehydrate par
 * `Host\Store\EventStore::between()`. Le jour ou les motifs se multiplieront et
 * ou plusieurs d'entre eux voudront la meme phrase, le moteur aura deux
 * consommateurs reels et deviendra justifiable.
 *
 * ## Les phrases vivent ici et pas dans une vue Blade
 *
 * `src/` est du PHP nu (cf. `Tests\Architecture\ReadLayerStaysFrameworkFreeTest`),
 * donc la page **et** la route JSON portent exactement les memes chaines, et
 * `Tests\Http\PagesMatchJsonTest` peut exiger de retrouver dans l'une ce que
 * l'autre annonce. Une phrase composee dans un template serait invisible du
 * JSON, donc invisible de ce test.
 */
final readonly class FactSentence
{
    public function __construct(private ClubMentions $mentions = new ClubMentions())
    {
    }

    /**
     * `$clubId` est le point de vue : la meme rencontre se raconte « bat » ou
     * « s'incline » selon le club qui lit. `null` pour le bloc « le monde », ou
     * personne n'est chez soi.
     */
    public function of(WorldState $state, DomainEvent $event, ?int $clubId): string
    {
        return match (true) {
            $event instanceof MatchPlayed => $this->match($state, $event, $clubId),
            $event instanceof SeasonConcluded => $this->season($state, $event, $clubId),
            $event instanceof TransferAgreed => $this->transfer($state, $event, $clubId),
            $event instanceof ContractSigned => $this->signing($state, $event),
            $event instanceof ContractExpired => sprintf(
                'Le contrat de %s avec %s arrive a son terme.',
                $this->person($state, $event->playerId),
                $this->club($state, $event->clubId),
            ),
            $event instanceof YouthPlayerPromoted => sprintf(
                '%s monte du centre de formation de %s.',
                $this->person($state, $event->playerId),
                $this->club($state, $event->clubId),
            ),
            $event instanceof PlayerRetired => $this->retirement($state, $event),
            $event instanceof ClubInvestedInFacilities => sprintf(
                '%s investit %s dans ses installations.',
                $this->club($state, $event->clubId),
                Money::roundEuros($event->cents),
            ),

            // Inatteignable en pratique : `FactAmplitude` met a zero tout ce qui
            // n'est pas traite ci-dessus, et un Fait a amplitude nulle n'arrive
            // jamais jusqu'ici. La branche existe pour que la fonction reste
            // totale, et le test d'exhaustivite pour que le couple des deux
            // fichiers ne diverge pas.
            default => 'Evenement sans recit.',
        };
    }

    private function match(WorldState $state, MatchPlayed $event, ?int $clubId): string
    {
        $home = $this->club($state, $event->homeClubId);
        $away = $this->club($state, $event->awayClubId);
        $score = "{$event->homeGoals}-{$event->awayGoals}";

        if ($clubId === null) {
            return "{$home} {$score} {$away}.";
        }

        $isHome = $event->homeClubId === $clubId;
        $mine = $isHome ? $event->homeGoals : $event->awayGoals;
        $theirs = $isHome ? $event->awayGoals : $event->homeGoals;
        $opponent = $isHome ? $away : $home;
        $where = $isHome ? 'a domicile' : 'a l\'exterieur';

        if ($mine > $theirs) {
            return sprintf('Large victoire %s contre %s (%d-%d).', $where, $opponent, $mine, $theirs);
        }

        if ($mine < $theirs) {
            return sprintf('Lourde defaite %s contre %s (%d-%d).', $where, $opponent, $mine, $theirs);
        }

        return sprintf('Match spectaculaire %s contre %s (%d-%d).', $where, $opponent, $mine, $theirs);
    }

    private function season(WorldState $state, SeasonConcluded $event, ?int $clubId): string
    {
        $table = $event->finalTable;
        $count = count($table);

        if ($clubId === null) {
            $champion = $table === [] ? null : $table[0]->clubId;

            return $champion === null
                ? 'La saison s\'acheve.'
                : sprintf('%s termine champion.', $this->club($state, $champion));
        }

        $rank = $this->mentions->rankIn($event, $clubId);
        $line = $this->mentions->lineIn($event, $clubId);

        if ($rank === null || $line === null) {
            return 'La saison s\'acheve.';
        }

        $verdict = match (true) {
            $rank === 1 => 'Champion',
            $rank <= 3 => 'Sur le podium',
            $rank === $count => 'Dernier',
            default => sprintf('%de', $rank),
        };

        return sprintf(
            '%s : la saison s\'acheve a la %de place sur %d, avec %d points.',
            $verdict,
            $rank,
            $count,
            $line->points,
        );
    }

    private function transfer(WorldState $state, TransferAgreed $event, ?int $clubId): string
    {
        $player = $this->person($state, $event->playerId);
        $buyer = $this->club($state, $event->buyerClubId);
        $seller = $this->club($state, $event->sellerClubId);
        $fee = Money::roundEuros($event->agreedPriceCents);

        if ($clubId === $event->buyerClubId) {
            return sprintf('%s arrive de %s pour %s.', $player, $seller, $fee);
        }

        if ($clubId === $event->sellerClubId) {
            return sprintf('%s part a %s pour %s.', $player, $buyer, $fee);
        }

        return sprintf('%s passe de %s a %s pour %s.', $player, $seller, $buyer, $fee);
    }

    private function signing(WorldState $state, ContractSigned $event): string
    {
        $player = $this->person($state, $event->playerId);
        $club = $this->club($state, $event->clubId);
        $wage = Money::roundEuros($event->wagePerWeekCents);

        if ($event->previousClubId === $event->clubId) {
            return sprintf('%s prolonge avec %s (%s par semaine).', $player, $club, $wage);
        }

        if ($event->previousClubId === null) {
            return sprintf('%s, sans club, signe a %s (%s par semaine).', $player, $club, $wage);
        }

        return sprintf(
            '%s quitte %s pour %s (%s par semaine).',
            $player,
            $this->club($state, $event->previousClubId),
            $club,
            $wage,
        );
    }

    private function retirement(WorldState $state, PlayerRetired $event): string
    {
        $player = $this->person($state, $event->playerId);

        if ($event->clubId === null) {
            return sprintf('%s met un terme a sa carriere a %d ans, sans club.', $player, $event->ageYears);
        }

        return sprintf(
            '%s raccroche a %d ans, sous les couleurs de %s.',
            $player,
            $event->ageYears,
            $this->club($state, $event->clubId),
        );
    }

    /**
     * Le nom reste lisible pour un joueur parti ou retraite parce que
     * `Football\RetirementSystem::removes()` ne retire pas `Person` - la
     * retention notee en R1 dans docs/18- a ici son second usage reel.
     */
    private function person(WorldState $state, int $playerId): string
    {
        return $state->components(Person::class)->get($playerId)->name ?? "Joueur {$playerId}";
    }

    private function club(WorldState $state, int $clubId): string
    {
        return $state->components(Club::class)->get($clubId)->name ?? "Club {$clubId}";
    }
}

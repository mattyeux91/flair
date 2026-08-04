<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\Generation\PlayerFactory;

/**
 * L'arrivee des jeunes (docs/12- §7, docs/15- §4) : purement periodique,
 * aucun evenement ecoute. Un jour precis de l'annee simulee
 * (`YouthIntakeBalance::$intakeDayOfYear`), chaque club promeut une
 * poignee de joueurs neufs dans son effectif.
 *
 * **Ferme la boucle demographique.** Sans lui, `RetirementSystem` ne fait
 * que vider la population et le premier critere de sortie de la Phase 0
 * ("pyramide des ages stationnaire sur 20 saisons", docs/15- §4) est
 * structurellement inatteignable.
 *
 * ## Par club, pas par monde
 *
 * Le flux RNG est derive de `(worldSeed, tick, systemId, entityId)`
 * (docs/13- §4.1) - or ce systeme cree des entites qui n'ont pas encore
 * d'identifiant au moment du tirage. La cle ne peut donc pas etre le joueur
 * produit : c'est le **club producteur** (`$ctx->rng($clubId)`). Ce qui
 * tombe juste sur le plan du domaine, un centre de formation appartenant a
 * un club, et permet de repartir la promotion selon `Facilities` (voir
 * `averageQuality()` : une part d'un vivier national de taille fixe, jamais
 * un multiplicateur du volume total).
 *
 * Ce n'est pas qu'une commodite d'implementation : l'alternative - un
 * intake mondial asservi a une cible de population - **garantirait** la
 * stationnarite par construction. On mesurerait alors son propre
 * regulateur, et le critere de sortie de la Phase 0 serait vide de sens.
 * Ici la stationnarite reste une propriete emergente, a verifier.
 *
 * ## Cadence
 *
 * `tick % intakeDayOfYear` plutot qu'une probabilite quotidienne facon
 * `RetirementSystem` : une cohorte discrete arrivant le meme jour rend la
 * pyramide des ages immediatement lisible, la ou des arrivees au
 * compte-goutte toute l'annee la brouillent. C'est aussi ce que fait le
 * vrai football - non parce que les jeunes naissent ce jour-la, mais parce
 * que l'entree dans la population professionnelle est bornee par le
 * calendrier administratif (bascule de saison au 1er juillet en Europe).
 * Voir `YouthIntakeBalance::$intakeDayOfYear` pour le caractere provisoire
 * du modulo.
 *
 * ## Position dans le pipeline
 *
 * En premier. Les joueurs promus font partie du monde des ce tick :
 * `TrainingSystem` lit leur `SquadMembership` et `PlayerDevelopmentSystem`
 * leurs competences dans le meme tick, par le canal 1 (composant ecrit tot,
 * lu plus loin - docs/13- §2). Aucune inversion de dependance : ce systeme
 * n'ecrit que sur des entites qui n'existaient pas quand qui que ce soit a
 * itere.
 *
 * Seul createur de joueurs a l'execution (`creates()`, cf. le docblock de
 * `System`) : il ne `set()` jamais un composant d'une entite preexistante,
 * ce qui le laisse coexister avec `PlayerDevelopmentSystem`, seul writer
 * des competences, sans violer l'invariant "un seul writer" (docs/13- §2).
 */
final class YouthIntakeSystem implements System
{
    public function __construct(
        private readonly PlayerFactory $players = new PlayerFactory(),
    ) {
    }

    public function id(): string
    {
        return 'youth-intake';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Club::class,
            Facilities::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [
            Person::class,
            PlayerPotentials::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
            SquadMembership::class,
            Contract::class,
        ];
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
        $intake = $ctx->ruleset()->balance->youthIntake;

        if ($ctx->tick % 365 !== $intake->intakeDayOfYear) {
            return;
        }

        $birthDate = new SimDate((int) round($ctx->tick - $intake->intakeAgeYears * 365));
        $clubIds = $ctx->read(Club::class)->entities();
        $averageQuality = $this->averageQuality($ctx, $clubIds);

        foreach ($clubIds as $clubId) {
            $rng = $ctx->rng($clubId);
            $share = $this->quality($ctx, $clubId) / $averageQuality;

            $count = $this->cohortSize($intake->baseIntakePerClub * $share, $rng);

            for ($i = 0; $i < $count; $i++) {
                $this->promote($ctx, $clubId, $birthDate, $intake, $rng);
            }
        }
    }

    /**
     * Un club sans `Facilities` a quand meme un centre de formation moyen :
     * qualite neutre plutot qu'exclusion. Meme defaut que `TrainingSystem`,
     * qui saute ces clubs et laisse `PlayerDevelopmentSystem` appliquer le
     * sien.
     */
    private function quality(SystemContext $ctx, int $clubId): float
    {
        return $ctx->read(Facilities::class)->get($clubId)->quality ?? 1.0;
    }

    /**
     * Qualite moyenne du monde, denominateur de la part de chaque club.
     *
     * **Le vivier national est de taille fixe** : un club promeut
     * `baseIntakePerClub x quality / moyenne`, donc le total du monde vaut
     * toujours `baseIntakePerClub x nombre de clubs`, quelles que soient les
     * installations. Les bons centres captent une plus grosse *part* du
     * vivier, ils ne l'agrandissent pas.
     *
     * Ce n'est pas une subtilite d'equilibrage, c'est ce qui empeche le monde
     * d'osciller. Depuis que `Football\FacilitiesSystem` rend les
     * installations dynamiques, une modulation non normalisee refermerait la
     * boucle `installations -> jeunes -> effectif -> masse salariale ->
     * argent -> installations`, dont le retour porte le **delai d'une
     * carriere** (~15 ans). Une contre-reaction retardee de ce gain oscille -
     * mesure a l'appui, la population balancait entre 224 et 381 sur 60
     * saisons, et deux calibrages successifs n'en ont change que l'amplitude,
     * jamais l'existence. La normalisation coupe le lien entre installations
     * et effectif **total** tout en gardant l'effet entre clubs.
     *
     * Se defend aussi dans la fiction : le nombre de jeunes talentueux d'un
     * pays tient a sa demographie, pas a la generosite de ses clubs - ceux-ci
     * se disputent lesquels percent.
     *
     * Somme dans l'ordre de `$clubIds` (deja trie par `EntityId` croissant) :
     * une somme de flottants n'est pas associative, l'ordre fait donc partie
     * du determinisme, pas seulement de la convention (docs/12- §2).
     *
     * @param list<int> $clubIds trie par EntityId croissant
     */
    private function averageQuality(SystemContext $ctx, array $clubIds): float
    {
        if ($clubIds === []) {
            return 1.0;
        }

        $total = 0.0;
        foreach ($clubIds as $clubId) {
            $total += $this->quality($ctx, $clubId);
        }

        // `Facilities::MIN_QUALITY` etant strictement positif et le defaut
        // neutre valant 1.0, la moyenne ne peut pas etre nulle.
        return $total / \count($clubIds);
    }

    /**
     * Arrondi stochastique de l'effectif attendu : `floor(x)` joueurs, plus
     * un de plus avec la probabilite `x - floor(x)`. Un `round()` sec
     * ecraserait la calibration - avec 1,2 attendu, tous les clubs
     * promouvraient exactement 1 joueur et `baseIntakePerClub` n'aurait plus
     * aucun effet entre 0,5 et 1,5. Ici l'esperance reste exactement `x`
     * malgre des cohortes forcement entieres.
     */
    private function cohortSize(float $expected, Rng $rng): int
    {
        $guaranteed = (int) floor(max(0.0, $expected));
        $remainder = max(0.0, $expected) - $guaranteed;
        $roll = $rng->nextUint32() % 10_000;

        return $guaranteed + ($roll < (int) ($remainder * 10_000) ? 1 : 0);
    }

    /**
     * Le contrat d'une recrue est au **salaire forfaitaire** de
     * `YouthIntakeBalance::$basePlayerWagePerWeekCents`, jamais indexe sur sa
     * qualite par `Football\Support\WageModel` comme le sont les
     * renouvellements et les signatures de `Football\ContractSystem`. Ce n'est
     * pas un oubli : un premier contrat d'academie est standardise dans le
     * vrai football, et le joueur passe au prix du marche a son premier
     * renouvellement - ce qui donne au centre de formation son interet
     * economique, quelques annees de talent paye en dessous de sa valeur.
     *
     * La duree, elle, est tiree comme partout ailleurs
     * (`ContractBalance::$minDurationYears`) : sans terme, le jeune ne
     * reviendrait jamais sur le marche.
     */
    private function promote(SystemContext $ctx, int $clubId, SimDate $birthDate, YouthIntakeBalance $intake, Rng $rng): void
    {
        $playerId = $ctx->createEntity();
        $blueprint = $this->players->drawRookie($rng, "Joueur {$playerId}", $birthDate, $intake);
        $contract = $ctx->ruleset()->balance->contract;
        $shortest = max(1, $contract->minDurationYears);
        $longest = max($shortest, $contract->maxDurationYears);
        $years = $shortest + (int) ($rng->nextUint32() % ($longest - $shortest + 1));

        $ctx->write(Person::class)->set($playerId, $blueprint->person);
        $ctx->write(PlayerPotentials::class)->set($playerId, $blueprint->potentials);
        $ctx->write(PlayerPhysicalSkills::class)->set($playerId, $blueprint->physical);
        $ctx->write(PlayerTechnicalSkills::class)->set($playerId, $blueprint->technical);
        $ctx->write(PlayerMentalSkills::class)->set($playerId, $blueprint->mental);
        $ctx->write(SquadMembership::class)->set($playerId, new SquadMembership($clubId));
        $ctx->write(Contract::class)->set($playerId, new Contract(
            $clubId,
            $intake->basePlayerWagePerWeekCents,
            new SimDate($ctx->tick + $years * 365),
        ));

        $ctx->emit(new YouthPlayerPromoted($playerId, $clubId), entityId: $playerId);
    }
}

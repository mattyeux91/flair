<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Generation\PlayerFactory;
use Flair\Kernel\Football\Support\PositionModel;
use Flair\Kernel\Football\Support\WageModel;

/**
 * Construit la population initiale du harness : des clubs synthetiques
 * (Population\ClubFactory), une competition synthetique qui les regroupe
 * (Population\CompetitionFactory - sans elle, `Football\CalendarSystem` n'a
 * aucun calendrier a generer meme si des clubs existent) et des joueurs deja
 * en cours de carriere (17-34 ans), la ou `YouthIntakeSystem` ne produit que
 * des recrues de 17 ans. Chaque joueur recoit un `SquadMembership`
 * (repartition round-robin sur les clubs crees) - sans ca,
 * `Football\TrainingSystem` et `Football\YouthIntakeSystem` n'ont rien a
 * lire ni ou promouvoir (cf. docblock ClubFactory).
 *
 * La competition n'est creee que si `$spec->clubCount > 0` - meme condition
 * que les clubs eux-memes, pas un nouveau flag sur `PopulationSpec` : une
 * competition sans le moindre club n'a aucun sens et `CalendarSystem`
 * degenererait de toute facon en zero fixture.
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
 * debutant - et un joueur genere a 34 ans a deja depasse son pic, il ne
 * demarre pas non plus au `ceiling`. Approximation assumee du premier jet :
 * une interpolation triangulaire (montee lineaire du niveau de recrue vers
 * le `ceiling` jusqu'au pic physique, puis descente symetrique vers le
 * niveau de recrue au-dela) - `PlayerDevelopmentSystem` suivrait une
 * trajectoire plus fine (stochastique, calibree par `PlayerDevelopmentBalance`),
 * mais le rejouer ici reviendrait a en dupliquer la logique et a coupler
 * cette classe a des parametres non calibres (Phase 1).
 */
final class PopulationFactory
{
    private const YOUNGEST_START_AGE = 17.0;
    private const OLDEST_START_AGE = 34.0;

    /**
     * Taille du cycle de distribution des archetypes. Vingt, soit la cible
     * d'effectif par defaut (`ContractBalance::$targetSquadSize`) : un cycle
     * complet compose donc un effectif entier plausible.
     */
    private const DEAL_SIZE = 20;

    public function __construct(
        private readonly PlayerFactory $players = new PlayerFactory(),
        private readonly ClubFactory $clubs = new ClubFactory(),
        private readonly CompetitionFactory $competitions = new CompetitionFactory(),
        private readonly StaffFactory $staff = new StaffFactory(),
    ) {
    }

    /** @return list<int> identifiants des entites joueur creees */
    public function populate(WorldState $world, PopulationSpec $spec, int $atTick = 1, ?YouthIntakeBalance $talent = null, ?ContractBalance $contracts = null, ?PositionBalance $positions = null): array
    {
        $rng = new Rng($spec->seed);
        $talent ??= new YouthIntakeBalance();
        $contracts ??= new ContractBalance();
        $positions ??= new PositionBalance();

        $clubIds = $spec->clubCount > 0 ? $this->clubs->create($world, $spec->clubCount, $spec->facilitiesQuality, $spec->startingBalanceCents) : [];
        if ($clubIds !== []) {
            $this->competitions->create($world);
        }

        // Les joueurs sont distribues aux clubs en round-robin, donc le
        // compteur par club avance d'un a chaque tour complet : c'est lui qui
        // indexe le deal d'archetypes, et non le compteur global.
        $deal = $this->archetypeDeal($positions);
        $dealt = [];

        $playerIds = [];
        for ($i = 0; $i < $spec->playerCount; $i++) {
            $clubId = $clubIds === [] ? null : $clubIds[$i % \count($clubIds)];
            $rank = $clubId === null ? $i : $dealt[$clubId] = ($dealt[$clubId] ?? -1) + 1;
            $archetype = $deal[$rank % \count($deal)];
            $playerIds[] = $this->createPlayer($world, $rng, $atTick, $talent, $contracts, $positions, $archetype, $clubId);
        }

        // Le staff **apres** les joueurs, deliberement : les identifiants des
        // entites joueur restent donc exactement ceux d'avant l'arrivee des
        // scouts, et avec eux tous les flux RNG qui en derivent. C'est ce qui
        // garde comparables les mesures deja enregistrees (docs/15- §4) au lieu
        // de decaler le monde entier pour une entite par club.
        $this->staff->create($world, $rng, $clubIds, $spec->scoutJudgementMean, $spec->scoutJudgementSpread);

        return $playerIds;
    }

    /**
     * L'ordre dans lequel les archetypes sont distribues a chaque club, cycle
     * autant de fois que necessaire.
     *
     * Impose plutot que tire, et c'est le point : un tirage independant par
     * joueur laisse, par pur hasard, des clubs entiers sans gardien - avec une
     * trentaine de joueurs par club et une part de gardiens a 10 %, environ un
     * club sur dix-huit. Un monde ne doit pas **naitre** infirme, et
     * `Harness\Tests\Regression\FieldableSquadTest` le verifie.
     *
     * Le gardien ouvre le cycle : tout club, meme minuscule, en obtient un.
     * Les proportions restent celles de `PositionBalance`, seule source de
     * verite de la composition du monde - ce sont les memes parts que suivent
     * les promotions annuelles de `Football\YouthIntakeSystem`, qui elles
     * tirent bien au hasard.
     *
     * @return non-empty-list<Position>
     */
    private function archetypeDeal(PositionBalance $positions): array
    {
        $deal = [Position::Goalkeeper];

        foreach (Position::cases() as $position) {
            $count = (int) round(PositionModel::generationShare($position, $positions) * self::DEAL_SIZE);
            $start = $position === Position::Goalkeeper ? 1 : 0;

            for ($i = $start; $i < $count; $i++) {
                $deal[] = $position;
            }
        }

        return $deal;
    }

    private function createPlayer(WorldState $world, Rng $rng, int $atTick, YouthIntakeBalance $talent, ContractBalance $contracts, PositionBalance $positions, Position $archetype, ?int $clubId): int
    {
        $entity = $world->createEntity();

        $startAge = $this->uniform($rng, self::YOUNGEST_START_AGE, self::OLDEST_START_AGE);
        $birthDay = (int) round($atTick - $startAge * 365);
        $world->components(Person::class)->set($entity, new Person("Joueur {$entity}", new SimDate($birthDay)));

        $potentials = $this->players->drawPotentials($rng, $talent, $positions, $archetype);
        $world->components(PlayerPotentials::class)->set($entity, $potentials);

        // La maturite liee a l'age est une **fraction** du plafond, appliquee
        // ensuite au plafond de chaque attribut : un joueur de 30 ans est
        // proche de son potentiel, et ce potentiel a la forme de son archetype
        // (Football\Support\PositionModel). Sans ca le genesis produirait des
        // joueurs plats que le developpement mettrait dix ans a profiler,
        // et les vingt premieres saisons du monde ne vaudraient rien.
        $ceilings = $potentials->ceilings;
        $maturity = $this->levelAtAge($startAge, $potentials->ceiling, $potentials->physicalPeakAge, $talent)
            / max(1, $potentials->ceiling);

        $world->components(PlayerPhysicalSkills::class)->set($entity, new PlayerPhysicalSkills(
            pace: $this->matured($rng, $ceilings->pace, $maturity, $talent),
            stamina: $this->matured($rng, $ceilings->stamina, $maturity, $talent),
            strength: $this->matured($rng, $ceilings->strength, $maturity, $talent),
            reflexes: $this->matured($rng, $ceilings->reflexes, $maturity, $talent),
        ));

        $world->components(PlayerTechnicalSkills::class)->set($entity, new PlayerTechnicalSkills(
            technique: $this->matured($rng, $ceilings->technique, $maturity, $talent),
            passing: $this->matured($rng, $ceilings->passing, $maturity, $talent),
            finishing: $this->matured($rng, $ceilings->finishing, $maturity, $talent),
            defending: $this->matured($rng, $ceilings->defending, $maturity, $talent),
            positioning: $this->matured($rng, $ceilings->positioning, $maturity, $talent),
            handling: $this->matured($rng, $ceilings->handling, $maturity, $talent),
            distribution: $this->matured($rng, $ceilings->distribution, $maturity, $talent),
        ));

        $world->components(PlayerMentalSkills::class)->set($entity, new PlayerMentalSkills(
            vision: $this->matured($rng, $ceilings->vision, $maturity, $talent),
            composure: $this->matured($rng, $ceilings->composure, $maturity, $talent),
            leadership: $this->matured($rng, $ceilings->leadership, $maturity, $talent),
            discipline: $this->matured($rng, $ceilings->discipline, $maturity, $talent),
            command: $this->matured($rng, $ceilings->command, $maturity, $talent),
        ));

        if ($clubId !== null) {
            $this->employ($world, $rng, $entity, $atTick, $contracts, $clubId);
        }

        return $entity;
    }

    /**
     * L'embauche au genesis, posee **apres** les competences parce qu'elle en
     * depend : le salaire passe par `Football\Support\WageModel`, comme tout
     * renouvellement de `Football\ContractSystem`. Un monde qui demarrerait au
     * salaire forfaitaire verrait sa masse salariale glisser pendant les
     * quatre premieres annees, a mesure que les contrats initiaux seraient
     * renegocies au prix du marche - la ligne de base du grand livre ne serait
     * comparable a rien.
     *
     * Le salaire du genesis est calcule sur la qualite **vraie**, alors que tout
     * renouvellement passe par la qualite percue (docs/12- §4). Ce n'est pas une
     * incoherence : au genesis aucun observateur n'existe encore (le staff est
     * seme apres les joueurs), et cette valeur n'est pas une decision de club
     * mais un point de depart d'echelle salariale - le monde doit demarrer la ou
     * il convergera. Les erreurs d'evaluation apparaissent au premier mercato.
     *
     * L'echeance est **etalee** sur toute la duree maximale d'un contrat, sans
     * quoi tout le monde arriverait a terme la meme annee : le monde entier
     * changerait de club en bloc tous les quatre ans au lieu de tourner
     * continument. Elle peut tomber dans le passe proche (`atTick` inclus),
     * ce qui met simplement le joueur sur le marche au premier mercato.
     */
    private function employ(WorldState $world, Rng $rng, int $entity, int $atTick, ContractBalance $contracts, int $clubId): void
    {
        $quality = WageModel::quality(
            $world->components(PlayerPhysicalSkills::class)->get($entity),
            $world->components(PlayerTechnicalSkills::class)->get($entity),
            $world->components(PlayerMentalSkills::class)->get($entity),
        );

        $span = max(1, $contracts->maxDurationYears) * 365;
        $expiresOn = $atTick + (int) ($rng->nextUint32() % $span);

        $world->components(SquadMembership::class)->set($entity, new SquadMembership($clubId));
        $world->components(Contract::class)->set($entity, new Contract(
            $clubId,
            WageModel::perWeekCents($quality, $contracts),
            new SimDate($expiresOn),
            // Anciennete **derivee** de l'echeance deja tiree, jamais tiree a
            // part : un tirage de plus decalerait tout le flux RNG du genesis et
            // changerait la population entiere, ce qui rendrait incomparables
            // toutes les mesures deja enregistrees. L'etalement de l'echeance
            // suffit d'ailleurs a etaler l'anciennete - un joueur proche du
            // terme est un joueur arrive depuis longtemps. `epochDay` peut etre
            // negatif dans un monde qui demarre au tick 1 : « signe avant le
            // debut du monde » est la lecture honnete d'une population de
            // genesis, et seule la difference de deux dates est jamais lue.
            new SimDate($expiresOn - $span),
        ));
    }

    /**
     * Interpolation triangulaire : montee lineaire du niveau de recrue vers
     * le `ceiling` jusqu'au pic, puis descente symetrique vers le niveau de
     * recrue au-dela - sur le meme empan que la montee, jamais plus bas (un
     * veteran en fin de carriere reste au-dessus du niveau d'un debutant,
     * contrairement au declin reel de `PlayerDevelopmentSystem` qui peut
     * aller jusqu'a `MIN_SKILL`). Approximation volontairement ignorante de
     * `PlayerDevelopmentBalance` - la reprendre coupterait cette classe a des
     * parametres non calibres (Phase 1) pour un gain de precision qui n'a
     * pas lieu d'etre a la generation initiale (cf. docblock de classe).
     */
    private function levelAtAge(float $age, int $ceiling, int $peakAge, YouthIntakeBalance $talent): int
    {
        $rookieLevel = $ceiling * $talent->startingSkillRatio;
        $span = max(1.0, $peakAge - $talent->intakeAgeYears);

        if ($age <= $peakAge) {
            $progress = min(1.0, max(0.0, ($age - $talent->intakeAgeYears) / $span));

            return (int) round($rookieLevel + $progress * ($ceiling - $rookieLevel));
        }

        $declineProgress = min(1.0, ($age - $peakAge) / $span);

        return (int) round($ceiling - $declineProgress * ($ceiling - $rookieLevel));
    }

    /**
     * Le niveau d'**un** attribut au genesis : son propre plafond, ramene a la
     * maturite de l'age du joueur, puis ecarte par un bruit borne.
     *
     * Un tirage par attribut, et non plus un par categorie partage entre tous
     * ses attributs : c'est le plafond par attribut qui porte le profil de
     * poste (`Football\Support\PositionModel`), le bruit ne fait que casser
     * l'uniformite residuelle.
     */
    private function matured(Rng $rng, int $ceiling, float $maturity, YouthIntakeBalance $talent): int
    {
        $level = (int) round($ceiling * $maturity);
        $offset = (int) round($this->uniform($rng, (float) -$talent->startingSkillJitter, (float) $talent->startingSkillJitter));

        return max(1, min(100, $level + $offset));
    }

    private function uniform(Rng $rng, float $min, float $max): float
    {
        $fraction = $rng->nextUint32() / 0xFFFFFFFF;

        return $min + $fraction * ($max - $min);
    }
}

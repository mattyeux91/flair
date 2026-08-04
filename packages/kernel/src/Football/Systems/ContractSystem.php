<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Support\WageModel;

/**
 * Le mercato annuel (docs/14-algorithmes.md §5, docs/15-roadmap.md §4
 * Phase 2) : un jour par an, tous les contrats arrives a terme sont
 * renouveles, ou pas, et les joueurs liberes changent de club. C'est le
 * premier systeme qui fait **bouger un joueur d'un club a un autre**, et le
 * premier qui met un **prix** sur un joueur.
 *
 * N'ecrit aucun composant : il decide et emet des Faits, `Football\SquadSystem`
 * applique au tick suivant. Le decoupage est structurellement force - lire les
 * competences et `Finances` impose de venir apres leurs writers, ecrire
 * `SquadMembership` impose de venir avant `TrainingSystem`/`MatchSystem` qui
 * le lisent. Le raisonnement complet est dans le docblock de
 * `Football\Events\ContractSigned` ; ce systeme occupe donc la **derniere**
 * place du pipeline, celle ou tout est lisible.
 *
 * ## Ce qu'il ne fait pas
 *
 * Ni indemnite de transfert, ni negociation en plusieurs tours, ni agent
 * intermediaire, ni rupture de contrat en cours (docs/14- §5 les prevoit
 * tous). Tous les mouvements sont des **transferts libres** : un joueur ne
 * quitte son club qu'au terme de son contrat, et aucun argent ne change de
 * mains entre clubs. Consequence directe et voulue : ce lot ne cree ni ne
 * detruit un centime, l'invariant de conservation monetaire
 * (`Harness\Tests\Regression\MonetaryConservationTest`) reste vrai sans
 * qu'aucune ligne de comptabilite ait besoin d'etre ecrite ici. Un club
 * au-dessus de sa cible d'effectif se degonfle donc lentement, par
 * non-renouvellement, sur deux a quatre ans.
 *
 * ## La simplification a ne pas prendre pour une decision de conception
 *
 * La decision de renouvellement lit les competences **vraies** du joueur
 * (`quality()`). Ce n'est pas une affirmation sur le monde - ce n'est **pas**
 * "un club connait forcement bien ses propres joueurs". C'est une
 * simplification de perimetre : aucun role non-joueur n'existe encore dans le
 * monde, et dans le modele vise ce n'est jamais "le club" qui evalue mais une
 * **personne** - scout, entraineur, directeur sportif, president - dont la
 * competence d'observation determine l'erreur d'estimation (docs/12- §1 et
 * §4, note du 2026-08-02). Un club au staff mediocre doit pouvoir se tromper
 * sur son propre joueur, le prolonger trop cher ou laisser filer le bon.
 *
 * Ce site d'appel est donc le **premier consommateur prevu de la perception**
 * : quand `Person` + role existeront, c'est `quality()` qui passera de la
 * verite cachee a une estimation bruitee par `observerId`, et rien d'autre
 * dans ce systeme n'aura a changer.
 *
 * ## Le budget
 *
 * Un club engage au plus `wageBudgetShare` de son `SeasonIncome` en masse
 * salariale annuelle, contrats deja en cours compris - voir le docblock du
 * champ pour pourquoi une part de revenu et non un plancher de tresorerie. Le
 * solde (`Finances`) ne sert qu'a une seconde garde, plus grossiere : un club
 * dans le rouge ne recrute pas de bouche supplementaire, meme s'il continue de
 * prolonger les siennes. Prolonger un joueur qu'on a deja coute moins cher que
 * de devoir le remplacer, et un club endette qui liquiderait son effectif
 * n'aurait aucun moyen de revenir.
 *
 * ## L'ordre de service, et le piege qu'il evite
 *
 * Les clubs se servent chez les joueurs sans club par tours, tries par deficit
 * d'effectif decroissant, egalites departagees par une **cle de loterie**
 * tiree sur `rng(clubId)` - donc rejouee chaque annee. Trier par `clubId`
 * aurait ete plus simple et aurait grave une hierarchie arbitraire a la
 * creation du monde, que le harness aurait ensuite mesuree comme une vraie
 * inegalite : exactement le piege que `Football\FinanceSystem` documente deja
 * pour la repartition des revenus.
 *
 * Le deficit d'abord, plutot que la richesse d'abord, est un choix
 * d'equilibre assume : le salaire indexe sur la qualite rouvre deja un canal
 * "riche -> meilleurs joueurs", il n'y avait pas de raison d'en empiler un
 * second dans le meme lot. Un seul levier a la fois, comme pour l'entretien
 * convexe.
 */
final class ContractSystem implements System
{
    /**
     * Un contrat est signe a la semaine (`Contract::$wagePerWeekCents`) mais
     * arbitre a l'annee : c'est l'horizon sur lequel un club raisonne, et le
     * seul qui se compare a `SeasonIncome`.
     */
    private const WEEKS_PER_YEAR = 52;

    public function id(): string
    {
        return 'contract';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Club::class,
            Contract::class,
            Finances::class,
            SeasonIncome::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
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
        $balance = $ctx->ruleset()->balance->contract;

        if ($ctx->tick % 365 !== $balance->renewalDayOfYear) {
            return;
        }

        $clubIds = $ctx->read(Club::class)->entities();

        if ($clubIds === []) {
            return;
        }

        /** @var array<int, int> $qualities */
        $qualities = [];
        /** @var array<int, int> $squadSize */
        $squadSize = array_fill_keys($clubIds, 0);
        /** @var array<int, int> $committedWage */
        $committedWage = array_fill_keys($clubIds, 0);
        /** @var array<int, list<int>> $expiring */
        $expiring = array_fill_keys($clubIds, []);

        $this->census($ctx, $qualities, $squadSize, $committedWage, $expiring);

        /** @var array<int, array{clubId: int, previousClubId: int|null, wagePerWeekCents: int, expiresOnEpochDay: int}> $signed */
        $signed = [];
        /** @var array<int, int> $released */
        $released = [];

        $this->renew($ctx, $balance, $qualities, $squadSize, $committedWage, $expiring, $signed, $released);
        $this->allocateUnattached($ctx, $balance, $qualities, $squadSize, $committedWage, $signed, $released);

        ksort($signed);
        ksort($released);

        foreach ($signed as $playerId => $terms) {
            $ctx->emit(new ContractSigned(
                $playerId,
                $terms['clubId'],
                $terms['previousClubId'],
                $terms['wagePerWeekCents'],
                $terms['expiresOnEpochDay'],
            ), entityId: $playerId);
        }

        foreach ($released as $playerId => $clubId) {
            $ctx->emit(new ContractExpired($playerId, $clubId), entityId: $playerId);
        }
    }

    /**
     * Recense l'existant : qui est sous contrat, pour combien, et qui arrive a
     * terme aujourd'hui.
     *
     * Un joueur sans competences est ignore de bout en bout. C'est le cas d'un
     * retraite du jour : `Football\RetirementSystem` lui a retire ses
     * competences plus tot dans le meme tick mais son `Contract` ne
     * disparaitra qu'au tick suivant, quand `Football\SquadSystem` traitera
     * `PlayerRetired`. Le compter dans l'effectif ou le remettre sur le marche
     * ferait signer un retraite.
     *
     * Un contrat qui pointe vers un club inexistant est ignore de meme : rien
     * ne produit ce cas aujourd'hui (aucun systeme ne detruit de club), mais
     * `array_fill_keys` ne pardonnerait pas la cle manquante.
     *
     * @param array<int, int> $qualities
     * @param array<int, int> $squadSize
     * @param array<int, int> $committedWage cents par an, contrats non echus
     * @param array<int, list<int>> $expiring
     */
    private function census(
        SystemContext $ctx,
        array &$qualities,
        array &$squadSize,
        array &$committedWage,
        array &$expiring,
    ): void {
        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);

            if ($contract === null || !isset($squadSize[$contract->clubId])) {
                continue;
            }

            $quality = $this->quality($ctx, $playerId);

            if ($quality === null) {
                continue;
            }

            $qualities[$playerId] = $quality;

            if ($contract->expiresOn->epochDay > $ctx->tick) {
                $squadSize[$contract->clubId]++;
                $committedWage[$contract->clubId] += $contract->wagePerWeekCents * self::WEEKS_PER_YEAR;

                continue;
            }

            $expiring[$contract->clubId][] = $playerId;
        }
    }

    /**
     * Chaque club prolonge ses joueurs arrivant a terme, du meilleur au moins
     * bon, tant que l'effectif cible et le budget le permettent. Les autres
     * sont liberes.
     *
     * Le tri par qualite decroissante est ce qui donne un sens a la
     * contrainte : un club qui doit couper coupe par le bas, pas au hasard.
     * Les egalites sont departagees par `EntityId` croissant - un ordre total
     * est necessaire, et c'est le seul disponible ici.
     *
     * @param array<int, int> $qualities
     * @param array<int, int> $squadSize
     * @param array<int, int> $committedWage
     * @param array<int, list<int>> $expiring
     * @param array<int, array{clubId: int, previousClubId: int|null, wagePerWeekCents: int, expiresOnEpochDay: int}> $signed
     * @param array<int, int> $released
     */
    private function renew(
        SystemContext $ctx,
        ContractBalance $balance,
        array $qualities,
        array &$squadSize,
        array &$committedWage,
        array $expiring,
        array &$signed,
        array &$released,
    ): void {
        $budgets = $this->budgets($ctx, $balance, array_keys($expiring));

        foreach ($expiring as $clubId => $candidates) {
            usort($candidates, static fn (int $left, int $right): int
                => [$qualities[$right] ?? 0, $left] <=> [$qualities[$left] ?? 0, $right]);

            foreach ($candidates as $playerId) {
                $wage = WageModel::perWeekCents($qualities[$playerId] ?? 0, $balance);
                $annual = $wage * self::WEEKS_PER_YEAR;

                if ($squadSize[$clubId] >= $balance->targetSquadSize
                    || !$this->fitsBudget($budgets[$clubId], $committedWage[$clubId], $annual)) {
                    $released[$playerId] = $clubId;

                    continue;
                }

                $signed[$playerId] = [
                    'clubId' => $clubId,
                    'previousClubId' => $clubId,
                    'wagePerWeekCents' => $wage,
                    'expiresOnEpochDay' => $this->expiresOn($ctx, $playerId, $balance),
                ];
                $squadSize[$clubId]++;
                $committedWage[$clubId] += $annual;
            }
        }
    }

    /**
     * Le marche : les clubs en deficit d'effectif se servent a tour de role
     * dans le vivier d'joueurs sans club, chacun prenant le meilleur joueur qu'il
     * peut encore payer.
     *
     * Le vivier reunit les liberes du jour et les chomeurs des annees
     * precedentes (une entite qui porte des competences sans `Contract`). Sans
     * ce second groupe, un joueur que personne n'a repris une annee ne
     * pourrait plus jamais revenir : il faudrait qu'il reste sur le marche
     * exactement le jour ou il en sort, ce qui n'arrive jamais.
     *
     * Le tour de table s'arrete des qu'un tour complet n'a produit aucune
     * signature : soit le vivier est vide, soit plus personne n'a la place ou
     * l'argent. Aucune borne artificielle sur le nombre de tours n'est
     * necessaire, chaque signature retirant un joueur du vivier.
     *
     * @param array<int, int> $qualities
     * @param array<int, int> $squadSize
     * @param array<int, int> $committedWage
     * @param array<int, array{clubId: int, previousClubId: int|null, wagePerWeekCents: int, expiresOnEpochDay: int}> $signed
     * @param array<int, int> $released
     */
    private function allocateUnattached(
        SystemContext $ctx,
        ContractBalance $balance,
        array &$qualities,
        array &$squadSize,
        array &$committedWage,
        array &$signed,
        array &$released,
    ): void {
        $clubIds = array_keys($squadSize);
        $budgets = $this->budgets($ctx, $balance, $clubIds);
        $lottery = [];
        $solvent = [];

        foreach ($clubIds as $clubId) {
            $lottery[$clubId] = $ctx->rng($clubId)->nextUint32();
            $solvent[$clubId] = ($ctx->read(Finances::class)->get($clubId)->balanceCents ?? 0) >= 0;
        }

        $pool = $this->unattached($ctx, $qualities, $released);

        while ($pool !== []) {
            $needy = [];

            foreach ($clubIds as $clubId) {
                if ($solvent[$clubId] && $squadSize[$clubId] < $balance->targetSquadSize) {
                    $needy[] = $clubId;
                }
            }

            usort($needy, static fn (int $left, int $right): int
                => [$balance->targetSquadSize - $squadSize[$right], $lottery[$left]]
                <=> [$balance->targetSquadSize - $squadSize[$left], $lottery[$right]]);

            $anySigned = false;

            foreach ($needy as $clubId) {
                foreach ($pool as $index => $playerId) {
                    $wage = WageModel::perWeekCents($qualities[$playerId] ?? 0, $balance);
                    $annual = $wage * self::WEEKS_PER_YEAR;

                    if (!$this->fitsBudget($budgets[$clubId], $committedWage[$clubId], $annual)) {
                        continue;
                    }

                    $signed[$playerId] = [
                        'clubId' => $clubId,
                        'previousClubId' => $released[$playerId] ?? null,
                        'wagePerWeekCents' => $wage,
                        'expiresOnEpochDay' => $this->expiresOn($ctx, $playerId, $balance),
                    ];
                    unset($released[$playerId], $pool[$index]);
                    $squadSize[$clubId]++;
                    $committedWage[$clubId] += $annual;
                    $anySigned = true;

                    break;
                }
            }

            if (!$anySigned) {
                return;
            }
        }
    }

    /**
     * Le vivier, du meilleur au moins bon : les liberes du jour, puis les
     * joueurs deja sans contrat depuis une annee ou plus.
     *
     * Le tri decroissant fait que le premier joueur finançable trouve par un
     * club est aussi le meilleur qu'il puisse s'offrir - le salaire etant
     * monotone en qualite, aucun parcours complet du vivier n'est necessaire.
     *
     * @param array<int, int> $qualities enrichi des chomeurs rencontres ici
     * @param array<int, int> $released
     * @return list<int>
     */
    private function unattached(SystemContext $ctx, array &$qualities, array $released): array
    {
        $pool = array_keys($released);

        foreach ($ctx->read(PlayerPhysicalSkills::class)->entities() as $playerId) {
            if ($ctx->read(Contract::class)->get($playerId) !== null) {
                continue;
            }

            $quality = $this->quality($ctx, $playerId);

            if ($quality === null) {
                continue;
            }

            $qualities[$playerId] = $quality;
            $pool[] = $playerId;
        }

        usort($pool, static fn (int $left, int $right): int
            => [$qualities[$right] ?? 0, $left] <=> [$qualities[$left] ?? 0, $right]);

        return $pool;
    }

    /**
     * Le budget salarial annuel de chaque club, ou `null` quand aucune saison
     * ne s'est encore achevee - un monde qui demarre n'a pas de revenu a
     * partager, et refuser tout contrat cette annee-la viderait les effectifs
     * avant le premier match.
     *
     * @param list<int> $clubIds
     * @return array<int, int|null>
     */
    private function budgets(SystemContext $ctx, ContractBalance $balance, array $clubIds): array
    {
        $budgets = [];

        foreach ($clubIds as $clubId) {
            $income = $ctx->read(SeasonIncome::class)->get($clubId);
            $budgets[$clubId] = $income === null
                ? null
                : max(0, (int) round($income->cents * $balance->wageBudgetShare));
        }

        return $budgets;
    }

    /**
     * Un budget `null` - aucune saison achevee, donc aucun revenu connu - ne
     * contraint pas : refuser tout contrat la premiere annee d'un monde
     * viderait les effectifs avant le premier match.
     */
    private function fitsBudget(?int $budget, int $committed, int $annual): bool
    {
        return $budget === null || $committed + $annual <= $budget;
    }

    /**
     * La date de fin du contrat signe aujourd'hui. La duree est tiree par
     * joueur pour etaler les echeances (cf. `ContractBalance::$minDurationYears`)
     * sur le flux `rng(playerId)`, donc reproductible a graine egale et
     * independante de l'ordre dans lequel les clubs ont ete servis.
     */
    private function expiresOn(SystemContext $ctx, int $playerId, ContractBalance $balance): int
    {
        $shortest = max(1, $balance->minDurationYears);
        $longest = max($shortest, $balance->maxDurationYears);
        $years = $shortest + (int) ($ctx->rng($playerId)->nextUint32() % ($longest - $shortest + 1));

        return $ctx->tick + $years * 365;
    }

    /**
     * La qualite du joueur, ou `null` s'il n'en est plus un - un bloc de
     * competences manquant signale un retraite du jour (cf. `census()`), pas
     * un joueur mediocre.
     */
    private function quality(SystemContext $ctx, int $playerId): ?int
    {
        $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        return WageModel::quality($physical, $technical, $mental);
    }
}

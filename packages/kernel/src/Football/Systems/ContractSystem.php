<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\PerceptionBalance;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Events\ContractExpired;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Support\PerceptionModel;
use Flair\Kernel\Football\Support\PositionModel;
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
 * ## Ce n'est jamais "le club" qui evalue
 *
 * Toutes les decisions de ce systeme portent sur une qualite **percue**
 * (`perceived()`), jamais sur les competences vraies : c'est le scout employe
 * par le club qui observe, et sa competence determine l'erreur (docs/12- §4).
 * Un club au staff mediocre prolonge trop cher, laisse filer le bon joueur, et
 * se trompe davantage encore sur un joueur qu'il n'a jamais eu sous les yeux -
 * un club sans scout du tout est le pire observateur du monde, pas un
 * omniscient.
 *
 * Deux consequences a garder en tete en lisant le code :
 *
 * - **Le salaire propose suit la perception**, pas la verite : un club qui
 *   surestime surpaie, et c'est voulu (`WageModel` est monotone en qualite,
 *   donc l'erreur d'evaluation se paie comptant).
 * - **Le vivier des joueurs sans club n'a plus d'ordre unique** : une qualite
 *   percue est par nature par observateur, donc chaque club trie le vivier
 *   selon ce que *lui* croit voir. C'est la seule vraie complication
 *   structurelle du passage a la perception.
 *
 * Restent lues sur la verite, deliberement : le **poste** d'un joueur
 * (`positionOf()`, donc la composition d'effectif par poste) et tout ce que
 * `Football\MatchSystem` lit - un match n'est pas une opinion. Se tromper sur le
 * poste d'un joueur est une extension possible, pas une omission.
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
            Employment::class,
            Finances::class,
            Scout::class,
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

        // Un observateur par club, releve une fois pour le mercato entier : la
        // meme personne doit juger tous les dossiers du jour, sinon deux
        // decisions du meme club pourraient reposer sur deux perceptions
        // differentes du meme joueur.
        $observers = $this->observersByClub($ctx, $clubIds);

        /** @var array<int, int> $qualities */
        $qualities = [];
        /** @var array<int, int> $squadSize */
        $squadSize = array_fill_keys($clubIds, 0);
        /** @var array<int, int> $committedWage */
        $committedWage = array_fill_keys($clubIds, 0);
        /** @var array<int, list<int>> $expiring */
        $expiring = array_fill_keys($clubIds, []);

        $this->census($ctx, $observers, $qualities, $squadSize, $committedWage, $expiring);

        /** @var array<int, array{clubId: int, previousClubId: int|null, wagePerWeekCents: int, expiresOnEpochDay: int}> $signed */
        $signed = [];
        /** @var array<int, int> $released */
        $released = [];

        $this->renew($ctx, $balance, $qualities, $squadSize, $committedWage, $expiring, $signed, $released);
        $this->allocateUnattached($ctx, $balance, $observers, $squadSize, $committedWage, $signed, $released);

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
     * Chaque joueur sous contrat est evalue par l'observateur de **son** club :
     * une carte plate `joueur -> qualite percue` suffit donc ici, sans
     * ambiguite. Seul le vivier des joueurs sans club, que plusieurs clubs
     * regardent, a besoin d'une carte par observateur.
     *
     * @param array<int, array{id: int, judgement: int}> $observers
     * @param array<int, int> $qualities
     * @param array<int, int> $squadSize
     * @param array<int, int> $committedWage cents par an, contrats non echus
     * @param array<int, list<int>> $expiring
     */
    private function census(
        SystemContext $ctx,
        array $observers,
        array &$qualities,
        array &$squadSize,
        array &$committedWage,
        array &$expiring,
    ): void {
        $perception = $ctx->ruleset()->balance->perception;

        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);

            if ($contract === null || !isset($squadSize[$contract->clubId])) {
                continue;
            }

            $quality = $this->perceived($ctx, $observers[$contract->clubId], $playerId, $perception);

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
        $wanted = $this->positionTargets($ctx, $balance);
        $held = $this->retainedByPosition($ctx, $expiring);

        foreach ($expiring as $clubId => $candidates) {
            usort($candidates, static fn (int $left, int $right): int
                => [$qualities[$right] ?? 0, $left] <=> [$qualities[$left] ?? 0, $right]);

            // Deux passes sur les memes candidats, deja tries par qualite
            // decroissante : d'abord ceux dont le poste manquerait a l'effectif
            // retenu, ensuite les autres. Sans cette premiere passe un club
            // coupe strictement par le bas, et son gardien - souvent son joueur
            // le moins bien note sur l'echelle marchande - part le premier. Le
            // club se retrouve alors sans gardien, et rien ne l'y ramene
            // puisqu'il n'est pas en deficit d'effectif.
            $keep = [];

            foreach ([true, false] as $fillingAGap) {
                foreach ($candidates as $playerId) {
                    if (isset($keep[$playerId])) {
                        continue;
                    }

                    $position = $this->positionOf($ctx, $playerId);

                    if ($fillingAGap && ($position === null
                        || ($held[$clubId][$position->value] ?? 0) >= ($wanted[$position->value] ?? 0))) {
                        continue;
                    }

                    $wage = WageModel::perWeekCents($qualities[$playerId] ?? 0, $balance);
                    $annual = $wage * self::WEEKS_PER_YEAR;

                    if (!$this->fitsBudget($budgets[$clubId], $committedWage[$clubId], $annual)) {
                        continue;
                    }

                    // Le plafond d'effectif ne s'applique **pas** a la premiere
                    // passe : un club au-dessus de sa cible doit couper un
                    // milieu excedentaire, jamais son dernier gardien. C'est le
                    // cas qui cassait reellement - tout club nait au-dessus de
                    // sa cible (une trentaine de joueurs pour une cible de
                    // vingt), donc sans cette exception le plafond libere en
                    // bloc tous les contrats arrivant a terme, gardiens
                    // compris, avant meme que la priorite par poste ne
                    // s'applique. Le club depasse sa cible d'une saison, puis
                    // se degonfle par le bas comme prevu.
                    if (!$fillingAGap && $squadSize[$clubId] >= $balance->targetSquadSize) {
                        continue;
                    }

                    $keep[$playerId] = true;
                    $signed[$playerId] = [
                        'clubId' => $clubId,
                        'previousClubId' => $clubId,
                        'wagePerWeekCents' => $wage,
                        'expiresOnEpochDay' => $this->expiresOn($ctx, $playerId, $balance),
                    ];
                    $squadSize[$clubId]++;
                    $committedWage[$clubId] += $annual;

                    if ($position !== null) {
                        $held[$clubId][$position->value] = ($held[$clubId][$position->value] ?? 0) + 1;
                    }
                }
            }

            foreach ($candidates as $playerId) {
                if (!isset($keep[$playerId])) {
                    $released[$playerId] = $clubId;
                }
            }
        }
    }

    /**
     * L'effectif par poste que chaque club **garde de toute facon** : tous ses
     * joueurs sous contrat, moins ceux dont le contrat expire aujourd'hui.
     *
     * C'est la reference contre laquelle un renouvellement comble un trou ou
     * non - compter l'effectif complet ferait croire au club qu'il a deja un
     * gardien alors que c'est precisement celui dont il doit decider.
     *
     * @param array<int, list<int>> $expiring
     * @return array<int, array<string, int>>
     */
    private function retainedByPosition(SystemContext $ctx, array $expiring): array
    {
        $held = $this->squadByPosition($ctx);

        foreach ($expiring as $clubId => $candidates) {
            foreach ($candidates as $playerId) {
                $position = $this->positionOf($ctx, $playerId);

                if ($position !== null && isset($held[$clubId][$position->value])) {
                    $held[$clubId][$position->value]--;
                }
            }
        }

        return $held;
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
     * @param array<int, array{id: int, judgement: int}> $observers
     * @param array<int, int> $squadSize
     * @param array<int, int> $committedWage
     * @param array<int, array{clubId: int, previousClubId: int|null, wagePerWeekCents: int, expiresOnEpochDay: int}> $signed
     * @param array<int, int> $released
     */
    private function allocateUnattached(
        SystemContext $ctx,
        ContractBalance $balance,
        array $observers,
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

        $pool = $this->unattached($ctx, $released);
        $scouted = $this->scout($ctx, $observers, $pool);
        $squadByPosition = $this->squadByPosition($ctx);
        $wanted = $this->positionTargets($ctx, $balance);

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
                // Deux passes : d'abord un joueur dont le poste manque a ce
                // club, ensuite n'importe qui. Sans ca les effectifs derivent
                // vers des compositions aleatoires - un club a trois gardiens
                // et zero attaquant - puisque le vivier est trie par qualite
                // seule. C'est la version minimale de la "gap analysis" de
                // docs/14- §5 : combler un trou, pas evaluer un marche.
                $index = $this->pick($ctx, $pool, $clubId, $squadByPosition, $wanted, $scouted[$clubId], $balance, $budgets, $committedWage, onlyDeficit: true)
                    ?? $this->pick($ctx, $pool, $clubId, $squadByPosition, $wanted, $scouted[$clubId], $balance, $budgets, $committedWage, onlyDeficit: false);

                if ($index !== null) {
                    $playerId = $pool[$index];
                    $wage = WageModel::perWeekCents($scouted[$clubId]['qualities'][$playerId] ?? 0, $balance);
                    $annual = $wage * self::WEEKS_PER_YEAR;
                    $position = $this->positionOf($ctx, $playerId);

                    if ($position !== null) {
                        $squadByPosition[$clubId][$position->value] = ($squadByPosition[$clubId][$position->value] ?? 0) + 1;
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
                }
            }

            if (!$anySigned) {
                return;
            }
        }
    }

    /**
     * L'indice, dans le vivier, du meilleur joueur que ce club puisse signer -
     * ou `null` s'il n'y en a aucun. « Meilleur » au sens de **ce que ce club
     * croit voir**, jamais au sens de la verite.
     *
     * Le parcours suit l'ordre de ce club (`$scouted['order']`), et non l'ordre
     * du vivier : la qualite percue etant propre a l'observateur, il n'existe
     * plus d'ordre unique du vivier sur lequel tout le monde s'accorderait.
     * `$pool` reste l'autorite de **disponibilite** - les indices qu'un club
     * precedent a consommes dans ce tour y ont ete `unset()`, il faut donc les
     * sauter. Le salaire restant monotone en qualite percue, le premier joueur
     * finançable rencontre dans cet ordre est bien le meilleur que le club
     * puisse s'offrir.
     *
     * `$onlyDeficit` restreint aux joueurs dont le poste manque au club. Un
     * joueur sans competences (donc sans poste derivable) n'est jamais un
     * comble-trou, mais reste signable a la seconde passe.
     *
     * @param array<int, int> $pool cles creusees par les `unset()` des tours precedents, donc jamais une `list`
     * @param array<int, array<string, int>> $squadByPosition
     * @param array<string, int> $wanted
     * @param array{qualities: array<int, int>, order: list<int>} $scouted ce que cet observateur croit du vivier
     * @param array<int, int|null> $budgets
     * @param array<int, int> $committedWage
     */
    private function pick(
        SystemContext $ctx,
        array $pool,
        int $clubId,
        array $squadByPosition,
        array $wanted,
        array $scouted,
        ContractBalance $balance,
        array $budgets,
        array $committedWage,
        bool $onlyDeficit,
    ): ?int {
        foreach ($scouted['order'] as $index) {
            if (!isset($pool[$index])) {
                continue;
            }

            $playerId = $pool[$index];
            $annual = WageModel::perWeekCents($scouted['qualities'][$playerId] ?? 0, $balance) * self::WEEKS_PER_YEAR;

            if (!$this->fitsBudget($budgets[$clubId], $committedWage[$clubId], $annual)) {
                continue;
            }

            if ($onlyDeficit) {
                $position = $this->positionOf($ctx, $playerId);

                if ($position === null) {
                    continue;
                }

                $held = $squadByPosition[$clubId][$position->value] ?? 0;

                if ($held >= ($wanted[$position->value] ?? 0)) {
                    continue;
                }
            }

            return $index;
        }

        return null;
    }

    /**
     * L'effectif de chaque club ventile par poste, le poste d'un joueur etant
     * **derive** de ses competences (`PositionModel::bestPosition()`) et jamais
     * stocke - cf. docs/12- §4.
     *
     * @return array<int, array<string, int>> clubId -> [valeur du poste -> effectif]
     */
    private function squadByPosition(SystemContext $ctx): array
    {
        $byClub = [];

        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);
            $position = $this->positionOf($ctx, $playerId);

            if ($contract === null || $position === null) {
                continue;
            }

            $byClub[$contract->clubId][$position->value] = ($byClub[$contract->clubId][$position->value] ?? 0) + 1;
        }

        return $byClub;
    }

    /**
     * Combien de joueurs par poste un club cherche a tenir : les places de la
     * formation, mises a l'echelle de `targetSquadSize`. Un 4-4-2 pour vingt
     * joueurs donne deux gardiens, huit defenseurs, huit milieux, quatre
     * attaquants - un remplacant a chaque poste, ce qui est precisement ce qui
     * evite qu'un club se retrouve sans gardien.
     *
     * L'arrondi vers le haut fait que la somme depasse legerement l'effectif
     * cible : c'est une cible **par poste**, pas une repartition d'un total,
     * et `targetSquadSize` reste le seul plafond dur.
     *
     * @return array<string, int>
     */
    private function positionTargets(SystemContext $ctx, ContractBalance $balance): array
    {
        $positions = $ctx->ruleset()->balance->position;
        $onPitch = 0;
        $targets = [];

        foreach (Position::cases() as $position) {
            $onPitch += PositionModel::slots($position, $positions);
        }

        foreach (Position::cases() as $position) {
            $slots = PositionModel::slots($position, $positions);
            $targets[$position->value] = $onPitch > 0
                ? (int) ceil($slots * $balance->targetSquadSize / $onPitch)
                : 0;
        }

        return $targets;
    }

    /** Le poste ou ce joueur note le mieux, ou `null` s'il n'a pas de competences. */
    private function positionOf(SystemContext $ctx, int $playerId): ?Position
    {
        $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        return PositionModel::bestPosition($physical, $technical, $mental);
    }

    /**
     * Le vivier : les liberes du jour, puis les joueurs deja sans contrat depuis
     * une annee ou plus. **Sans ordre**, et c'est le point - le vivier n'a plus
     * d'ordre unique depuis que les clubs ne voient plus la meme chose. C'est
     * `scout()` qui donne a chacun son classement.
     *
     * @param array<int, int> $released
     * @return list<int>
     */
    private function unattached(SystemContext $ctx, array $released): array
    {
        $pool = array_keys($released);

        foreach ($ctx->read(PlayerPhysicalSkills::class)->entities() as $playerId) {
            if ($ctx->read(Contract::class)->get($playerId) !== null) {
                continue;
            }

            $pool[] = $playerId;
        }

        return $pool;
    }

    /**
     * Ce que chaque club croit du vivier : la qualite percue de chaque joueur
     * disponible, et l'ordre de preference qui en decoule.
     *
     * `order` contient des **cles du vivier**, pas des identifiants de joueur,
     * pour que `pick()` puisse tester la disponibilite d'un candidat en une
     * lecture (`isset($pool[$index])`) plutot qu'en cherchant dans une liste.
     *
     * Le comparateur - qualite decroissante, `EntityId` croissant a egalite -
     * est **exactement** celui que le tri global unique appliquait avant ce lot.
     * C'est ce qui garantit qu'a perception desactivee (`baseErrorPoints = 0`,
     * donc tous les clubs percevant la verite) le mercato reprend l'ordre
     * d'avant, joueur pour joueur, et pas seulement en moyenne.
     *
     * Cout : autant d'evaluations que clubs x vivier, une fois l'an. Aucune
     * raison de l'optimiser (18 clubs, quelques dizaines de joueurs), et surtout
     * aucune raison de le mettre en cache - deux clubs n'ont pas le droit de
     * partager une perception.
     *
     * @param array<int, array{id: int, judgement: int}> $observers
     * @param list<int> $pool
     * @return array<int, array{qualities: array<int, int>, order: list<int>}>
     */
    private function scout(SystemContext $ctx, array $observers, array $pool): array
    {
        $perception = $ctx->ruleset()->balance->perception;
        $scouted = [];

        foreach ($observers as $clubId => $observer) {
            $qualities = [];
            $order = [];

            foreach ($pool as $index => $playerId) {
                $quality = $this->perceived($ctx, $observer, $playerId, $perception);

                // Une entite du vivier sans competences n'entre dans l'ordre
                // d'aucun club, donc n'est jamais signee : c'est ainsi qu'un
                // retraite reste hors du marche, la ou le vivier filtrait
                // autrefois sur la qualite vraie.
                if ($quality === null) {
                    continue;
                }

                $qualities[$playerId] = $quality;
                $order[] = $index;
            }

            usort($order, static fn (int $left, int $right): int
                => [$qualities[$pool[$right]], $pool[$left]] <=> [$qualities[$pool[$left]], $pool[$right]]);

            $scouted[$clubId] = ['qualities' => $qualities, 'order' => $order];
        }

        return $scouted;
    }

    /**
     * L'observateur de chaque club : la personne que le club emploie comme
     * scout, ou - s'il n'en emploie aucune - un observateur virtuel au jugement
     * `unstaffedJudgement`, deliberement mauvais (voir le champ).
     *
     * `id` est l'`EntityId` de la **personne**, et c'est lui qui entre dans la
     * derivation du bruit : la perception est attachee a quelqu'un, jamais a une
     * institution (docs/12- §4). Deux clubs qui echangeraient leurs scouts
     * echangeraient donc leurs erreurs. Faute de scout, l'`EntityId` du club
     * fait office de graine - il faut bien une identite stable pour que
     * l'estimation ne varie pas d'une annee sur l'autre.
     *
     * Plusieurs scouts sur un meme club : le meilleur jugement l'emporte, a
     * egalite le plus petit `EntityId`. Rien ne produit ce cas aujourd'hui (un
     * scout par club au genesis), mais l'ordre doit etre total de toute facon.
     *
     * @param list<int> $clubIds
     * @return array<int, array{id: int, judgement: int}>
     */
    private function observersByClub(SystemContext $ctx, array $clubIds): array
    {
        $unstaffed = $ctx->ruleset()->balance->perception->unstaffedJudgement;
        $observers = [];

        foreach ($clubIds as $clubId) {
            $observers[$clubId] = ['id' => $clubId, 'judgement' => $unstaffed];
        }

        foreach ($ctx->read(Scout::class)->entities() as $personId) {
            $scout = $ctx->read(Scout::class)->get($personId);
            $employment = $ctx->read(Employment::class)->get($personId);

            if ($scout === null || $employment === null || !isset($observers[$employment->clubId])) {
                continue;
            }

            $incumbent = $observers[$employment->clubId];

            if ($incumbent['id'] === $employment->clubId || $scout->judgement > $incumbent['judgement']) {
                $observers[$employment->clubId] = ['id' => $personId, 'judgement' => $scout->judgement];
            }
        }

        return $observers;
    }

    /**
     * Ce que cet observateur croit valoir ce joueur, ou `null` si le joueur n'en
     * est plus un (voir `census()` : un retraite du jour garde son `Contract`
     * jusqu'au tick suivant).
     *
     * @param array{id: int, judgement: int} $observer
     */
    private function perceived(SystemContext $ctx, array $observer, int $playerId, PerceptionBalance $perception): ?int
    {
        $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        $observations = $this->observationYears($ctx, $observer['id'], $playerId);

        return PerceptionModel::estimate(
            WageModel::quality($physical, $technical, $mental),
            // Invariant par tick, donc stable d'un mercato au suivant tant que
            // l'anciennete n'a pas change : une erreur d'evaluation est un biais
            // qu'on garde, pas un tirage qu'on refait (docs/12- §4).
            $ctx->stableHash($observer['id'], $playerId, $observations),
            $observations,
            $observer['judgement'],
            $perception,
        );
    }

    /**
     * Depuis combien d'annees cet observateur voit ce joueur a l'entrainement -
     * l'`observationCount` de docs/12- §4, sans aucun stockage par paire.
     *
     * Regle unique : l'anciennete du joueur au club **de l'observateur**, zero
     * partout ailleurs. Elle couvre d'elle-meme le joueur libere ce mercato-ci,
     * qui a encore son `Contract` a cet instant du tick : son ancien club le
     * connait toujours, les autres le decouvrent. C'est exactement la propriete
     * cherchee - un club connait mieux ses joueurs que ceux des autres, et se
     * trompe quand meme si son staff est mauvais.
     */
    private function observationYears(SystemContext $ctx, int $observerId, int $playerId): int
    {
        // Faute de scout, l'observateur *est* le club (voir `observersByClub()`).
        $clubId = $ctx->read(Employment::class)->get($observerId)->clubId ?? $observerId;
        $contract = $ctx->read(Contract::class)->get($playerId);

        if ($contract === null || $contract->clubId !== $clubId) {
            return 0;
        }

        return max(0, (int) (($ctx->tick - $contract->signedOn->epochDay) / 365));
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

}

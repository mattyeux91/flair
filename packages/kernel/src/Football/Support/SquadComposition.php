<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;

/**
 * L'effectif d'un club vu par poste (docs/12- §5, docs/14- §5 : « chaque club
 * evalue son effectif par poste »), et l'effectif qu'il cherche a tenir.
 * Extrait de `Football\ContractSystem`, qui portait deja exactement ce calcul
 * pour ses propres besoins de renouvellement.
 *
 * Deux consommateurs reels, jamais un seul : `ContractSystem` (mercato annuel)
 * et `Football\TransferSystem` (analyse de besoin, docs/17-marche-transferts.md
 * point 2) - le critere d'extraction que ce projet s'applique partout ailleurs
 * (cf. le docblock de `WageModel`).
 */
final class SquadComposition
{
    /**
     * L'effectif de chaque club ventile par poste, le poste d'un joueur etant
     * **derive** de ses competences (`PositionModel::bestPosition()`) et
     * jamais stocke - cf. docs/12- §4.
     *
     * @return array<int, array<string, int>> clubId -> [valeur du poste -> effectif]
     */
    public static function byPosition(SystemContext $ctx): array
    {
        $byClub = [];

        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);
            $position = self::positionOf($ctx, $playerId);

            if ($contract === null || $position === null) {
                continue;
            }

            $byClub[$contract->clubId][$position->value] = ($byClub[$contract->clubId][$position->value] ?? 0) + 1;
        }

        return $byClub;
    }

    /**
     * Combien de joueurs par poste un club cherche a tenir : les places de la
     * formation, mises a l'echelle de `ContractBalance::$targetSquadSize`. Un
     * 4-4-2 pour vingt joueurs donne deux gardiens, huit defenseurs, huit
     * milieux, quatre attaquants - un remplacant a chaque poste.
     *
     * L'arrondi vers le haut fait que la somme depasse legerement l'effectif
     * cible : c'est une cible **par poste**, pas une repartition d'un total,
     * et `targetSquadSize` reste le seul plafond dur.
     *
     * @return array<string, int>
     */
    public static function targets(PositionBalance $positions, ContractBalance $contract): array
    {
        $onPitch = 0;
        $targets = [];

        foreach (Position::cases() as $position) {
            $onPitch += PositionModel::slots($position, $positions);
        }

        foreach (Position::cases() as $position) {
            $slots = PositionModel::slots($position, $positions);
            $targets[$position->value] = $onPitch > 0
                ? (int) ceil($slots * $contract->targetSquadSize / $onPitch)
                : 0;
        }

        return $targets;
    }

    /** Le poste ou ce joueur note le mieux, ou `null` s'il n'a pas de competences. */
    private static function positionOf(SystemContext $ctx, int $playerId): ?Position
    {
        $physical = $ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        return PositionModel::bestPosition($physical, $technical, $mental);
    }
}

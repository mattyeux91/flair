<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Un joueur qui entre ou sort d'un club.
 *
 * Le sens (arrivee ou depart) n'est **pas** porte ici : il est donne par la
 * liste dans laquelle ce mouvement se trouve, parce qu'un meme
 * `ContractSigned` produit les deux - une arrivee pour le club qui signe, un
 * depart pour celui que le joueur quitte.
 *
 * `$feeCents` est `null` quand aucune indemnite n'a change de mains : fin de
 * contrat, promotion d'un jeune, ou joueur repris sans club. Un zero
 * signifierait « transfert a titre gratuit », ce qui n'est pas la meme chose.
 */
final readonly class MovementView
{
    public function __construct(
        public int $playerId,
        public string $playerName,
        public ?int $otherClubId,
        public ?string $otherClubName,
        public ?int $feeCents,
        public ?int $wagePerWeekCents,
        public int $tick,
    ) {
    }
}

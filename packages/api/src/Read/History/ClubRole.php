<?php

declare(strict_types=1);

namespace Flair\Api\Read\History;

/**
 * A quel titre un club est concerne par un Fait.
 *
 * Le role n'est pas decoratif : c'est lui qui distingue une arrivee d'un
 * depart alors que les deux naissent du **meme** `ContractSigned` - le club qui
 * signe est `Subject`, celui que le joueur quitte est `Previous`. Sans role, un
 * transfert apparaitrait deux fois a l'identique dans deux histoires, et on ne
 * saurait pas laquelle raconte un gain et laquelle une perte.
 *
 * Valeurs adossees a une chaine parce qu'elles sortent en JSON, comme
 * `Football\Components\Position`.
 */
enum ClubRole: string
{
    /** Le club dont le Fait parle directement : il signe, promeut, investit. */
    case Subject = 'subject';

    /** Le club que le joueur quitte (`ContractSigned::$previousClubId`). */
    case Previous = 'previous';

    case Home = 'home';
    case Away = 'away';
    case Buyer = 'buyer';
    case Seller = 'seller';

    /** Le club figure au classement final d'une saison conclue. */
    case Ranked = 'ranked';
}

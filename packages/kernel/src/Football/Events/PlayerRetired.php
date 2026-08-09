<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Un joueur raccroche : irreversible, donc un Fait (docs/16- §2 - la
 * retraite est l'exemple cite d'evenement qui franchit le test de
 * pertinence par irreversibilite). Seul evenement emis par
 * `Football\RetirementSystem` : la derive quotidienne des attributs ne
 * franchit aucun seuil decisionnel.
 *
 * ## `clubId`, et pourquoi il a fallu l'ajouter
 *
 * Ce Fait n'a longtemps porte que `playerId` et `ageYears`, ce qui suffisait
 * a son unique consommateur du noyau (`Football\SquadSystem`, qui relisait
 * `Contract` pour retrouver l'employeur). Le jour ou une lecture a voulu
 * raconter le **passe** d'un club, le manque est devenu irrattrapable : les
 * contrats du genesis ne sont pas dans l'event log, donc un joueur au meme
 * club depuis l'origine n'a aucun `ContractSigned` d'ou deduire son
 * employeur. Reconstruire aurait ete **silencieusement faux**, pas seulement
 * incomplet.
 *
 * D'ou la regle generale (docs/16- §2) : **un Fait porte de quoi l'attribuer
 * a ses sujets**, parce que l'etat courant ne rattrape jamais ce qu'un Fait
 * a omis.
 *
 * `null` pour un joueur sans club : il raccroche comme un autre, et personne
 * ne le perd.
 */
final class PlayerRetired implements DomainEvent
{
    public function __construct(
        public int $playerId,
        public int $ageYears,
        public ?int $clubId,
    ) {
    }
}

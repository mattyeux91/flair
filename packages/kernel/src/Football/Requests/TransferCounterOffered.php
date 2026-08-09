<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Requests;

use Flair\Kernel\Core\Messaging\DecisionRequest;

/**
 * Le club vendeur repond a l'offre en cours par une contre-demande plutot que
 * d'accepter ou de rompre (`Football\TransferSystem`,
 * docs/17-marche-transferts.md point 2). **L'acheteur doit trancher.**
 *
 * ## Pourquoi ce n'est pas un Fait, et pourquoi ca en etait un
 *
 * Ca a ete `Events\TransferCounterDemanded` jusqu'au 2026-08-09, journalise
 * comme le reste du marche. Le digest (Phase 4 lot 3) a rendu le defaut
 * visible : ces contre-demandes pesaient 10,6 % des Faits d'une fenetre de
 * mercato pour un contenu que **personne ne veut lire** - elles disaient
 * qu'une chose etait *en train* de se produire, quand un journal dit ce qui a
 * eu lieu.
 *
 * Mais « trop bavard » n'etait que le symptome. Les trois criteres de docs/16-
 * §1 designent tous la meme famille : une contre-demande est **adressee** a un
 * acheteur precis, elle **attend une reponse**, et elle a une **echeance**
 * (`Core\Ruleset\TransferBalance::$responseGraceTicks`, dont le docblock se
 * decrivait deja lui-meme comme « la version minimale de l'`expiresAtTick` que
 * docs/16- §1 attache aux `DecisionRequest` - l'echeance sans le canal »).
 * C'etait un `DecisionRequest` range dans les Faits, pas un Fait de trop.
 *
 * ## Ce que sa sortie du journal ne coute pas
 *
 * Rien a la mesure du point 2 de docs/17- (« la mediane des tours ne doit pas
 * s'ecraser sur 1 ») : `Events\TransferAgreed` **et**
 * `Events\TransferNegotiationBroken` portent tous deux `$round`, et toute
 * negociation se clot par l'un ou l'autre. La distribution reste calculable au
 * chiffre pres - verifie sur douze ans, graine 42 : mediane 2, moyenne 2,518,
 * 85 negociations ouvertes et 85 closes.
 *
 * Rien non plus au futur acheteur humain, qui est la raison pour laquelle ce
 * message n'a **pas** ete supprime : `Football\Intents\SubmittedBuyerIntentSource`
 * en fait le canal par lequel il apprendra la contre-demande. Il change de
 * famille, il ne disparait pas.
 *
 * ## Ce qu'il ne dira jamais
 *
 * `counterCents` est public : c'est ce que le vendeur dit a voix haute. Le
 * prix de reserve et le plafond de l'acheteur restent des variables de
 * decision **privees**, jamais exposees - meme logique que la verite cachee
 * (docs/12- §4). Une question est ce qu'on demande, pas ce qu'on pense.
 */
final class TransferCounterOffered implements DecisionRequest
{
    public function __construct(
        public int $negotiationId,
        public int $playerId,
        public int $round,
        public int $counterCents,
        public int $buyerClubId,
        public int $sellerClubId,
        /**
         * L'echeance que docs/16- §1 exige de tout `DecisionRequest` : au-dela,
         * `Football\TransferSystem` conclut au renoncement. Elle vaut le tick
         * courant quand `responseGraceTicks` est nul, c'est-a-dire « reponds
         * dans ce tick ou tu te retires » - le regime des PNJ, qui calculent
         * au lieu d'attendre.
         */
        public int $expiresAtTick,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Football\Components\Negotiation;

/**
 * D'ou viennent les decisions du club **acheteur** dans une negociation de
 * transfert (docs/17-marche-transferts.md point 3). Le point d'articulation
 * que docs/11- §3 appelle `IntentSource` : un agent humain et un agent PNJ
 * produisent les memes intentions, par la meme interface, et le noyau ne sait
 * pas lequel des deux lui a repondu.
 *
 * C'est une application directe du principe de substitution de Liskov
 * (docs/11- §8, « humain et PNJ doivent etre indiscernables du point de vue du
 * noyau ») et ca donne deux proprietes gratuites : un monde 100 % PNJ reste
 * jouable et testable, et un humain qui part peut etre repris par l'IA sans
 * rien casser.
 *
 * ## Portee : interface de domaine, pas `Core\IntentSource`
 *
 * L'esquisse du doc est generique (`produce(WorldView, tick): list<Intent>`).
 * Elle suppose un `WorldView` dont le contenu ne se decide pas avec un seul
 * consommateur - cf. le docblock de `TransferMarketView`. La generalisation
 * viendra du second domaine qui en aura besoin, pas d'avant.
 *
 * ## Contrat
 *
 * Une implementation doit etre **pure et deterministe** : elle tourne dans le
 * noyau, sous les memes regles que les systemes (docs/11- §1). Elle ne lit le
 * monde qu'a travers le `SystemContext` porte par la vue, donc uniquement ce
 * que `Football\TransferSystem` a declare dans `reads()`.
 *
 * Une intention rendue est une **demande**, pas un ordre :
 * `Football\TransferSystem` la valide avant de l'appliquer (docs/11- §3, « mises
 * en file, validees, puis consommees »). Une source n'a donc pas a verifier
 * elle-meme qu'un joueur est libre ou qu'un club est deja engage.
 */
interface BuyerIntentSource
{
    /**
     * Ce club ouvre-t-il une negociation cette annee, et pour qui ? `null`
     * pour s'abstenir.
     *
     * `$targetedPlayers` est indicatif - les joueurs deja vises par une
     * negociation ouverte, y compris celles ouvertes plus tot dans la meme
     * boucle. Une source peut l'ignorer : le systeme rejettera l'intention.
     *
     * @param array<int, true> $targetedPlayers playerId -> present
     */
    public function openingBid(TransferMarketView $view, int $buyerClubId, array $targetedPlayers): ?BidForPlayer;

    /**
     * Le vendeur a contre-demande `$counterCents`. Que fait l'acheteur ?
     *
     * `null` = **pas d'intention a ce tick**, ce qui n'est pas un retrait :
     * le systeme patiente `TransferBalance::$responseGraceTicks` avant
     * d'eteindre la negociation. Une source PNJ, qui calcule au lieu
     * d'attendre, rend `null` uniquement pour renoncer.
     */
    public function respondToCounter(
        TransferMarketView $view,
        int $negotiationId,
        Negotiation $negotiation,
        int $counterCents,
    ): ?RaiseTransferOffer;
}

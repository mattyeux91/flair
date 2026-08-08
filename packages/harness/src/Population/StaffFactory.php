<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\Scout;

/**
 * Un scout par club, seme au genesis (docs/12-modele-du-monde.md §4, question 1
 * tranchee) : aucun systeme du noyau ne cree de role non-joueur, exactement
 * comme aucun systeme ne cree `Facilities` ou `Finances`. La transition
 * retraite -> scout serait plus riche mais c'est un systeme entier, et elle
 * appartient a la gouvernance de club avec coach et president.
 *
 * ## Pourquoi le jugement est disperse entre clubs
 *
 * Un jugement uniforme ferait de la perception un bruit **symetrique** : tous
 * les clubs se trompant autant, personne ne serait mieux informe que son voisin
 * et le lot n'ajouterait que du hasard. C'est la dispersion qui fait de
 * l'asymetrie d'information une ressource (docs/12- §4).
 *
 * **Non correlee a la richesse du club, et ce n'est pas un oubli** : au genesis
 * tous les clubs sont identiques (memes installations, meme tresorerie, cf.
 * `ClubFactory`), il n'existe donc rien a quoi correler. Un lien endogene -
 * "un club riche s'offre un meilleur recruteur" - suppose une embauche, donc la
 * gouvernance de club : hors Phase 2. Le jugement reste ici exogene et
 * **statique**, ce qui en fait un handicap permanent pour les clubs mal servis :
 * effet a mesurer sur le Gini des titres, et `scoutJudgementSpread` est le
 * levier de recul s'il se revele trop fort.
 */
final class StaffFactory
{
    /**
     * Un seul scout par club. Le noyau sait deja departager plusieurs
     * observateurs pour un meme club (`ContractSystem::observersByClub()`), mais
     * rien ne justifie d'en semer deux : "qui observe qui" est une mecanique du
     * jeu d'agent (Phase 5), pas une question de generation de monde.
     */
    private const SCOUTS_PER_CLUB = 1;

    /**
     * @param list<int> $clubIds
     * @return list<int> identifiants des entites scout creees
     */
    public function create(WorldState $world, Rng $rng, array $clubIds, int $judgementMean, int $judgementSpread): array
    {
        $scoutIds = [];

        foreach ($clubIds as $clubId) {
            for ($i = 0; $i < self::SCOUTS_PER_CLUB; $i++) {
                $entity = $world->createEntity();
                $world->components(Person::class)->set($entity, new Person(
                    "Recruteur du club {$clubId}",
                    // Aucun systeme ne lit l'age d'un scout : ni retraite, ni
                    // vieillissement, ni progression du staff. Une date de
                    // naissance nulle plutot qu'un tirage qui decalerait le flux
                    // RNG du genesis pour rien.
                    new SimDate(0),
                ));
                $world->components(Employment::class)->set($entity, new Employment($clubId));
                $world->components(Scout::class)->set($entity, new Scout(
                    $this->judgement($rng, $judgementMean, $judgementSpread),
                ));

                $scoutIds[] = $entity;
            }
        }

        return $scoutIds;
    }

    /**
     * Un jugement uniforme sur `[mean - spread, mean + spread]`, clampe a
     * l'echelle absolue 1-100 (docs/12- §5).
     *
     * Uniforme et non gaussien : ce qui compte ici est qu'il **existe** de bons
     * et de mauvais recruteurs, et une loi uniforme rend l'ecart maximal lisible
     * directement dans le parametre - un `spread` de 25 signifie "du jugement 25
     * au jugement 75", ce qu'aucune loi a queues ne permet de dire aussi
     * simplement. Un `spread` de 0 rend tous les scouts identiques, ce qui est
     * l'experience de controle du lot.
     */
    private function judgement(Rng $rng, int $mean, int $spread): int
    {
        $spread = max(0, $spread);
        $offset = $spread === 0 ? 0 : (int) ($rng->nextUint32() % (2 * $spread + 1)) - $spread;

        return max(1, min(100, $mean + $offset));
    }
}

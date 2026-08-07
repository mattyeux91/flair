<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Intents;

use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Support\MarketValueModel;
use Flair\Kernel\Football\Support\PerceptionModel;
use Flair\Kernel\Football\Support\WageModel;

/**
 * Ce qu'un decideur voit du marche des transferts au moment de trancher : le
 * `WorldView` de docs/11- §3, **scope aux transferts** plutot que generique.
 *
 * Le doc esquisse un `IntentSource` generique prenant un `WorldView` lui aussi
 * generique. Ce qu'expose un `WorldView` (une projection ? un filtre par
 * acteur ?) ne se decide pas avec un seul consommateur pour en juger - c'est
 * la regle « deux consommateurs reels, jamais un seul, jamais par
 * anticipation » du projet. La propriete que le doc reclame vraiment (PNJ et
 * humain indiscernables du noyau, docs/11- §8) est tenue en entier par une
 * interface de domaine ; la generalisation attendra un second domaine.
 *
 * ## Ce que cette vue garantit
 *
 * Elle porte le `SystemContext` de `Football\TransferSystem`, donc une source
 * ne peut lire que ce que **le systeme** a declare dans `reads()` : elle ne
 * peut pas se fabriquer un acces. C'est ce qui rend l'injection sure sans
 * nouveau mecanisme de garde.
 *
 * Les agregats de ligue (rarete, richesse, effectifs) sont calcules **une
 * fois** par `TransferSystem` avant la boucle des clubs, pas par decideur :
 * c'est une vue partagee, pas un service par acheteur.
 *
 * `perceivedQuality()`/`valuation()` y vivent parce que l'acheteur et le
 * vendeur les calculent a l'identique, chacun avec son propre observateur -
 * un seul code, deux jugements. La qualite rendue est toujours **percue**
 * (docs/12- §4) : la verite cachee ne sort jamais d'ici.
 */
final readonly class TransferMarketView
{
    /**
     * @param array<int, array{id: int, judgement: int}> $observers clubId -> le meilleur oeil de ce club
     * @param array<string, float> $scarcity valeur de poste -> `rarete_poste`
     * @param array<int, float> $wealth clubId -> richesse relative a la ligue
     * @param array<int, array<string, int>> $squadByPosition clubId -> [valeur de poste -> effectif]
     * @param array<string, int> $targets valeur de poste -> effectif vise
     */
    public function __construct(
        public SystemContext $ctx,
        public SimDate $now,
        public array $observers,
        public array $scarcity,
        public array $wealth,
        public array $squadByPosition,
        public array $targets,
    ) {
    }

    /**
     * Ce que l'oeil de ce club croit de ce joueur - jamais sa vraie qualite.
     * `null` si le joueur n'a pas de competences (donc n'est pas un joueur).
     */
    public function perceivedQuality(int $observerClubId, int $playerId): ?int
    {
        $physical = $this->ctx->read(PlayerPhysicalSkills::class)->get($playerId);
        $technical = $this->ctx->read(PlayerTechnicalSkills::class)->get($playerId);
        $mental = $this->ctx->read(PlayerMentalSkills::class)->get($playerId);

        if ($physical === null || $technical === null || $mental === null) {
            return null;
        }

        $observer = $this->observers[$observerClubId]
            ?? ['id' => $observerClubId, 'judgement' => $this->ctx->ruleset()->balance->perception->unstaffedJudgement];
        $observations = $this->observationYears($observer['id'], $playerId);

        return PerceptionModel::estimate(
            WageModel::quality($physical, $technical, $mental),
            $this->ctx->stableHash($observer['id'], $playerId, $observations),
            $observations,
            $observer['judgement'],
            $this->ctx->ruleset()->balance->perception,
        );
    }

    /**
     * Le prix que ce club **croit** juste pour ce joueur (docs/14- §5, point 1
     * du chantier). `$buyerClubId` est le club dont la richesse entre dans le
     * calcul : l'acheteur lui-meme cote acheteur, et l'acheteur reel cote
     * vendeur aussi - seul proxy disponible pour sa reputation, absente du
     * noyau.
     *
     * `null` si le joueur n'a pas de quoi etre valorise.
     */
    public function valuation(int $observerClubId, int $playerId, Position $position, int $buyerClubId): ?int
    {
        $quality = $this->perceivedQuality($observerClubId, $playerId);
        $potentials = $this->ctx->read(PlayerPotentials::class)->get($playerId);
        $person = $this->ctx->read(Person::class)->get($playerId);
        $contract = $this->ctx->read(Contract::class)->get($playerId);

        if ($quality === null || $potentials === null || $person === null || $contract === null) {
            return null;
        }

        return MarketValueModel::value(
            $quality,
            $this->now->yearsSince($person->birthDate),
            $potentials,
            $this->now,
            $contract->expiresOn,
            $this->scarcity[$position->value] ?? 1.0,
            $this->wealth[$buyerClubId] ?? 1.0,
            1.0,
            $this->ctx->ruleset()->balance->market,
        );
    }

    /**
     * Depuis combien d'annees cet observateur cotoie ce joueur : `0` sauf s'il
     * est employe par le club qui l'emploie (docs/12- §4 - un scout juge mieux
     * les siens). Un observateur sans `Employment` est le club lui-meme.
     */
    private function observationYears(int $observerId, int $playerId): int
    {
        $employment = $this->ctx->read(Employment::class)->get($observerId);
        $clubId = $employment === null ? $observerId : $employment->clubId;
        $contract = $this->ctx->read(Contract::class)->get($playerId);

        if ($contract === null || $contract->clubId !== $clubId) {
            return 0;
        }

        return max(0, (int) (($this->ctx->tick - $contract->signedOn->epochDay) / 365));
    }
}

<?php

declare(strict_types=1);

namespace Flair\Host;

/**
 * Le compte rendu d'un tick : de quoi ecrire une ligne de log honnete et
 * mesurer le cout que docs/13- §7 annonce comme le vrai facteur limitant -
 * l'ecriture en base, pas le CPU du noyau.
 *
 * ## Trois compteurs, parce que deux mentaient
 *
 * `$simulationSeconds` et `$persistenceSeconds` totalisaient **34,7 ms sur
 * 48,5 mesurees** : le chargement du dernier snapshot etait pris avant le
 * premier `microtime()`, et le `COMMIT` arrive apres le retour de la closure
 * de transaction - structurellement hors de portee de tout compteur pose a
 * l'interieur. Les 29 % manquants n'etaient donc pas une imprecision, c'etait
 * un angle mort, et un chiffre de perf sert a decider.
 *
 * `$totalSeconds` entoure la transaction entiere. `overheadSeconds()` nomme
 * l'ecart : verrou, `COMMIT`, et ce que la couche base fait de son cote.
 */
final readonly class AdvanceResult
{
    public function __construct(
        public AdvanceOutcome $outcome,
        public int $tick = 0,
        public int $events = 0,
        public float $simulationSeconds = 0.0,
        public float $persistenceSeconds = 0.0,
        public float $totalSeconds = 0.0,
    ) {
    }

    /**
     * Ce que les deux compteurs internes ne voient pas : prise du verrou,
     * `COMMIT`, et l'enveloppe de la transaction elle-meme.
     *
     * `max(0, ...)` par prudence d'horloge, pas par conviction : les trois
     * mesures viennent du meme `microtime()` monotone en pratique, mais un
     * overhead negatif serait un chiffre absurde a afficher.
     */
    public function overheadSeconds(): float
    {
        return max(0.0, $this->totalSeconds - $this->simulationSeconds - $this->persistenceSeconds);
    }

    public static function busy(): self
    {
        return new self(AdvanceOutcome::Busy);
    }

    public static function unknown(): self
    {
        return new self(AdvanceOutcome::Unknown);
    }
}

<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Le bandeau de synthese : ce qui repond a « qu'est-ce que j'ai rate » **sans
 * etre trie**, parce que c'est un bilan et non une selection.
 *
 * C'est ici que vivent les matchs ordinaires. Trois mois en pleine saison en
 * comptent ~90, et les classer par ecart de buts remplirait le digest d'une
 * feuille de resultats - ce que le critere de sortie de la phase (« se comprend
 * en trente secondes ») exclut. Agreges en une ligne V/N/D ils informent ;
 * listes ils noient. Un match ne reapparait en clair dans les faits marquants
 * que par exception (`Digest\FactAmplitude`).
 */
final readonly class DigestSummaryView
{
    public function __construct(
        public int $played,
        public int $won,
        public int $drawn,
        public int $lost,
        public int $goalsFor,
        public int $goalsAgainst,
        public int $arrivals,
        public int $departures,
        public int $renewals,
        public int $youthPromoted,
        public int $retirements,
        public int $transferSpendCents,
        public int $transferIncomeCents,
        public int $facilitiesInvestedCents,
    ) {
    }

    /** Ce que le mercato a coute net sur la fenetre : positif = le club a depense. */
    public function netTransferCents(): int
    {
        return $this->transferSpendCents - $this->transferIncomeCents;
    }

    /** `true` si absolument rien n'est arrive au club sur la fenetre. */
    public function isEmpty(): bool
    {
        return $this->played === 0
            && $this->arrivals === 0
            && $this->departures === 0
            && $this->renewals === 0
            && $this->youthPromoted === 0
            && $this->retirements === 0
            && $this->facilitiesInvestedCents === 0;
    }
}

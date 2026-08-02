<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers de l'evolution des installations d'un club (docs/14-algorithmes.md
 * §7, "amortissement des infrastructures"), lus uniquement par
 * Football\FacilitiesSystem.
 *
 * Le partage avec `FinanceBalance` suit le partage des responsabilites entre
 * les deux systemes : la finance decide **combien d'argent** un club consacre
 * a ses installations (reserve, plafond par saison, cout d'entretien), les
 * installations decident **combien de qualite** cet argent achete et a quelle
 * vitesse elle se degrade. Aucun des deux ne lit les leviers de l'autre.
 */
final readonly class FacilitiesBalance
{
    public function __construct(
        /**
         * Combien coute un point entier de qualite d'installations. Un club
         * qui investit `centsPerQualityPoint / 20` sur une saison gagne 0,05
         * de qualite.
         *
         * Conversion **continue** plutot que par paliers : un pas fixe
         * creerait un effet de seuil autour de la tresorerie du club (juste
         * au-dessus, il progresse ; juste en dessous, rien), la ou le
         * continu rend la qualite d'equilibre une fonction monotone de la
         * richesse - beaucoup plus facile a equilibrer et a lire dans le
         * harness.
         */
        public int $centsPerQualityPoint = 200_000_000,
        /**
         * Perte de qualite a chaque fin de saison, avant tout
         * investissement. C'est elle qui donne un cout permanent au maintien
         * du niveau : un club qui cesse d'investir redescend.
         *
         * Inconditionnelle, et **pas** "seulement si le club est dans le
         * rouge". La regle binaire creerait une falaise - au calibrage
         * actuel un club peut passer sous zero pour des raisons sans rapport
         * avec ses installations, et tout le monde s'effondrerait ensemble.
         * Une derive constante que l'investissement compense donne un
         * equilibre continu, fonction directe des revenus du club : c'est le
         * rendement decroissant recherche par docs/14- §7.
         */
        public float $qualityDecayPerSeason = 0.05,
    ) {
    }
}

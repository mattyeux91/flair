<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers de la perception : de combien un observateur se trompe sur ce qu'il
 * evalue (docs/12-modele-du-monde.md §4), traduits par
 * `Football\Support\PerceptionModel` a qui ce groupe est passe en entier.
 *
 * **Ce groupe dit combien un jugement donne se trompe, jamais qui sont les
 * observateurs.** Le jugement d'un scout est une donnee du monde, portee par le
 * composant `Football\Scout` et semee au genesis - la meme frontiere regle/etat
 * que docs/12- §3 bis. Consequence pratique a connaitre : un levier de genesis
 * pose ici serait silencieusement inoperant sous
 * `Harness\Comparison\RulesetOverride`, qui ne rejoue jamais la generation du
 * monde (c'est le principe des graines appariees - meme population des deux
 * cotes, seul le `Ruleset` change).
 *
 * `Core` ne nomme aucun type du domaine football (docs/11- §7) : rien ici ne
 * connait `Scout` ni le fait qu'un club emploie qui que ce soit.
 */
final readonly class PerceptionBalance
{
    public function __construct(
        /**
         * L'ecart-type de l'erreur d'estimation, en points de l'echelle
         * absolue 1-100 (docs/12- §5), pour un observateur exactement a
         * `judgementReference` et qui n'a jamais observe son sujet.
         *
         * **`0.0` rend la perception exacte** - tout observateur lit la verite
         * cachee, c'est-a-dire le comportement d'avant ce lot. C'est
         * l'interrupteur de mesure du lot (`--set baseErrorPoints=0`), et il
         * doit rester une reduction *exacte*, pas approchee.
         *
         * Premiere calibration, a confronter a la mesure : a 10 points, un
         * staff median se trompe d'environ 10 points sur une recrue inconnue -
         * soit ~20 % de salaire de travers via `WageModel` - et un staff
         * mediocre (jugement 20) se trompe deux fois plus qu'un excellent
         * (jugement 85). L'ordre de grandeur cherche est celui qui rend le
         * recrutement faillible sans le rendre aleatoire.
         */
        public float $baseErrorPoints = 10.0,
        /**
         * Le jugement pour lequel `baseErrorPoints` se lit litteralement. 50
         * sur une echelle bornee a [1, 100] : le staff median, meme ancrage que
         * `ContractBalance::$referenceQuality`.
         */
        public int $judgementReference = 50,
        /**
         * Le jugement impute a un club qui n'emploie aucun observateur.
         *
         * **Pas une vision parfaite**, et c'est tout l'objet du lot : un club
         * sans staff qui verrait juste serait exactement l'affirmation de
         * conception fausse - "un club connait forcement ses joueurs" - que
         * cette brique vient supprimer. Un club sans scout est le pire
         * observateur du monde, pas le meilleur.
         */
        public int $unstaffedJudgement = 20,
    ) {
    }
}

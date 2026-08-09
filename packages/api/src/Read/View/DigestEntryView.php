<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Une ligne du digest : un Fait retenu, sa phrase, et de quoi comprendre
 * pourquoi il a ete retenu.
 *
 * `$score`, `$amplitude` et `$roleWeight` sont exposes **exprès**. Le tri par
 * pertinence de docs/14- §9 est la seule vraie difficulte du digest, et un tri
 * dont on ne peut pas lire les entrees est un tri qu'on ne peut pas corriger :
 * c'est exactement ce qui a laisse le marche n'acheter que des defenseurs
 * pendant tout un lot. La page ne les affiche pas au premier plan, la route
 * JSON les porte toujours.
 */
final readonly class DigestEntryView
{
    public function __construct(
        public int $tick,
        public int $seq,
        /** La cle stable du `TypeRegistry`, jamais le FQCN - cf. `LoggedFactView`. */
        public string $type,
        public string $sentence,
        /** Le role du club qui lit, `null` dans le bloc « le monde ». */
        public ?string $role,
        public float $score,
        public float $amplitude,
        public float $roleWeight,
        public float $freshness,
    ) {
    }
}

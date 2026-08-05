<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Les quatre postes du monde (docs/12-modele-du-monde.md §5,
 * docs/15-roadmap.md §4 Phase 2).
 *
 * ## Pourquoi quatre, et pas neuf
 *
 * Le poste porte **la place et la rarete**, les attributs portent **le
 * style** : un ailier est un `Midfielder` a `pace`/`technique` eleves, pas un
 * poste de plus. Ajouter "ailier", "lateral" ou "milieu defensif" comme postes
 * dupliquerait ce que le profil d'attributs exprime deja, au prix d'une
 * formation et d'une generation nettement plus lourdes.
 *
 * Ce que quatre postes suffisent a produire, et qui est le vrai objectif :
 * une **demande heterogene**. En 4-4-2 les raretes sont tres contrastees (un
 * gardien contre quatre defenseurs), donc un club qui cherche un gardien ne
 * peut pas se consoler avec un attaquant - c'est de la que viendront les
 * vraies negociations du marche (docs/14- §5, `rarete_poste`).
 *
 * Precedent assume : Hattrick tient un monde persistant depuis vingt ans avec
 * huit competences fortement liees au poste, sans jamais avoir eu besoin d'une
 * taxonomie fine.
 *
 * ## Ce type n'est **pas** "le poste d'un joueur"
 *
 * Aucune entite ne porte "son poste". Deux usages distincts, a ne pas
 * confondre (`Football\Support\PositionModel`) :
 *
 * - **L'archetype de developpement**, porte par `PlayerPotentials` : la forme
 *   du potentiel, fixee a la naissance comme un gabarit physique. C'est lui
 *   qui decide qu'un gardien a un plafond de finition bas, definitivement.
 * - **Le poste joue**, jamais stocke : `PositionModel::bestPosition()` le
 *   **derive** des competences du moment. Une etiquette stockee deriverait de
 *   la realite sur vingt saisons de developpement ; derivee, elle suit le
 *   joueur. Meme principe que la perception (docs/12- §4), qui n'est jamais
 *   stockee non plus.
 *
 * La valeur de secours (`string`) sert au `Ruleset` et aux projections, qui
 * ne peuvent pas dependre du domaine football (`Core` n'importe jamais
 * `Football`, docs/11- §7).
 */
enum Position: string
{
    case Goalkeeper = 'GK';
    case Defender = 'DEF';
    case Midfielder = 'MID';
    case Attacker = 'ATT';
}

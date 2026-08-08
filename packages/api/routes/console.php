<?php

declare(strict_types=1);

/*
 * Aucune commande console ici, et c'est voulu.
 *
 * Ce paquet **lit** un monde ; celui qui le fait vivre est `flair/host`, dont
 * le CLI (`packages/host/bin/host.php`) cree les mondes, les avance et lit
 * l'event log. Ajouter des commandes ici dupliquerait cette surface, ou pire,
 * donnerait a une application de lecture les moyens d'ecrire.
 *
 * La commande `inspire` du squelette a ete retiree : elle etait la seule chose
 * dans ce fichier, et sa closure sans `$this` type ne passe pas PHPStan au
 * niveau max - or garder la couche HTTP et les routes **dans** l'analyse est
 * precisement la lecon de `packages/harness/public/`, casse depuis des semaines
 * parce qu'il en etait exclu.
 */

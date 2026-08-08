<?php

declare(strict_types=1);

namespace Flair\Kernel;

/**
 * Identite du noyau. Un monde est epingle a (kernelVersion, rulesetVersion)
 * (docs/12-modele-du-monde.md §6, docs/13-moteur-de-simulation.md §6) : cette
 * constante est la premiere moitie de ce couple, la seconde etant
 * Core\Ruleset\Ruleset::$version.
 *
 * Elle est ecrite dans chaque snapshot (Core\Snapshot\WorldSnapshot) et relue
 * au chargement. A faire monter des que la *forme* de l'etat persistant change
 * - un composant qui gagne ou perd un champ, un type qui change de nom de
 * cle - parce qu'un snapshot ecrit par une version anterieure ne se relit
 * alors plus sans migration explicite (jamais un rejeu, docs/13- §6).
 *
 * Volontairement une constante de code et non une donnee : c'est le code du
 * noyau qui definit la forme de l'etat, donc les deux versionnent ensemble.
 */
final class Kernel
{
    public const string VERSION = '0.1.0';
}

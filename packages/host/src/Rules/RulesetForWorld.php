<?php

declare(strict_types=1);

namespace Flair\Host\Rules;

use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * Traduit le `rulesetVersion` d'un monde persiste en `Ruleset` - et **leve**
 * pour toute version qu'il ne sait pas reconstruire.
 *
 * ## Pourquoi ce garde vit dans `host` et pas dans le noyau
 *
 * `Core\Ruleset\Ruleset` accepte deliberement n'importe quelle chaine de
 * version et rend toujours le meme `Balance` : ce n'est pas un oubli, c'est
 * que la version y est une **etiquette**, et le harness s'en sert comme telle
 * (`'harness'`, `'sandbox'`, `'ci'`, `'test'`, `'regression'`,
 * `'snapshot-continuity'`). Un run de mesure porte son nom dans son ruleset et
 * personne ne reconstruit rien a partir de cette chaine.
 *
 * Un **monde persiste**, lui, est different : la chaine est ecrite en base au
 * genesis et sert plus tard a *reconstruire* les regles, un tick a la fois,
 * peut-etre des mois apres (docs/12- §6). La, une chaine qui ne determine pas
 * les regles est un monde qui tourne selon des regles qui ne sont pas les
 * siennes, sans que rien ne le signale. Le garde appartient donc a la couche
 * qui persiste, pas a celle qui calcule.
 *
 * ## Ce qu'il rend impossible, au-dela de rendre l'erreur bruyante
 *
 * `Worldgen\WorldFactory::populate()` accepte des groupes de `Balance` que
 * `Host\CreateWorld` ne lui passe pas : le genesis utilise donc **toujours**
 * les defauts du noyau. Tant qu'une seule version est acceptee, ce n'est pas
 * une approximation mais une identite - genesis et avancement lisent
 * forcement les memes regles. Le garde ne se contente pas de faire du bruit,
 * il rend cette classe entiere de desaccord inatteignable.
 *
 * ## Le jour ou `packages/ruleset` existera
 *
 * C'est le seul site a rebrancher : cette classe deviendra une facade sur le
 * catalogue versionne de ce package (docs/12- §6 - schema JSON valide,
 * migrations explicites). Ni `CreateWorld` ni `AdvanceWorld` n'auront a
 * changer.
 */
final class RulesetForWorld
{
    /**
     * La seule version de regles que ce Host sait reconstruire : celle des
     * defauts que le noyau embarque dans son code.
     */
    public const string VERSION = 'default';

    public static function supports(string $version): bool
    {
        return $version === self::VERSION;
    }

    public static function for(string $version): Ruleset
    {
        return self::supports($version)
            ? new Ruleset($version)
            : throw new UnsupportedRulesetVersion($version, self::VERSION);
    }
}

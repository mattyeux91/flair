<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Architecture;

use Flair\Api\Read\Digest\FactAmplitude;
use Flair\Api\Read\Digest\FactSentence;
use Flair\Api\Tests\Support\FactFabricator;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Football\FootballTypes;
use PHPUnit\Framework\TestCase;

/**
 * Le meme garde-fou que `EveryFactIsPlacedOrExcludedTest`, cote digest.
 *
 * Un Fait ajoute au noyau et oublie de `Digest\FactAmplitude` n'echouerait
 * nulle part : il vaudrait zero, donc ne serait **jamais** raconte, et personne
 * ne le verrait avant de se demander pourquoi une nouvelle categorie
 * d'evenement n'apparait jamais dans un digest. C'est le mode de panne que ce
 * projet a deja paye trois fois - la serialisation
 * (`SnapshotConformanceTest`), l'histoire d'un club, et le formulaire de
 * calibrage du harness.
 *
 * La regle est « note **ou** explicitement exclu ». L'exclusion demande
 * d'inscrire le type dans `FactAmplitude::NEVER_NEWSWORTHY` avec sa raison,
 * ce qui transforme un oubli en decision relisible.
 *
 * ⚠️ Ce test ne dit **pas** que le digest est bon. Il dit que rien n'y disparait
 * en silence. Savoir si les seuils sont bien regles se fait en ouvrant la page,
 * ce qu'aucun test ne remplacera - c'est tout l'objet de docs/14- §9.
 */
final class DigestCoversEveryFactTest extends TestCase
{
    public function testEveryRegisteredFactIsEitherScoredOrExplicitlyExcluded(): void
    {
        $amplitude = new FactAmplitude();
        $events = FootballTypes::registry()->events;

        self::assertGreaterThan(10, count($events), 'Le registre des Faits semble vide : FootballTypes a bouge.');

        foreach ($events as $key => $class) {
            $excluded = array_key_exists($class, FactAmplitude::NEVER_NEWSWORTHY);
            $scored = $amplitude->handles(FactFabricator::make($class));

            self::assertTrue(
                $scored || $excluded,
                sprintf(
                    "Le Fait \"%s\" (%s) n'a aucune regle d'amplitude et n'est pas inscrit dans NEVER_NEWSWORTHY.\n"
                    . "Il ne serait jamais raconte dans un digest, en silence.\n"
                    . 'Soit il merite d\'etre raconte et il faut un cas dans FactAmplitude::of(), soit non et il '
                    . 'faut l\'inscrire dans la liste **avec sa raison**.',
                    $key,
                    $class,
                ),
            );

            self::assertFalse(
                $scored && $excluded,
                sprintf('Le Fait "%s" a une regle d\'amplitude **et** est exclu : la liste NEVER_NEWSWORTHY ment.', $key),
            );
        }
    }

    public function testEveryExclusionCarriesAReason(): void
    {
        foreach (FactAmplitude::NEVER_NEWSWORTHY as $class => $reason) {
            self::assertArrayHasKey(
                $class,
                array_flip(FootballTypes::registry()->events),
                "{$class} est exclu mais n'est plus un Fait enregistre : la liste a survecu a son sujet.",
            );

            self::assertNotSame('', trim($reason), "L'exclusion de {$class} n'a pas de raison ecrite.");
        }
    }

    /**
     * Tout Fait qui peut etre raconte doit avoir une **vraie** phrase.
     *
     * Sans ca, `FactAmplitude` et `FactSentence` pourraient diverger : un Fait
     * note mais sans phrase remonterait en tete du digest pour y afficher le
     * repli « Evenement sans recit. », ce qui est pire que de ne pas
     * l'afficher du tout.
     */
    public function testEveryScoredFactHasASentence(): void
    {
        $amplitude = new FactAmplitude();
        $sentence = new FactSentence();
        $state = new WorldState();

        foreach (FootballTypes::registry()->events as $key => $class) {
            if (!$amplitude->handles(FactFabricator::make($class))) {
                continue;
            }

            foreach ([null, 1, 2] as $viewpoint) {
                $text = $sentence->of($state, FactFabricator::make($class), $viewpoint);

                self::assertNotSame(
                    'Evenement sans recit.',
                    $text,
                    "Le Fait \"{$key}\" a une amplitude mais aucune phrase : FactAmplitude et FactSentence ont diverge.",
                );

                self::assertNotSame('', trim($text), "Le Fait \"{$key}\" rend une phrase vide.");
                self::assertStringEndsWith('.', trim($text), "La phrase du Fait \"{$key}\" n'est pas une phrase.");
            }
        }
    }
}

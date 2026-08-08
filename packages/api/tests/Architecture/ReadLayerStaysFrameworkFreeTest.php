<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Le test qui fait **mériter** la frontiere `src/` vs `app/`.
 *
 * Ce paquet a deux racines d'autoload : `Flair\Api\` dans `src/` (la lecture du
 * monde, PHP nu) et `App\` dans `app/` (l'adaptation a Laravel). Sans ce test,
 * cette frontiere ne serait qu'une **convention** - vraie aujourd'hui parce
 * qu'on l'a ecrite ainsi, et exactement aussi fragile qu'un simple
 * `app/Read/`. Il suffirait d'un `use Illuminate\Support\Collection;` pour
 * qu'elle cesse d'exister, sans que personne le voie.
 *
 * Or une frontiere qui coute une decision par fichier sans rien garantir est
 * pire que pas de frontiere du tout. Ce test est le prix a payer pour la
 * garder, et c'est le meme idiome que
 * `Kernel\Tests\Core\Snapshot\SnapshotConformanceTest` : **balayer le disque
 * plutot qu'ecrire une liste a la main**, puisqu'une liste a la main aurait le
 * defaut qu'on corrige.
 *
 * ## Ce que la garantie achete concretement
 *
 * Les neuf classes de `src/` lisent un ECS ; Laravel n'y sert a rien. Les
 * garder framework-free n'est pas de l'esthetique :
 *
 * - leurs tests n'ont pas besoin de booter une application HTTP, donc ils
 *   testent une chose au lieu de deux (voir `Tests\ReadTestCase`, qui n'etend
 *   deliberement pas la classe de test de Laravel) ;
 * - le digest, SSE et `game-web` reutiliseront cette couche sans heriter des
 *   choix d'un framework fait pour du HTTP ;
 * - et le jour ou l'admin sortirait en paquet separe, `src/` part sans
 *   discussion. Ce dernier point est un **bonus**, jamais la justification :
 *   une frontiere posee pour un futur hypothetique serait l'anticipation que
 *   ce projet refuse partout ailleurs.
 */
final class ReadLayerStaysFrameworkFreeTest extends TestCase
{
    /**
     * Les racines de namespace que `src/` n'a pas le droit d'importer.
     *
     * `App\` figure dans la liste et ce n'est pas un detail : la dependance est
     * a **sens unique**. Un contrôleur connait la couche de lecture ; la couche
     * de lecture ne connait pas les contrôleurs, sinon la frontiere devient une
     * boucle et ne separe plus rien.
     */
    private const array FORBIDDEN = [
        'Illuminate\\',
        'Symfony\\',
        'Laravel\\',
        'App\\',
    ];

    public function testNothingInTheReadLayerImportsTheFramework(): void
    {
        $files = $this->phpFilesIn(dirname(__DIR__, 2) . '/src');

        self::assertGreaterThan(5, count($files), 'Le balayage n\'a rien trouve : le chemin de `src/` a bouge.');

        foreach ($files as $path => $source) {
            foreach ($this->importsOf($source) as $import) {
                foreach (self::FORBIDDEN as $forbidden) {
                    self::assertStringStartsNotWith($forbidden, $import, sprintf(
                        "%s importe %s.\n"
                        . "`src/` (Flair\\Api\\) doit rester du PHP nu : il ne connait que flair/host et flair/kernel.\n"
                        . 'Si cet import est vraiment necessaire, la classe appartient a `app/` - ou la frontiere '
                        . 'ne merite plus d\'exister, et il faut alors tout ramener sous `App\\` plutot que la laisser mentir.',
                        $path,
                        $import,
                    ));
                }
            }
        }
    }

    /**
     * L'inverse n'est pas teste, et c'est volontaire : `app/` **doit** importer
     * `Flair\Api\` (c'est tout son role) et Laravel (c'est sa raison d'etre).
     * Il n'y a donc rien a y interdire. Ce qui vaut d'y verifier, en revanche,
     * c'est qu'aucun contrôleur ne lise le monde directement - la regle « rien
     * en dehors de Flair\Api\Read\ ne lit le snapshot ni l'event log ».
     */
    public function testNoControllerReachesForTheStoresItself(): void
    {
        $files = $this->phpFilesIn(dirname(__DIR__, 2) . '/app/Http');

        self::assertNotSame([], $files, 'Aucun contrôleur trouve : le chemin de `app/Http` a bouge.');

        foreach ($files as $path => $source) {
            foreach ($this->importsOf($source) as $import) {
                self::assertStringStartsNotWith('Flair\\Host\\Store\\', $import, sprintf(
                    "%s importe %s.\n"
                    . 'Un contrôleur assemble, il ne lit pas. Toute lecture du monde passe par Flair\\Api\\Read\\, '
                    . 'sinon les pages et le JSON peuvent diverger sans que PagesMatchJsonTest le voie.',
                    $path,
                    $import,
                ));
                self::assertStringStartsNotWith('Flair\\Kernel\\Core\\Snapshot\\', $import, sprintf(
                    '%s importe %s : decoder un snapshot est le travail de Flair\\Api\\Read\\WorldReader.',
                    $path,
                    $import,
                ));
            }
        }
    }

    /**
     * Les `use` de premier niveau d'un fichier. Une analyse textuelle suffit et
     * se lit d'un coup d'oeil : on cherche des imports, pas a comprendre du
     * code, et un import qui passerait entre les mailles serait un FQCN en
     * ligne - lequel sauterait aux yeux en relecture.
     *
     * @return list<string>
     */
    private function importsOf(string $source): array
    {
        preg_match_all('/^use\s+(?:function\s+|const\s+)?([^\s;]+)/m', $source, $matches);

        return $matches[1];
    }

    /** @return array<string, string> chemin relatif => contenu */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                $files[substr($path, strlen(dirname(__DIR__, 2)) + 1)] = (string) file_get_contents($path);
            }
        }

        ksort($files);

        return $files;
    }
}

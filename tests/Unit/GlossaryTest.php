<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Glossary;
use PHPUnit\Framework\TestCase;

/**
 * Glossaire : chaque fiche est complète (définition simple + exemple), et chaque bulle ui_term() des vues
 * renvoie à une fiche existante.
 */
final class GlossaryTest extends TestCase
{
    private const BASE = __DIR__ . '/../..';

    public function testEveryTermIsCompleteAndWellFormed(): void
    {
        $methodology = (string) file_get_contents(self::BASE . '/app/Views/pages/methodology.php');
        self::assertGreaterThanOrEqual(30, count(Glossary::terms()));
        foreach (Glossary::terms() as $slug => $term) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            self::assertArrayHasKey($term['category'], Glossary::CATEGORIES, $slug);
            self::assertNotSame('', trim($term['definition']), $slug);
            self::assertNotSame('', trim($term['example']), $slug);
            if (isset($term['see'])) {
                self::assertStringContainsString('id="' . $term['see'] . '"', $methodology, $slug . ' : ancre de méthodologie inconnue');
            }
        }
        foreach (['mrr', 'arr', 'cout-horaire', 'prix-plancher', 'marge-prix-vente', 'churn'] as $required) {
            self::assertArrayHasKey($required, Glossary::terms());
        }
    }

    public function testGroupingKeepsEveryTerm(): void
    {
        $count = 0;
        foreach (Glossary::byCategory() as $terms) {
            $count += count($terms);
        }
        self::assertSame(count(Glossary::terms()), $count);
    }

    public function testUnknownTermIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Glossary::get('inexistant');
    }

    public function testEveryBubbleInViewsPointsToAnExistingTerm(): void
    {
        $terms = Glossary::terms();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::BASE . '/app', \FilesystemIterator::SKIP_DOTS));
        $found = 0;
        foreach ($iterator as $file) {
            if (!str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/ui_term\\('([a-z0-9-]+)'/", $source, $calls);
            preg_match_all("/'term' => '([a-z0-9-]+)'/", $source, $fields);
            preg_match_all("#/app/glossaire\\#([a-z0-9-]+)#", $source, $links);
            foreach ([...$calls[1], ...$fields[1], ...$links[1]] as $slug) {
                $found++;
                $isCategory = str_starts_with($slug, 'rubrique-') && isset(Glossary::CATEGORIES[substr($slug, 9)]);
                self::assertTrue(isset($terms[$slug]) || $isCategory, $file->getFilename() . ' : terme inconnu « ' . $slug . ' »');
            }
        }
        self::assertGreaterThan(30, $found);
    }

    public function testBubbleRendersDefinitionExampleAndLink(): void
    {
        $html = ui_term('mrr', 'Précision <propre> à l\'écran.');
        self::assertStringContainsString('MRR (revenu récurrent mensuel)', $html);
        self::assertStringContainsString('Exemple :', $html);
        self::assertStringContainsString('href="/app/glossaire#mrr"', $html);
        self::assertStringContainsString('Précision &lt;propre&gt;', $html);
        self::assertStringNotContainsString('tip__formula', $html);
        self::assertStringContainsString('tip__formula', ui_term('mrr', formula: true));
        self::assertStringNotContainsString('tip__more', ui_tip('t', 'L', 'T', 'B', [], 'start', null, 'https://evil.test'));
    }
}

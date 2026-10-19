<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the movement How-to-Use guide, its runnable example, and the entry
 * points that link to them consistent (Issue #40). It checks structure and
 * publication-status wording only; the behavior of the example is proven by the
 * MySQL suites.
 */
final class MoveWithinScopeDocumentationTest extends TestCase
{
    private const GUIDE = 'docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md';

    public function testGuideListsEveryMovementExampleScript(): void
    {
        $guide = $this->read(self::GUIDE);
        $scripts = glob($this->path('examples/move-within-scope') . '/[0-9][0-9]_*.php');
        self::assertIsArray($scripts);
        self::assertNotSame([], $scripts);

        foreach ($scripts as $script) {
            self::assertStringContainsString(
                'examples/move-within-scope/' . basename($script),
                $guide,
                basename($script) . ' is not documented in the guide.',
            );
        }
    }

    public function testEveryExampleReferencedByTheGuideExists(): void
    {
        $guide = $this->read(self::GUIDE);
        $matched = preg_match_all('#examples/(?:move-within-scope|next-position)/([A-Za-z0-9_]+\.php)#', $guide, $matches);
        self::assertGreaterThan(0, $matched);

        foreach (array_unique($matches[0]) as $path) {
            self::assertFileExists($this->path($path));
        }
    }

    public function testReadmeAndPackageReferenceLinkToTheGuideAndExample(): void
    {
        foreach (['README.md', 'PERSISTENCE_PACKAGE_REFERENCE.md'] as $file) {
            $contents = $this->read($file);
            self::assertStringContainsString(self::GUIDE, $contents, $file);
            self::assertStringContainsString('examples/move-within-scope', $contents, $file);
        }
    }

    public function testGuideStatesTheUnreleasedStatusAndTheAllocationBoundary(): void
    {
        // Collapse line wraps and blockquote markers so the wording is checked, not the layout.
        $guide = preg_replace('/\s*\n(?:>\s*)?/', ' ', $this->read(self::GUIDE));
        self::assertIsString($guide);

        self::assertStringContainsString('Unreleased', $guide);
        self::assertStringContainsString('not part of the stable `v1.4.0`', $guide);
        self::assertStringContainsString('Why `getNextPositionForUpdate()` is not the movement fix', $guide);
        self::assertStringContainsString('is not a guarantee', $guide);
    }

    public function testGuideLinksTheTestsThatVerifyTheExample(): void
    {
        $guide = $this->read(self::GUIDE);

        foreach (
            [
                'tests/Integration/Examples/MoveWithinScopeExamplesTest.php',
                'tests/Integration/Pdo/Ordering/ScopedOrderingManagerMoveStaleSnapshotTest.php',
                'composer test:consumer',
            ] as $reference
        ) {
            self::assertStringContainsString($reference, $guide);
        }

        self::assertFileExists($this->path('tests/Integration/Examples/MoveWithinScopeExamplesTest.php'));
        self::assertFileExists($this->path('tests/Integration/Pdo/Ordering/ScopedOrderingManagerMoveStaleSnapshotTest.php'));
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->path($relativePath));
        self::assertIsString($contents);

        return $contents;
    }

    private function path(string $relativePath): string
    {
        return dirname(__DIR__, 3) . '/' . $relativePath;
    }
}

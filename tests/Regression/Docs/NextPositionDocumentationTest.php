<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the consumer How-to-Use guide, its examples, and the entry points that
 * link to them consistent. It checks structure and publication-status wording
 * only; the behavior of the examples is proven by the MySQL suites.
 */
final class NextPositionDocumentationTest extends TestCase
{
    private const GUIDE = 'docs/guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md';

    public function testGuideListsEveryExampleScript(): void
    {
        $guide = $this->read(self::GUIDE);
        $scripts = glob($this->path('examples/next-position') . '/[0-9][0-9]_*.php');
        self::assertIsArray($scripts);
        self::assertNotSame([], $scripts);

        foreach ($scripts as $script) {
            self::assertStringContainsString(
                'examples/next-position/' . basename($script),
                $guide,
                basename($script) . ' is not documented in the guide.',
            );
        }
    }

    public function testEveryExampleReferencedByTheGuideExists(): void
    {
        $guide = $this->read(self::GUIDE);
        $matched = preg_match_all('#examples/next-position/([A-Za-z0-9_]+\.php)#', $guide, $matches);
        self::assertGreaterThan(0, $matched);

        foreach (array_unique($matches[1]) as $file) {
            self::assertFileExists($this->path('examples/next-position/' . $file));
        }
    }

    public function testReadmeAndPackageReferenceLinkToTheGuide(): void
    {
        self::assertStringContainsString(self::GUIDE, $this->read('README.md'));
        self::assertStringContainsString(self::GUIDE, $this->read('PERSISTENCE_PACKAGE_REFERENCE.md'));
        self::assertStringContainsString('examples/next-position', $this->read('README.md'));
    }

    public function testGuideStatesThatTheMethodIsNotInTheStableV140Release(): void
    {
        // Collapse line wraps and blockquote markers so the wording is checked, not the layout.
        $guide = preg_replace('/\s*\n(?:>\s*)?/', ' ', $this->read(self::GUIDE));
        self::assertIsString($guide);

        self::assertStringContainsString('Unreleased', $guide);
        self::assertStringContainsString('not part of the stable `v1.4.0`', $guide);
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

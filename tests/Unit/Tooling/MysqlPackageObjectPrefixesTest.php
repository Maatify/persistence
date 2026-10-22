<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Tooling;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * scripts/mysql-package-objects.php is the single source for the names the
 * MySQL residue check inspects. Every `maa_` object name the tests, examples
 * and Consumer harness use must be covered by it, so a new family of test
 * objects cannot slip past the residue check unnoticed.
 */
final class MysqlPackageObjectPrefixesTest extends TestCase
{
    public function testPrefixesAreWellFormed(): void
    {
        $prefixes = $this->prefixes();

        self::assertNotSame([], $prefixes);

        foreach ($prefixes as $prefix) {
            self::assertMatchesRegularExpression('/^maa_[a-z0-9_]+_$/', $prefix);
        }
    }

    public function testEveryMaaObjectNameUsedByTestsAndExamplesIsCovered(): void
    {
        $root = dirname(__DIR__, 3);
        $self = __FILE__;
        $uncovered = [];
        $found = 0;

        foreach (['tests', 'examples'] as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getPathname() === $self) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                preg_match_all('/\bmaa_[a-z0-9_]+/', $source, $matches);

                foreach ($matches[0] as $name) {
                    ++$found;
                    if (!$this->isCovered($name)) {
                        $uncovered[$name] = $file->getPathname();
                    }
                }
            }
        }

        self::assertGreaterThan(0, $found, 'the scan must find the package test object names');
        self::assertSame([], $uncovered, 'object names outside the residue check prefixes');
    }

    public function testPrefixesDoNotMatchHostApplicationNames(): void
    {
        foreach (['users', 'maatify_persistence_test', 'maa_other_package_items', 'host_maa_example_items'] as $name) {
            self::assertFalse($this->isCovered($name), $name);
        }
    }

    private function isCovered(string $name): bool
    {
        foreach ($this->prefixes() as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<non-empty-string>
     */
    private function prefixes(): array
    {
        /** @var list<non-empty-string> $prefixes */
        $prefixes = require dirname(__DIR__, 3) . '/scripts/mysql-package-objects.php';

        return $prefixes;
    }
}

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
 * and Consumer harness use in PHP source and SQL fixtures must be covered by
 * it, so a new family of statically named test objects cannot slip past the
 * residue check unnoticed. Names assembled at runtime from other parts are not
 * detected by this scan.
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
        $sqlFiles = 0;

        foreach (['tests', 'examples'] as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getPathname() === $self) {
                    continue;
                }

                if (!in_array($file->getExtension(), ['php', 'sql'], true)) {
                    continue;
                }

                if ($file->getExtension() === 'sql') {
                    ++$sqlFiles;
                }

                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);

                foreach ($this->uncoveredNames($source) as $name) {
                    $uncovered[$name] = $file->getPathname();
                }

                $found += preg_match_all('/\bmaa_[a-z0-9_]+/', $source);
            }
        }

        self::assertGreaterThan(0, $found, 'the scan must find the package test object names');
        self::assertGreaterThan(0, $sqlFiles, 'the scan must include the SQL fixtures that create tables');
        self::assertSame([], $uncovered, 'object names outside the residue check prefixes');
    }

    public function testScanFlagsAFixtureNameOutsideTheRegisteredPrefixes(): void
    {
        $ddl = "CREATE TABLE `maa_newfamily_items` (\n  `id` INT NOT NULL\n);\n"
            . "CREATE TABLE `maa_persistence_test_covered_items` (`id` INT NOT NULL);\n";

        self::assertSame(['maa_newfamily_items'], $this->uncoveredNames($ddl));
    }

    public function testPrefixesDoNotMatchHostApplicationNames(): void
    {
        foreach (['users', 'maatify_persistence_test', 'maa_other_package_items', 'host_maa_example_items'] as $name) {
            self::assertFalse($this->isCovered($name), $name);
        }
    }

    /**
     * @return list<string>
     */
    private function uncoveredNames(string $source): array
    {
        preg_match_all('/\bmaa_[a-z0-9_]+/', $source, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $name): bool => !$this->isCovered($name),
        )));
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

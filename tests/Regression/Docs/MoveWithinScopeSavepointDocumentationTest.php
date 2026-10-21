<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Pins the wording of the Issue #55 behavior change: moveWithinScope() undoes
 * its own mutation window with an operation-local savepoint in a caller-owned
 * transaction, and the retired "no savepoint / partial shifts remain"
 * statements are gone from the active documentation. Structure and wording
 * only; the behavior is proven by the MySQL suites.
 */
final class MoveWithinScopeSavepointDocumentationTest extends TestCase
{
    private const ADR = 'docs/adr/0005-move-within-scope-lazy-operation-savepoint.md';

    private const ACTIVE_DOCS = [
        'README.md',
        'PERSISTENCE_PACKAGE_REFERENCE.md',
        'docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md',
        'CONTRIBUTING.md',
        'src/Pdo/Ordering/ScopedOrderingManager.php',
        'examples/move-within-scope/01_move_after_older_snapshot.php',
    ];

    public function testRetiredPartialStateStatementsAreGone(): void
    {
        foreach (self::ACTIVE_DOCS as $file) {
            $contents = $this->flatten($this->read($file));

            foreach (
                [
                    'creates no savepoint',
                    'create a savepoint, so it does not undo',
                    'does **not** undo its own partial work',
                    'does not undo its own partial work',
                    'no savepoint-rollback guarantee',
                    'rows already shifted stay',
                    'Rows it already shifted stay',
                ] as $retired
            ) {
                self::assertStringNotContainsString($retired, $contents, $file . ' still says: ' . $retired);
            }
        }
    }

    public function testActiveDocsDescribeTheOperationLocalSavepoint(): void
    {
        foreach (
            [
                'README.md',
                'PERSISTENCE_PACKAGE_REFERENCE.md',
                'docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md',
                'src/Pdo/Ordering/ScopedOrderingManager.php',
            ] as $file
        ) {
            $contents = $this->flatten($this->read($file));

            self::assertStringContainsString('operation-local savepoint', $contents, $file);
            self::assertStringContainsString('ADR 0005', $contents, $file);
            self::assertStringContainsString('1213', $contents, $file);
        }
    }

    public function testUserFacingGuidanceDoesNotPromiseCleanUndoForEveryException(): void
    {
        foreach (['README.md', 'docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md'] as $file) {
            $contents = $this->flatten($this->read($file));

            self::assertStringContainsString('not proof', $contents, $file);
            self::assertStringContainsString('stay', $contents, $file);
            self::assertStringNotContainsString('the move has undone its own shifts (Unreleased, Issue #55): your earlier work', $contents, $file);
        }

        self::assertStringContainsString('is not proof of a clean, still-live transaction', $this->flatten($this->read('CHANGELOG.md')));
    }

    public function testAdrRecordsTheDecisionTheNotRescuableListAndTheGuardLimit(): void
    {
        $adr = $this->flatten($this->read(self::ADR));

        foreach (
            [
                'Accepted',
                'Unreleased',
                'after** the scope lock',
                'published failure-path behavior change',
                '`1213`',
                '`1020`',
                '`innodb_rollback_on_timeout=ON`',
                'a proof of transaction identity',
                'MariaDB 11.4.7',
                'Release target and version are TBD by the Owner',
            ] as $needle
        ) {
            self::assertStringContainsString($needle, $adr);
        }

        self::assertStringContainsString('0005-move-within-scope-lazy-operation-savepoint.md', $this->read('docs/adr/README.md'));
    }

    public function testChangelogRecordsTheBehaviorChangeUnderUnreleased(): void
    {
        $changelog = $this->read('CHANGELOG.md');
        $unreleased = substr($changelog, (int) strpos($changelog, '## [Unreleased]'), (int) strpos($changelog, '## [1.4.0]') - (int) strpos($changelog, '## [Unreleased]'));
        $flat = $this->flatten($unreleased);

        self::assertStringContainsString('Issue #55', $flat);
        self::assertStringContainsString('Behavior change on failure paths', $flat);
        self::assertStringContainsString('`SAVEPOINT`, `RELEASE SAVEPOINT`', $flat);
        self::assertStringContainsString('TBD by the Owner', $flat);
    }

    private function flatten(string $text): string
    {
        $flat = preg_replace('/\s*\n(?:\s*(?:>|\*|\/\*\*|\*\/)\s*)?\s*/', ' ', $text);
        self::assertIsString($flat);

        return $flat;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);

        return $contents;
    }
}

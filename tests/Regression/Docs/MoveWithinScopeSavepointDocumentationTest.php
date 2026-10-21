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
        [$heading, $section] = $this->issue55ChangelogSection();
        $flat = $this->flatten($section);

        self::assertStringContainsString('Issue #55', $flat);
        self::assertStringContainsString('Behavior change on failure paths', $flat);
        self::assertStringContainsString('`SAVEPOINT`, `RELEASE SAVEPOINT`', $flat);

        // Version-transition rule: while the entry is under [Unreleased] it must say the release is the Owner's call;
        // once release work moves it under a version heading, that heading must be a real `## [x.y.z] - date` one.
        if ($heading === '[Unreleased]') {
            self::assertStringContainsString('TBD by the Owner', $flat);
        } else {
            self::assertMatchesRegularExpression('/^\[\d+\.\d+\.\d+\] - \d{4}-\d{2}-\d{2}$/', $heading);
        }
    }

    public function testChangelogDoesNotClaimUnchangedExceptionTypesAndNamesTheNewThrowPossibility(): void
    {
        $flat = $this->flatten($this->issue55ChangelogSection()[1]);

        self::assertStringNotContainsString('return values, exception types,', $flat);
        self::assertStringContainsString('can now also throw `TransactionExecutionException`', $flat);
        self::assertStringContainsString('an existing public class of `v1.4.0`', $flat);
        self::assertStringContainsString('a `false` result is still returned as `false` when the undo succeeds', $flat);
    }

    public function testStatementCountClaimsAreLocallyQualifiedAsSuccessPathWithCleanupOnlyAfterTheSavepoint(): void
    {
        foreach (
            [
                'README.md',
                'PERSISTENCE_PACKAGE_REFERENCE.md',
                'docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md',
                'docs/adr/0005-move-within-scope-lazy-operation-savepoint.md',
                'CHANGELOG.md',
            ] as $file
        ) {
            $contents = $this->flatten($this->read($file));
            $claims = preg_match_all(
                '/adds (?:exactly )?two\b|adds exactly `SAVEPOINT`|plus two extra control statements/',
                $contents,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            self::assertGreaterThan(0, $claims, $file . ' no longer states the statement count.');

            foreach ($matches[0] as [, $offset]) {
                $window = substr($contents, max(0, $offset - 250), 900);

                self::assertMatchesRegularExpression('/success path|that succeeds/', $window, $file . ': count not tied to the success path.');
                self::assertMatchesRegularExpression('/savepoint was established|savepointed window/', $window, $file . ': cleanup not tied to a failure after the savepoint.');
                self::assertMatchesRegularExpression('/ROLLBACK TO/', $window, $file . ': failure-path cleanup statements missing next to the count.');
            }

            self::assertStringNotContainsString('a failed path also runs', $contents, $file);
            self::assertStringNotContainsString('failed paths also run', $contents, $file);
        }
    }

    public function testReadmeComparisonSeparatesTheThrowingPathFromTheFalsePath(): void
    {
        $readme = $this->flatten($this->read('README.md'));

        self::assertStringContainsString('Throwing path:', $readme);
        self::assertStringContainsString('`false` path:', $readme);
        self::assertStringNotContainsString('the original exception is still the one thrown', $readme);
        self::assertStringNotContainsString('Cannot throw `TransactionExecutionException`', $readme);
        self::assertStringContainsString('If shifts had already been executed and the caller\'s transaction survives', $readme);
    }

    public function testReadmeRollbackOwnershipBulletSeparatesThrownFailuresFromTheFalseReturn(): void
    {
        $readme = $this->read('README.md');
        $start = (int) strpos($readme, '* **`moveWithinScope()` undoes its own mutation window');
        $end = (int) strpos($readme, "\n\n", $start);
        $bullet = $this->flatten(substr($readme, $start, $end - $start));

        self::assertNotSame(0, $start);
        self::assertStringNotContainsString('(or `false` after the shifts)', $bullet);
        self::assertStringNotContainsString('the original exception is rethrown unchanged. If that cleanup itself fails, the original exception still wins', $bullet);

        $throws = strpos($bullet, 'the range shift or the target write **throws**');
        $noRow = strpos($bullet, '**changes no row** (no exception exists)');

        self::assertIsInt($throws, 'The thrown-failure outcome is missing.');
        self::assertIsInt($noRow, 'The false-return outcome is missing.');
        self::assertGreaterThan($throws, $noRow);

        // "original exception" may only be used for the thrown outcome, i.e. before the false-return outcome.
        self::assertSame(false, strpos(substr($bullet, $noRow), 'original exception'), 'The false path must not mention an original exception.');
        self::assertStringContainsString('that failure is thrown instead of `false`', substr($bullet, $noRow));
    }

    public function testChangelogEntryOpeningDoesNotGiveTheFalsePathAnOriginalException(): void
    {
        $entry = $this->flatten($this->issue55ChangelogSection()[1]);

        self::assertStringNotContainsString('or the target write changes no row (`false`), it rolls back', $entry);
        self::assertStringContainsString('when the target write changes no row, there is no exception', $entry);

        $noException = (int) strpos($entry, 'there is no exception');
        self::assertSame(false, strpos(substr($entry, $noException, 200), 'original exception'));
    }

    public function testChangelogDoesNotPresentSupersededOrGloballyUnchangedCallerOwnedClaimsAsCurrent(): void
    {
        $unreleased = $this->flatten($this->unreleasedChangelog());

        self::assertStringNotContainsString('(partial work remains until the caller rolls back; no savepoint guarantee in the ordering API)', $unreleased);
        self::assertStringContainsString('at that interim documentation state', $unreleased);
        self::assertStringContainsString('supersedes this for `moveWithinScope()`', $unreleased);
        self::assertStringNotContainsString('`moveWithinScope()` keeps its behavior', $unreleased);
        self::assertStringContainsString('This runner-generation change does not alter `ScopedOrderingManager::moveWithinScope()`', $unreleased);
    }

    public function testReferenceAndReadmeSummariesDoNotGiveTheFalseReturnAnOriginalException(): void
    {
        self::assertStringContainsString('a `false` result has none and is returned only if the undo succeeded', $this->flatten($this->read('PERSISTENCE_PACKAGE_REFERENCE.md')));
        self::assertStringContainsString('(no exception exists; `false` is returned only if the undo succeeded)', $this->flatten($this->read('README.md')));
        self::assertStringNotContainsString('(original exception kept; an undo failure is thrown rather than `false`)', $this->read('PERSISTENCE_PACKAGE_REFERENCE.md'));
    }

    public function testReferenceSeparatesUnreleasedApiFromTheUnreleasedDeprecationOfReleasedClasses(): void
    {
        $head = $this->flatten((string) substr($this->read('PERSISTENCE_PACKAGE_REFERENCE.md'), 0, 5000));

        self::assertStringContainsString('Unreleased status change of released classes', $head);
        self::assertStringContainsString('Both classes are in `v1.4.0` and not deprecated there', $head);
        self::assertStringNotContainsString('`InvalidTransactionConfigurationException`, and the deprecation of', $head);
    }

    public function testReadmeAndReferenceListTheCallerOwnedBehaviorChangeAsUnreleased(): void
    {
        foreach (['README.md', 'PERSISTENCE_PACKAGE_REFERENCE.md'] as $file) {
            $head = $this->flatten((string) substr($this->read($file), 0, 9000));

            self::assertStringContainsString('Issue #55', $head, $file);
            self::assertStringContainsString('TransactionExecutionException', $head, $file);
        }
    }

    public function testUnreleasedChangelogSectionsFollowKeepAChangelogOrder(): void
    {
        $order = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

        $unreleased = $this->unreleasedChangelog();

        if (trim($unreleased) === '') {
            self::markTestSkipped('[Unreleased] is empty after a release cut; nothing to order.');
        }

        preg_match_all('/^### (.+)$/m', $unreleased, $matches);
        $headings = $matches[1];

        self::assertNotSame([], $headings, '[Unreleased] has content but no Keep a Changelog headings.');

        $positions = [];

        foreach ($headings as $heading) {
            $position = array_search($heading, $order, true);
            self::assertIsInt($position, 'Unknown changelog heading in [Unreleased]: ' . $heading);
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);

        self::assertSame($sorted, $positions, 'Unreleased sections: ' . implode(', ', $headings));
        self::assertSame(array_unique($headings), $headings);
    }

    public function testChangelogSectionSlicingStopsAtTheNextVersionHeading(): void
    {
        $synthetic = "# Changelog\n\n## [Unreleased]\n\n### Added\n* a\n\n## [9.9.9] - 2099-01-01\n\n### Fixed\n* b\n\n## [1.4.0] - 2026-09-18\n\n### Added\n* c\n";
        $sections = $this->changelogSections($synthetic);

        self::assertSame(['[Unreleased]', '[9.9.9] - 2099-01-01', '[1.4.0] - 2026-09-18'], array_keys($sections));
        self::assertStringContainsString('* a', $sections['[Unreleased]']);
        self::assertStringNotContainsString('* b', $sections['[Unreleased]']);
        self::assertStringNotContainsString('### Fixed', $sections['[Unreleased]']);
    }

    /** @return array<string, string> heading (without the leading `## `) => body, in file order */
    private function changelogSections(string $changelog): array
    {
        $parts = preg_split('/^## (\[.+)$/m', $changelog, -1, PREG_SPLIT_DELIM_CAPTURE);
        self::assertIsArray($parts);

        $sections = [];

        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $sections[trim($parts[$i])] = $parts[$i + 1];
        }

        return $sections;
    }

    private function unreleasedChangelog(): string
    {
        $sections = $this->changelogSections($this->read('CHANGELOG.md'));

        self::assertSame('[Unreleased]', array_key_first($sections), '[Unreleased] must stay the first changelog section.');

        return $sections['[Unreleased]'];
    }

    /** @return array{string, string} heading of the one section holding the Issue #55 entry, and that entry bullet alone */
    private function issue55ChangelogSection(): array
    {
        $holders = [];

        foreach ($this->changelogSections($this->read('CHANGELOG.md')) as $heading => $body) {
            if (str_contains($body, 'Behavior change on failure paths (Issue #55')) {
                $holders[$heading] = $body;
            }
        }

        self::assertCount(1, $holders, 'The Issue #55 entry must live in exactly one changelog section.');
        $heading = (string) array_key_first($holders);

        $body = $holders[$heading];
        $start = (int) strpos($body, '* **Behavior change on failure paths (Issue #55');
        $end = preg_match('/\n(?:\* |### )/', $body, $next, PREG_OFFSET_CAPTURE, $start + 1) === 1 ? $next[0][1] : strlen($body);

        return [$heading, substr($body, $start, $end - $start)];
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

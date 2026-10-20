<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the active documentation on the deadlock-retry runner generation
 * (Issue #32, ADR 0004): current usage shows only the new classes, the
 * deprecated runners keep a compatibility record, the replay contract and its
 * idempotency warning are stated, and every runnable example is linked.
 */
final class DeadlockRetryDocumentationTest extends TestCase
{
    private const ADR = 'docs/adr/0004-deadlock-retry-transaction-runner-generation.md';

    /** Owner approval of the accepted replay limit (Issue #32, option 1). */
    private const OWNER_APPROVAL = 'https://github.com/Maatify/persistence/issues/32#issuecomment-6296393312';

    /** @return array<string, array{string}> */
    public static function activeUsageDocuments(): array
    {
        $documents = [
            'README.md' => ['README.md'],
            'next-position guide' => ['docs/guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md'],
            'move-within-scope guide' => ['docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md'],
        ];

        foreach (glob(dirname(__DIR__, 3) . '/examples/*/*.php') ?: [] as $example) {
            $relative = substr($example, strlen(dirname(__DIR__, 3)) + 1);
            $documents[$relative] = [$relative];
        }

        return $documents;
    }

    #[DataProvider('activeUsageDocuments')]
    public function testActiveUsageInstantiatesOnlyTheNewRunnerGeneration(string $relativePath): void
    {
        $contents = $this->read($relativePath);

        self::assertDoesNotMatchRegularExpression('/new\s+PdoTransactionRunner\s*\(/', $contents);
        self::assertDoesNotMatchRegularExpression('/new\s+PdoSavepointTransactionRunner\s*\(/', $contents);
        self::assertDoesNotMatchRegularExpression('/use\s+Maatify\\\\Persistence\\\\Pdo\\\\Transaction\\\\Pdo(Savepoint)?TransactionRunner;/', $contents);
    }

    public function testReadmeAndReferenceDocumentBothNewRunnersAndTheReplayContract(): void
    {
        foreach (['README.md', 'PERSISTENCE_PACKAGE_REFERENCE.md', 'docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md'] as $file) {
            $contents = $this->flattened($file);

            self::assertStringContainsString('PdoDeadlockRetryTransactionRunner', $contents, $file);
            self::assertStringContainsString('PdoDeadlockRetrySavepointTransactionRunner', $contents, $file);
            self::assertStringContainsString('maxDeadlockRetries', $contents, $file);
            self::assertStringContainsString('`1213`', $contents, $file);
            self::assertStringContainsString('`40001`', $contents, $file);
            self::assertStringContainsString('whole callback', $contents, $file);
        }

        $reference = $this->flattened('PERSISTENCE_PACKAGE_REFERENCE.md');
        self::assertStringContainsString('public function __construct(\PDO $pdo, int $maxDeadlockRetries = 0)', $reference);
        self::assertStringContainsString('InvalidTransactionConfigurationException', $reference);
        self::assertStringContainsString('## Compatibility: Deprecated Transaction Runners', $this->read('PERSISTENCE_PACKAGE_REFERENCE.md'));
        self::assertStringContainsString('Unreleased, not part of the stable `v1.4.0`', $reference);
    }

    public function testFailClosedControlIsDocumented(): void
    {
        $reference = $this->flattened('PERSISTENCE_PACKAGE_REFERENCE.md');
        self::assertStringContainsString('**Non-throwing control failures (fail-closed)**', $reference);
        self::assertStringContainsString('the end cannot be attributed to the deadlock, and nothing is replayed', $reference);
        self::assertStringContainsString('raises `TransactionExecutionException`', $this->flattened('README.md'));
    }

    /** @return array<string, array{string}> */
    public static function ownerAcceptedLimitDocuments(): array
    {
        return [
            'README' => ['README.md'],
            'Package Reference' => ['PERSISTENCE_PACKAGE_REFERENCE.md'],
            'architecture' => ['docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md'],
            'ADR 0004' => [self::ADR],
        ];
    }

    /**
     * Owner-accepted limit (ADR 0004, review F44-02R): the callback
     * transaction-ownership precondition stays explicit, including DDL /
     * implicit commits, and no document promotes the driver state or the probe
     * into a guarantee of transaction identity.
     */
    #[DataProvider('ownerAcceptedLimitDocuments')]
    public function testOwnerAcceptedReplayLimitStaysExplicitAndIsNeverPromotedToAGuarantee(string $relativePath): void
    {
        $contents = $this->flattened($relativePath);

        self::assertStringContainsString('mandatory precondition of replay', $contents);
        self::assertStringContainsString('no transaction-identity guarantee', $contents);
        self::assertStringContainsString('DDL', $contents);
        self::assertMatchesRegularExpression('/implicit(ly)? commit/', $contents);
        self::assertStringContainsString('another transaction', $contents);

        foreach (['server is proven', 'proven to have ended', 'really ended', 'proves the server ended', 'proves that the deadlock', 'guarantees transaction identity', 'guarantee of transaction identity'] as $overclaim) {
            self::assertStringNotContainsString($overclaim, $contents, $relativePath . ' must not claim: ' . $overclaim);
        }
    }

    public function testAdrRecordsTheOwnerAcceptedLimitInsteadOfAnOpenDecision(): void
    {
        $adr = $this->flattened(self::ADR);

        self::assertStringContainsString('## Accepted limitation: callback transaction-ownership precondition', $adr);
        self::assertStringContainsString('Owner-accepted limitation', $adr);
        self::assertStringNotContainsString('Open Owner decision', $adr);
        self::assertStringNotContainsString('open Owner decision', $this->flattened('PERSISTENCE_PACKAGE_REFERENCE.md'));
        self::assertStringNotContainsString('open Owner decision', $this->flattened('docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md'));
        self::assertStringContainsString('[Owner approval](' . self::OWNER_APPROVAL . ')', $adr);
        self::assertStringNotContainsString('__OWNER_APPROVAL', $adr);
        foreach (['PERSISTENCE_PACKAGE_REFERENCE.md', 'docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md'] as $file) {
            self::assertStringContainsString(self::OWNER_APPROVAL, $this->read($file), $file);
        }
    }

    public function testDeprecationIsRecordedWithoutRewritingHistory(): void
    {
        $readme = $this->flattened('README.md');
        self::assertStringContainsString('`PdoTransactionRunner` and `PdoSavepointTransactionRunner` are deprecated', $readme);

        $changelog = $this->read('CHANGELOG.md');
        $unreleased = substr($changelog, 0, (int) strpos($changelog, '## [1.4.0]'));
        self::assertStringContainsString('### Deprecated', $unreleased);
        self::assertStringContainsString('PdoDeadlockRetryTransactionRunner', $unreleased);
        self::assertStringContainsString(
            'Transaction savepoint orchestration capability (`SavepointTransactionRunnerInterface`, `PdoSavepointTransactionRunner`)',
            $changelog,
            'The released v1.4.0 entry stays unchanged.',
        );

        self::assertFileExists($this->path(self::ADR));
        self::assertStringContainsString('0004-deadlock-retry-transaction-runner-generation.md', $this->read('docs/adr/0003-transaction-savepoint-orchestration.md'));
        self::assertStringContainsString(
            'Both `PdoTransactionRunner` and `PdoSavepointTransactionRunner` remain public, supported, and are not deprecated.',
            $this->read('docs/adr/0003-transaction-savepoint-orchestration.md'),
            'ADR 0003 keeps its original text as the historical record.',
        );
        self::assertStringContainsString('0004-deadlock-retry-transaction-runner-generation.md', $this->read('docs/adr/README.md'));
    }

    public function testEveryDeadlockRetryExampleIsLinkedFromTheReadme(): void
    {
        $scripts = glob($this->path('examples/deadlock-retry') . '/[0-9][0-9]_*.php');
        self::assertIsArray($scripts);
        self::assertNotSame([], $scripts);

        $readme = $this->read('README.md');
        foreach ($scripts as $script) {
            self::assertStringContainsString('examples/deadlock-retry/' . basename($script), $readme);
        }
    }

    private function flattened(string $relativePath): string
    {
        // Markdown wraps lines; compare prose with line breaks, indentation and quote markers collapsed.
        return (string) preg_replace('/\s*\n(?:\s*>)?\s*/', ' ', $this->read($relativePath));
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->path($relativePath));
        self::assertIsString($contents, $relativePath);

        return $contents;
    }

    private function path(string $relativePath): string
    {
        return dirname(__DIR__, 3) . '/' . $relativePath;
    }
}

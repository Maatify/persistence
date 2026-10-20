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

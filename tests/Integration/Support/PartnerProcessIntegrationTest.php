<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Support;

use Maatify\Persistence\Tests\Support\MySql\DeadlockRetryIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\PartnerProcess;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Harness reliability (Issue #68): the deadlock partner's bounded read and
 * shutdown, exercised with a real partner process that is frozen with SIGSTOP
 * before it can report readiness. A hung partner must fail fast with
 * diagnostics, and must never leave a child process or open pipe behind.
 */
final class PartnerProcessIntegrationTest extends DeadlockRetryIntegrationTestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The partner harness relies on POSIX pipes and signals.');
        }

        parent::setUp();
    }

    public function testReadFromAFrozenPartnerFailsWithinTheDeadlineAndStopReapsTheChild(): void
    {
        $partner = PartnerProcess::deadlockPartner();
        $stopStartedAt = 0;

        try {
            self::signal($partner, 'STOP');
            $startedAt = hrtime(true);

            try {
                $partner->readLine(1.0);
                self::fail('A frozen partner cannot report readiness.');
            } catch (AssertionFailedError $failure) {
                $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
                self::assertStringContainsString('produced no complete output line within 1.0 s', $failure->getMessage());
                self::assertStringContainsString('pid ' . $partner->pid(), $failure->getMessage());
                self::assertGreaterThanOrEqual(1.0, $elapsed);
                self::assertLessThan(5.0, $elapsed, 'The deadline must bound the wait.');
            }

            self::assertTrue($partner->isRunning(), 'The frozen child is still alive before stop().');
        } finally {
            $stopStartedAt = hrtime(true);
            $partner->stop();
        }

        self::assertLessThan(10.0, (hrtime(true) - $stopStartedAt) / 1_000_000_000, 'stop() must be bounded.');
        self::assertFalse($partner->isRunning());
        self::assertFalse(self::processExists($partner->pid()), 'The frozen child must be killed and reaped.');
        $partner->stop();
    }

    public function testNormalPartnerReportsReadyAndIsStoppedCleanly(): void
    {
        $partner = PartnerProcess::deadlockPartner();

        try {
            self::assertStringStartsWith('READY ', $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS));
        } finally {
            $partner->stop();
        }

        self::assertFalse(self::processExists($partner->pid()));
    }

    private static function signal(PartnerProcess $partner, string $signal): void
    {
        $pipes = [];
        $process = proc_open(['kill', '-' . $signal, (string) $partner->pid()], [], $pipes);
        self::assertIsResource($process, 'Unable to run kill.');
        self::assertSame(0, proc_close($process), 'kill -' . $signal . ' failed.');
    }

    private static function processExists(int $pid): bool
    {
        $pipes = [];
        $process = proc_open(
            ['kill', '-0', (string) $pid],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return proc_close($process) === 0;
    }
}

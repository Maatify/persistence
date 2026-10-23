<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Support;

use Maatify\Persistence\Tests\Support\MySql\DeadlockRetryIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\PartnerProcess;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Harness reliability (Issue #68): the deadlock partner's bounded read and
 * shutdown. The negative controls start the real partner script behind a shell
 * that stops itself (SIGSTOP) before exec'ing it, so the partner provably cannot
 * write READY before the freeze, whatever the scheduling. A hung partner must
 * fail fast with diagnostics and must never leave a child process or pipe behind.
 */
final class PartnerProcessIntegrationTest extends DeadlockRetryIntegrationTestCase
{
    private const STARTUP_BARRIER_SECONDS = 10.0;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The partner harness relies on POSIX pipes and signals.');
        }

        parent::setUp();
    }

    public function testReadFromAFrozenPartnerFailsWithinTheDeadlineAndStopReapsTheChild(): void
    {
        $partner = self::startPartnerFrozenBeforeExec();
        $stopStartedAt = 0;

        try {
            $startedAt = hrtime(true);

            try {
                $partner->readLine(1.0);
                self::fail('A partner frozen before exec cannot report readiness.');
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

    public function testStopFailsClosedWithoutAnUnboundedWaitWhenTheChildCannotBeTerminated(): void
    {
        $gate = new \ArrayObject(['deliver' => false]);
        $partner = self::startPartnerFrozenBeforeExec(
            static fn ($process, int $signal): bool => $gate['deliver'] === true && proc_terminate($process, $signal),
        );

        try {
            $startedAt = hrtime(true);

            try {
                $partner->stop();
                self::fail('stop() must not report success for a child it could not terminate.');
            } catch (AssertionFailedError $failure) {
                self::assertStringContainsString('still running', $failure->getMessage());
                self::assertStringContainsString('SIGTERM delivery failed, SIGKILL delivery failed', $failure->getMessage());
                self::assertStringContainsString('proc_close() is not called', $failure->getMessage());
            }

            self::assertLessThan(10.0, (hrtime(true) - $startedAt) / 1_000_000_000, 'The failed termination must be bounded.');
            self::assertTrue(self::processExists($partner->pid()), 'The child is leaked by design, not reaped.');
        } finally {
            $gate['deliver'] = true;
            $partner->stop();
        }

        self::assertFalse(self::processExists($partner->pid()), 'A later stop() terminates and reaps the child.');
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

    /**
     * The real partner script behind `sh -c 'kill -STOP $$; exec ...'`: the shell
     * stops itself before exec'ing PHP, so nothing can be written until a SIGCONT
     * that never comes. The startup barrier waits for the kernel to report it
     * stopped; there is no sleep and no dependence on scheduling speed.
     *
     * @param (\Closure(resource, int): bool)|null $signaler
     */
    private static function startPartnerFrozenBeforeExec(?\Closure $signaler = null): PartnerProcess
    {
        $partner = PartnerProcess::start(
            ['sh', '-c', 'kill -STOP $$; exec "$0" "$@"', PHP_BINARY, __DIR__ . '/../../Support/MySql/DeadlockPartner.php', '1', '2'],
            $signaler,
        );

        try {
            $partner->awaitStopped(self::STARTUP_BARRIER_SECONDS);
        } catch (AssertionFailedError $failure) {
            $partner->stop();

            throw $failure;
        }

        return $partner;
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

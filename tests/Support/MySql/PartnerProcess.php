<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use Closure;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Test-only child process (the other side of a real deadlock) with a bounded
 * line protocol on its stdout and a bounded, always-terminating shutdown.
 *
 * PHP's stream_set_timeout() is not honoured by a blocking fgets() on a
 * proc_open() pipe, so a stuck child used to hang the whole run. Reads here
 * wait on stream_select() against a monotonic deadline and fail with
 * diagnostics instead. stop() is bounded: it never waits on a child that
 * ignores or cannot handle SIGTERM (for example a stopped one), it escalates to
 * SIGKILL, and it only reaps with proc_close() a child it has seen exit. If the
 * child is still running after SIGKILL and the grace period, stop() closes the
 * pipes, does NOT call proc_close() (which would block without bound) and
 * throws; the child is then leaked (the resource is kept, so a later stop()
 * can retry) and PHP may wait for it when the process shuts down. Requires POSIX
 * pipes and a `kill` binary (Linux, macOS).
 */
final class PartnerProcess
{
    private const TERMINATE_GRACE_SECONDS = 2.0;
    private const REAP_POLL_MICROSECONDS = 10_000;
    private const DIAGNOSTIC_MAX_BYTES = 2000;
    private const SIGTERM = 15;
    private const SIGKILL = 9;

    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $stdin;

    /** @var resource|null */
    private $stdout;

    /** @var resource|null */
    private $stderr;

    private string $buffer = '';

    /** Latched the first time any status query observed the child stopped (the event is reported only once). */
    private bool $stopObserved = false;

    /** @var Closure(resource, int): bool */
    private Closure $signaler;

    private function __construct(private readonly int $pid, ?Closure $signaler)
    {
        $this->signaler = $signaler ?? self::sendSignal(...);
    }

    /** The deadlock partner script with the contended row ids 1 and 2. */
    public static function deadlockPartner(): self
    {
        return self::start([PHP_BINARY, __DIR__ . '/DeadlockPartner.php', '1', '2']);
    }

    /**
     * @param non-empty-list<string>                    $command
     * @param (Closure(resource, int): bool)|null       $signaler test seam replacing the signal delivery (proc_terminate with a
     *                                                            `kill` fallback), to exercise failed termination
     */
    public static function start(array $command, ?Closure $signaler = null): self
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        /** @var array<int, resource> $openedPipes */
        $openedPipes = $pipes;
        $stdin = $openedPipes[0] ?? null;
        $stdout = $openedPipes[1] ?? null;
        $stderr = $openedPipes[2] ?? null;

        if (!is_resource($process) || !is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            $partial = new self(is_resource($process) ? proc_get_status($process)['pid'] : 0, $signaler);
            $partial->process = is_resource($process) ? $process : null;
            $partial->stdin = is_resource($stdin) ? $stdin : null;
            $partial->stdout = is_resource($stdout) ? $stdout : null;
            $partial->stderr = is_resource($stderr) ? $stderr : null;
            $partial->stop();

            throw new AssertionFailedError('Unable to start the test partner process.');
        }

        $status = proc_get_status($process);
        $partner = new self($status['pid'], $signaler);
        $partner->stopObserved = $status['stopped'];
        $partner->process = $process;
        $partner->stdin = $stdin;
        $partner->stdout = $stdout;
        $partner->stderr = $stderr;
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        return $partner;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function writeLine(string $line): void
    {
        if (!is_resource($this->stdin) || fwrite($this->stdin, $line . "\n") === false || !fflush($this->stdin)) {
            throw new AssertionFailedError(sprintf(
                'Unable to write to test partner pid %d; %s',
                $this->pid,
                $this->diagnostics(),
            ));
        }
    }

    /**
     * Next complete stdout line, waiting at most $timeoutSeconds (monotonic).
     *
     * @throws AssertionFailedError on timeout or when the child closed stdout without a full line
     */
    public function readLine(float $timeoutSeconds): string
    {
        $startedAt = hrtime(true);
        $deadline = $startedAt + (int) ($timeoutSeconds * 1_000_000_000);

        while (true) {
            $newline = strpos($this->buffer, "\n");

            if ($newline !== false) {
                $line = substr($this->buffer, 0, $newline);
                $this->buffer = substr($this->buffer, $newline + 1);

                return trim($line);
            }

            if (!is_resource($this->stdout)) {
                throw new AssertionFailedError(sprintf('Test partner pid %d has no open stdout; %s', $this->pid, $this->diagnostics()));
            }

            $remaining = $deadline - hrtime(true);

            if ($remaining <= 0) {
                throw new AssertionFailedError(sprintf(
                    'Test partner pid %d produced no complete output line within %.1f s (waited %.1f s); %s',
                    $this->pid,
                    $timeoutSeconds,
                    (hrtime(true) - $startedAt) / 1_000_000_000,
                    $this->diagnostics(),
                ));
            }

            $read = [$this->stdout];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1_000));

            if ($ready === false) {
                throw new AssertionFailedError(sprintf('stream_select() failed on test partner pid %d; %s', $this->pid, $this->diagnostics()));
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($this->stdout, 8192);

            if ($chunk === '' || $chunk === false) {
                if (feof($this->stdout)) {
                    throw new AssertionFailedError(sprintf(
                        'Test partner pid %d closed stdout before a complete line (partial: "%s"); %s',
                        $this->pid,
                        $this->sanitize($this->buffer),
                        $this->diagnostics(),
                    ));
                }

                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    /**
     * Startup barrier for a child that stops itself (SIGSTOP) before it can write
     * anything: returns once the kernel has reported it stopped, within the
     * deadline. The kernel reports a stop only to the first status query that
     * sees it, so every query here goes through status(), which latches the
     * event, including the one start() makes to read the pid: a stop that
     * happened before start() returned is accepted, not lost. The latch records
     * that the child stopped at some point; it is not cleared by SIGCONT.
     */
    public function awaitStopped(float $timeoutSeconds): void
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

        while (is_resource($this->process)) {
            $status = $this->status();

            if ($this->stopObserved) {
                return;
            }

            if (!$status['running'] || hrtime(true) >= $deadline) {
                break;
            }

            usleep(self::REAP_POLL_MICROSECONDS);
        }

        throw new AssertionFailedError(sprintf('Test partner pid %d did not stop within %.1f s; %s', $this->pid, $timeoutSeconds, $this->diagnostics()));
    }

    public function isRunning(): bool
    {
        return is_resource($this->process) && $this->status()['running'];
    }

    /**
     * Closes stdin (the child ends on EOF), asks the child to terminate, escalates
     * to SIGKILL after a short grace period, reaps it and closes all pipes.
     * Idempotent. Total wait is bounded by two grace periods.
     *
     * @throws AssertionFailedError when the child is still running after SIGKILL and the grace period;
     *                              the pipes are closed, proc_close() is not called and the child is leaked
     */
    public function stop(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }

        $this->stdin = null;
        $failure = null;

        if (is_resource($this->process) && !$this->waitForExit(0.0)) {
            $termSent = $this->signal(self::SIGTERM);

            if (!$this->waitForExit(self::TERMINATE_GRACE_SECONDS)) {
                $killSent = $this->signal(self::SIGKILL);

                if (!$this->waitForExit(self::TERMINATE_GRACE_SECONDS)) {
                    $failure = sprintf(
                        'Test partner pid %d is still running %.1f s after SIGKILL (SIGTERM delivery %s, SIGKILL delivery %s); '
                        . 'proc_close() is not called because it would block, so the child is leaked and must be killed manually; %s',
                        $this->pid,
                        self::TERMINATE_GRACE_SECONDS,
                        $termSent ? 'ok' : 'failed',
                        $killSent ? 'ok' : 'failed',
                        $this->diagnostics(),
                    );
                }
            }
        }

        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->stdout = null;
        $this->stderr = null;

        if ($failure !== null) {
            throw new AssertionFailedError($failure);
        }

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->process = null;
    }

    private function signal(int $signal): bool
    {
        return is_resource($this->process) && ($this->signaler)($this->process, $signal);
    }

    /** @param resource $process */
    private static function sendSignal($process, int $signal): bool
    {
        if (proc_terminate($process, $signal)) {
            return true;
        }

        $pipes = [];
        $kill = proc_open(
            ['kill', '-' . $signal, (string) proc_get_status($process)['pid']],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        return is_resource($kill) && proc_close($kill) === 0;
    }

    /**
     * proc_get_status() of the open process, latching a reported stop.
     *
     * @return array{pid: int, running: bool, stopped: bool}
     */
    private function status(): array
    {
        $status = proc_get_status($this->process ?? throw new AssertionFailedError('The test partner process is closed.'));
        $this->stopObserved = $this->stopObserved || $status['stopped'];

        return ['pid' => $status['pid'], 'running' => $status['running'], 'stopped' => $status['stopped']];
    }

    /** @phpstan-impure */
    private function waitForExit(float $seconds): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

        while (is_resource($this->process) && $this->status()['running']) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(self::REAP_POLL_MICROSECONDS);
        }

        return true;
    }

    private function diagnostics(): string
    {
        $stderr = '';

        if (is_resource($this->stderr)) {
            $read = stream_get_contents($this->stderr, self::DIAGNOSTIC_MAX_BYTES);
            $stderr = $read === false ? '' : $read;
        }

        return sprintf(
            'running=%s, stderr: "%s"',
            $this->isRunning() ? 'yes' : 'no',
            $this->sanitize($stderr),
        );
    }

    private function sanitize(string $text): string
    {
        $clean = preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '';

        return substr(trim($clean), 0, self::DIAGNOSTIC_MAX_BYTES);
    }
}

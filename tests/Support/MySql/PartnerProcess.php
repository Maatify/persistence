<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Test-only child process (the other side of a real deadlock) with a bounded
 * line protocol on its stdout and a bounded, always-terminating shutdown.
 *
 * PHP's stream_set_timeout() is not honoured by a blocking fgets() on a
 * proc_open() pipe, so a stuck child used to hang the whole run. Reads here
 * wait on stream_select() against a monotonic deadline and fail with
 * diagnostics instead. stop() never waits on a child that ignores or cannot
 * handle SIGTERM (for example a stopped one): it escalates to SIGKILL, reaps the
 * child and closes every pipe. Requires POSIX pipes (Linux, macOS).
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

    private function __construct(private readonly int $pid)
    {
    }

    /** The deadlock partner script with the contended row ids 1 and 2. */
    public static function deadlockPartner(): self
    {
        return self::start([PHP_BINARY, __DIR__ . '/DeadlockPartner.php', '1', '2']);
    }

    /** @param non-empty-list<string> $command */
    public static function start(array $command): self
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
            foreach ([$stdin, $stdout, $stderr] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                proc_terminate($process, self::SIGKILL);
                proc_close($process);
            }

            throw new AssertionFailedError('Unable to start the test partner process.');
        }

        $status = proc_get_status($process);
        $partner = new self($status['pid']);
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

    public function isRunning(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    /**
     * Idempotent and bounded: closes stdin (the child ends on EOF), asks the
     * child to terminate, escalates to SIGKILL after a short grace period,
     * reaps it and closes all pipes.
     */
    public function stop(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }

        $this->stdin = null;

        if (is_resource($this->process)) {
            if (!$this->waitForExit(0.0)) {
                proc_terminate($this->process, self::SIGTERM);

                if (!$this->waitForExit(self::TERMINATE_GRACE_SECONDS)) {
                    proc_terminate($this->process, self::SIGKILL);
                    $this->waitForExit(self::TERMINATE_GRACE_SECONDS);
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

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->process = null;
    }

    private function waitForExit(float $seconds): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

        while (is_resource($this->process) && proc_get_status($this->process)['running']) {
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

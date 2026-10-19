<?php

/**
 * Issue #32 study harness: shared library (connection, scenario, retry policies, retry executor).
 *
 * STUDY-ONLY. Nothing here is part of the package runtime. The retry loop lives in this file,
 * OUTSIDE the library: it wraps the unmodified PdoSavepointTransactionRunner from src/.
 */

declare(strict_types=1);

namespace Maatify\Persistence\Study;

use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;
use PDO;
use PDOException;

const REPO_ROOT = __DIR__ . '/../../../..';

if (is_file(REPO_ROOT . '/vendor/autoload.php')) {
    require_once REPO_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Maatify\\Persistence\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = REPO_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

function envOr(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

/** @return array{dsn:string,user:string,pass:string} */
function connectionConfig(): array
{
    return [
        'dsn' => envOr('STUDY_MYSQL_DSN', 'mysql:host=127.0.0.1;port=33320;dbname=i32_study;charset=utf8mb4'),
        'user' => envOr('STUDY_MYSQL_USER', 'i32'),
        'pass' => envOr('STUDY_MYSQL_PASSWORD', 'i32_pass'),
    ];
}

/** Independent connection, explicit REPEATABLE READ, exceptions, native prepares (as the package does). */
function connect(array $cfg, bool $multiStatement = false): PDO
{
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => $multiStatement,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    return $pdo;
}

/**
 * The Issue #32 scenario: php-product ContentLifecycle::create() for a Product-owned Content, i.e.
 * "create the first Content (name) of a new Product". The SQL below is the SQL php-product runs
 * (ContentLifecycle::create -> PdoContentCommandRepository::findOwnerProductId,
 * PdoProductConfigurationRepository::lockSnapshot, PdoContentCommandRepository::create) at
 * Maatify/php-product@77d6aafbb48ff37e2ae07a24230b838848799ebd. For a brand-new Product the Option,
 * Option Value and Variant sets are empty, so the follow-up composition/label reads are skipped by
 * php-product itself (empty-id guards) and are therefore not issued here either.
 */
final class Scenario
{
    public const SQL_FIND_OWNER = 'SELECT `id` AS `product_id` FROM `maa_product_products` WHERE `id` = :id LIMIT 1';

    public const SQL_LOCK_PRODUCT = 'SELECT `id`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `maa_product_products` WHERE `id` = :id FOR UPDATE';

    public const SQL_LOCK_OPTIONS = 'SELECT `id`, `product_id`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `maa_product_options` '
        . 'FORCE INDEX (`uq_maa_product_options_product_code`) WHERE `product_id` = :product_id ORDER BY `id` ASC FOR UPDATE';

    public const SQL_LOCK_VALUES = 'SELECT ov.`id`, ov.`option_id`, ov.`status`, (ov.`deleted_at` IS NOT NULL) AS `deleted` '
        . 'FROM `maa_product_options` o FORCE INDEX (`uq_maa_product_options_product_code`) '
        . 'STRAIGHT_JOIN `maa_product_option_values` ov FORCE INDEX (`uq_maa_product_option_values_option_code`) '
        . 'ON ov.`option_id` = o.`id` WHERE o.`product_id` = :product_id ORDER BY ov.`id` ASC FOR UPDATE';

    public const SQL_LOCK_VARIANTS = 'SELECT `id`, `product_id`, `composition_fingerprint`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` '
        . 'FROM `maa_product_variants` FORCE INDEX (`idx_maa_product_variants_product_status_deleted_order`) '
        . 'WHERE `product_id` = :product_id ORDER BY `id` ASC FOR UPDATE';

    public const SQL_LOCK_NAME = 'SELECT 1 FROM `maa_product_product_contents` FORCE INDEX (`uq_maa_product_product_contents_identity`) '
        . 'WHERE `product_id` = :product_id AND `deleted_at` IS NULL '
        . 'AND `name` REGEXP \'[^[:space:]]\' LIMIT 1 FOR SHARE';

    public const SQL_INSERT_CONTENT = 'INSERT INTO `maa_product_product_contents` (`product_id`, `language_code`, `name`, `short_description`, `description`, `created_at`, `updated_at`, `deleted_at`) '
        . 'VALUES (:owner_id, :language_code, :name, :short_description, :description, :created_at, :updated_at, NULL)';

    /** Locking reads only (the "lockSnapshot" phase), in php-product order. */
    public static function lockSnapshot(PDO $pdo, int $productId): void
    {
        $steps = [
            [self::SQL_LOCK_PRODUCT, 'id'],
            [self::SQL_LOCK_OPTIONS, 'product_id'],
            [self::SQL_LOCK_VALUES, 'product_id'],
            [self::SQL_LOCK_VARIANTS, 'product_id'],
            [self::SQL_LOCK_NAME, 'product_id'],
        ];
        foreach ($steps as [$sql, $param]) {
            $statement = $pdo->prepare($sql);
            $statement->execute([$param => $productId]);
            $statement->fetchAll();
        }
    }

    /** The whole callback body of one operation. */
    public static function create(PDO $pdo, int $productId, string $name): void
    {
        $find = $pdo->prepare(self::SQL_FIND_OWNER);
        $find->execute(['id' => $productId]);
        $find->fetchAll();

        self::lockSnapshot($pdo, $productId);

        $now = date('Y-m-d H:i:s');
        $insert = $pdo->prepare(self::SQL_INSERT_CONTENT);
        $insert->execute([
            'owner_id' => $productId,
            'language_code' => null,
            'name' => $name,
            'short_description' => null,
            'description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

/** Retry policy evaluated by the harness. $maxRetries=3 means four attempts in total. */
final class Policy
{
    public function __construct(
        public readonly string $name,
        public readonly int $maxRetries,
        /** @var list<int> upper bound (µs) of the uniform jitter before retry #1, #2, ...; 0 = immediate */
        private readonly array $jitterUpperUs,
    ) {
    }

    public static function fromName(string $name, int $seed): self
    {
        mt_srand($seed);

        return match ($name) {
            'none' => new self('none', 0, []),
            'immediate' => new self('immediate', 3, [0, 0, 0]),
            'hybrid' => new self('hybrid', 3, [0, 10_000, 20_000]),
            default => throw new \InvalidArgumentException('Unknown policy ' . $name),
        };
    }

    /** Delay in µs to wait before retry number $retry (1-based). */
    public function delayBeforeRetryUs(int $retry): int
    {
        $upper = $this->jitterUpperUs[$retry - 1] ?? 0;

        return $upper === 0 ? 0 : mt_rand(0, $upper);
    }
}

/**
 * ACTIVE transaction of THIS connection as seen by the server (performance_schema; exact, unlike
 * information_schema.INNODB_TRX whose snapshot is cached for up to 100 ms). Empty = none open.
 */
function serverOpenTransactions(PDO $pdo): array
{
    $statement = $pdo->query("SELECT STATE, ISOLATION_LEVEL FROM performance_schema.events_transactions_current WHERE THREAD_ID = PS_CURRENT_THREAD_ID() AND STATE = 'ACTIVE'");

    return $statement === false ? [['unreadable' => true]] : $statement->fetchAll();
}

/**
 * ACTIVE transactions of all OTHER connections (exact; performance_schema). Used for the end-of-run check.
 */
function otherActiveTransactions(PDO $control): array
{
    return $control->query("SELECT THREAD_ID, STATE FROM performance_schema.events_transactions_current WHERE STATE = 'ACTIVE' AND THREAD_ID <> PS_CURRENT_THREAD_ID()")->fetchAll();
}

/** Hash of the harness sources; stored in every run summary so runs of different harness versions cannot be mixed. */
function harnessHash(): string
{
    $files = glob(__DIR__ . '/*.php') ?: [];
    sort($files);
    $ctx = hash_init('sha256');
    foreach ($files as $f) {
        hash_update($ctx, basename($f) . "\0" . (string) file_get_contents($f) . "\0");
    }

    return hash_final($ctx);
}

/**
 * The single retry classifier: an InnoDB deadlock is exactly driver error 1213 AND SQLSTATE 40001.
 * Anything else (1205, 1213 with another SQLSTATE, non-PDO throwables) is never retried.
 */
function isDeadlock(\Throwable $throwable): bool
{
    return $throwable instanceof PDOException
        && (int) ($throwable->errorInfo[1] ?? 0) === 1213
        && (string) ($throwable->errorInfo[0] ?? '') === '40001';
}

/**
 * The retry loop, free of any database access so that its decision branches can be tested deterministically
 * (selftest.php). Preconditions are fail-closed: if the connection is still inside a transaction before an attempt
 * or after a failure (ownership / cleanup uncertain), the operation ends as `invalid` and NO further attempt runs.
 *
 * Attempt record: [startUs, durationUs, errorCode (0 ok, -1 non-PDO), sleepAfterUs, sqlState].
 *
 * @param callable(int):void $attempt            runs one attempt (one runner->run call); throws on failure
 * @param callable():bool    $clientInTransaction  PDO::inTransaction()
 * @param callable():array   $serverOpen         server-side ACTIVE transactions of this connection ([] = none), or null to skip
 * @param callable(int):void $sleep              sleeps N microseconds
 * @param callable():int     $nowNs              monotonic nanoseconds
 * @return array{attempts:list<array>,flags:list<string>,outcome:string,other_error:?string,end_ns:int,start_ns:int}
 */
function executeWithPolicy(Policy $policy, callable $attempt, callable $clientInTransaction, ?callable $serverOpen, callable $sleep, callable $nowNs, int $t0Ns): array
{
    $attempts = [];
    $flags = [];
    $outcome = 'exhausted';
    $otherError = null;
    $opStartNs = $nowNs();
    $lastEndNs = $opStartNs;
    $total = $policy->maxRetries + 1;

    for ($n = 1; $n <= $total; ++$n) {
        if ($clientInTransaction()) {
            $flags[] = 'client_tx_open_before_attempt_' . $n;
            $outcome = 'invalid';
            break;
        }
        if ($serverOpen !== null && $n > 1) {
            $open = $serverOpen();
            if ($open !== []) {
                $flags[] = 'server_tx_open_before_retry_' . $n . ':' . json_encode($open);
                $outcome = 'invalid';
                break;
            }
        }

        $startNs = $nowNs();
        try {
            $attempt($n);
            $lastEndNs = $nowNs();
            $attempts[] = [intdiv($startNs - $t0Ns, 1000), intdiv($lastEndNs - $startNs, 1000), 0, 0, ''];
            $outcome = 'ok';
            break;
        } catch (\Throwable $throwable) {
            $lastEndNs = $nowNs();
            $pdo = $throwable instanceof PDOException;
            $code = $pdo ? (int) ($throwable->errorInfo[1] ?? 0) : -1;
            $state = $pdo ? (string) ($throwable->errorInfo[0] ?? '') : '';
            $deadlock = isDeadlock($throwable);
            $willRetry = $deadlock && $n < $total;
            $sleepUs = $willRetry ? $policy->delayBeforeRetryUs($n) : 0;
            $attempts[] = [intdiv($startNs - $t0Ns, 1000), intdiv($lastEndNs - $startNs, 1000), $code, $sleepUs, $state];

            if ($clientInTransaction()) {
                $flags[] = 'client_tx_open_after_failure_' . $n;
                $outcome = 'invalid';
                break;
            }
            if (!$deadlock) {
                $outcome = 'error';
                $otherError = ($pdo ? $code . ':' . $state : get_class($throwable)) . ':' . $throwable->getMessage();
                break;
            }
            if (!$willRetry) {
                $outcome = 'exhausted';
                break;
            }
            if ($sleepUs > 0) {
                $sleep($sleepUs);
                $lastEndNs = $nowNs();
            }
        }
    }

    return ['attempts' => $attempts, 'flags' => $flags, 'outcome' => $outcome, 'other_error' => $otherError, 'end_ns' => $lastEndNs, 'start_ns' => $opStartNs];
}

/**
 * Runs one operation against the real database: every attempt is one call to the unmodified runner, which owns
 * (begins/commits/rolls back) its transaction because the connection is asserted to be outside any transaction.
 *
 * @return array<string,mixed>
 */
function runOperation(
    PDO $pdo,
    PdoSavepointTransactionRunner $runner,
    Policy $policy,
    int $productId,
    int $opId,
    int $t0Ns,
    bool $verifyServerState,
): array {
    $callbackRuns = 0;
    $name = 'op-' . $opId;
    $result = executeWithPolicy(
        $policy,
        static function () use ($runner, $pdo, $productId, $name, &$callbackRuns): void {
            $runner->run(static function () use ($pdo, $productId, $name, &$callbackRuns): void {
                ++$callbackRuns;
                Scenario::create($pdo, $productId, $name);
            });
        },
        static fn(): bool => $pdo->inTransaction(),
        $verifyServerState ? static fn(): array => serverOpenTransactions($pdo) : null,
        static function (int $us): void {
            usleep($us);
        },
        static fn(): int => hrtime(true),
        $t0Ns,
    );

    return [
        'op' => $opId,
        'product' => $productId,
        'outcome' => $result['outcome'],
        'attempts' => $result['attempts'],
        'callback_runs' => $callbackRuns,
        'latency_us' => intdiv($result['end_ns'] - $result['start_ns'], 1000),
        'end_ns' => $result['end_ns'],
        'flags' => $result['flags'],
        'other_error' => $result['other_error'],
    ];
}

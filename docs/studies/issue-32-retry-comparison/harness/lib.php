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
 * Runs one operation under the given policy. The retry loop is entirely here; each attempt is one
 * call to the unmodified runner, which owns (begins/commits/rolls back) its transaction because the
 * connection is never inside a caller transaction (asserted before every attempt).
 *
 * Attempt record: [startUs, durationUs, errorCode, sleepAfterUs] (times relative to $t0Ns).
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
    $attempts = [];
    $flags = [];
    $callbackRuns = 0;
    $outcome = 'exhausted';
    $otherError = null;
    $opStartNs = hrtime(true);
    $lastEndNs = $opStartNs;
    $name = 'op-' . $opId;
    $total = $policy->maxRetries + 1;

    for ($attempt = 1; $attempt <= $total; ++$attempt) {
        if ($pdo->inTransaction()) {
            $flags[] = 'client_tx_open_before_attempt_' . $attempt;
        }
        if ($verifyServerState && $attempt > 1) {
            $open = serverOpenTransactions($pdo);
            if ($open !== []) {
                $flags[] = 'server_tx_open_before_retry_' . $attempt . ':' . json_encode($open);
            }
        }

        $startNs = hrtime(true);
        try {
            $runner->run(static function () use ($pdo, $productId, $name, &$callbackRuns): void {
                ++$callbackRuns;
                Scenario::create($pdo, $productId, $name);
            });
            $lastEndNs = hrtime(true);
            $attempts[] = [intdiv($startNs - $t0Ns, 1000), intdiv($lastEndNs - $startNs, 1000), 0, 0];
            $outcome = 'ok';
            break;
        } catch (PDOException $exception) {
            $lastEndNs = hrtime(true);
            $code = (int) ($exception->errorInfo[1] ?? 0);
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            if ($pdo->inTransaction()) {
                $flags[] = 'client_tx_open_after_failure_' . $attempt;
            }
            $sleepUs = 0;
            if ($code === 1213 && $sqlState === '40001' && $attempt < $total) {
                $sleepUs = $policy->delayBeforeRetryUs($attempt);
            }
            $attempts[] = [intdiv($startNs - $t0Ns, 1000), intdiv($lastEndNs - $startNs, 1000), $code, $sleepUs];

            if ($code !== 1213) {
                $outcome = 'error';
                $otherError = $code . ':' . $sqlState . ':' . $exception->getMessage();
                break;
            }
            if ($attempt === $total) {
                $outcome = 'exhausted';
                break;
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
                $lastEndNs = hrtime(true);
            }
        }
    }

    return [
        'op' => $opId,
        'product' => $productId,
        'outcome' => $outcome,
        'attempts' => $attempts,
        'callback_runs' => $callbackRuns,
        'latency_us' => intdiv($lastEndNs - $opStartNs, 1000),
        'end_ns' => $lastEndNs,
        'flags' => $flags,
        'other_error' => $otherError,
    ];
}

<?php

/**
 * Issue #32 study harness: one worker process = one independent PDO connection.
 * Protocol (JSON lines on stdin/stdout): config line -> "READY"; then "run" commands -> one result line each.
 */

declare(strict_types=1);

namespace Maatify\Persistence\Study;

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;

$config = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$pdo = connect($config['connection']);
$runner = new PdoSavepointTransactionRunner($pdo);
$policy = Policy::fromName($config['policy'], (int) $config['seed']);
$verify = (bool) $config['verify_server_state'];

fwrite(STDOUT, json_encode(['ready' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), 'isolation' => (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn()]) . "\n");
fflush(STDOUT);

while (($line = fgets(STDIN)) !== false) {
    $command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if ($command['cmd'] === 'quit') {
        break;
    }
    $t0 = (int) $command['t0_ns'];
    while (hrtime(true) < $t0) {
        // busy-wait barrier: all workers of a wave start on the same monotonic instant
    }
    $results = [];
    foreach ($command['ops'] as [$opId, $productId]) {
        $results[] = runOperation($pdo, $runner, $policy, (int) $productId, (int) $opId, $t0, $verify);
    }
    fwrite(STDOUT, json_encode(['results' => $results]) . "\n");
    fflush(STDOUT);
}

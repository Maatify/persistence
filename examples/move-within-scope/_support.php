<?php

declare(strict_types=1);

/**
 * Scaffolding for the move-within-scope examples.
 *
 * It reuses the connection, temporary-table, seeding and assertion helpers of
 * `examples/next-position/_support.php` (example scaffolding only, not package
 * API). Both directories must therefore sit side by side under `examples/`,
 * which is also how the Consumer Verification Harness copies them. The
 * Composer autoloader is resolved by that file, relative to `examples/`.
 */

$shared = dirname(__DIR__) . '/next-position/_support.php';

if (! is_file($shared)) {
    fwrite(STDERR, "examples/next-position/_support.php not found next to this directory.\n");

    exit(2);
}

require $shared;

/**
 * Returns the row titles of one scope ordered by position (then id), read on the
 * given connection.
 *
 * @return list<string>
 */
function example_titles_by_position(PDO $pdo, string $table, int $playlistId): array
{
    $select = $pdo->prepare(
        'SELECT title FROM ' . $table . ' WHERE playlist_id = :playlist_id ORDER BY position ASC, id ASC',
    );
    $select->execute(['playlist_id' => $playlistId]);

    $titles = [];
    foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $value) {
        if (! is_string($value)) {
            throw new RuntimeException('Unexpected title value returned by MySQL.');
        }

        $titles[] = $value;
    }

    return $titles;
}

/**
 * Returns the id of the row with the given title in a scope.
 */
function example_id_of(PDO $pdo, string $table, int $playlistId, string $title): int
{
    $select = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE playlist_id = :playlist_id AND title = :title');
    $select->execute(['playlist_id' => $playlistId, 'title' => $title]);
    $id = $select->fetchColumn();

    if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
        throw new RuntimeException('Row not found: ' . $title);
    }

    return (int) $id;
}

<?php

declare(strict_types=1);

/**
 * Single source of truth for the names of the MySQL objects the package's own
 * tests, examples and Consumer Verification Harness create. The residue check
 * reports any object whose name starts with one of these prefixes, and a unit
 * test fails when tests/ or examples/ use a `maa_` name outside them.
 *
 * Only these prefixes are ever inspected: objects of a host application or of
 * other schemas never match them.
 *
 * @return list<non-empty-string>
 */
return [
    // tests/Support/MySql/*: ordering, pagination, savepoint, deadlock-retry
    // and lazy-savepoint (lsp_*) tables, plus their failure-injection triggers.
    'maa_persistence_test_',
    // tests/Consumer/run.php: maa_persistence_consumer_<hex>.
    'maa_persistence_consumer_',
    // examples/* and tests/Integration/Examples: maa_example_<kind>_<hex>.
    'maa_example_',
];

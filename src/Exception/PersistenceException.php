<?php

declare(strict_types=1);

namespace Maatify\Persistence\Exception;

/**
 * Marker interface for all exceptions thrown by maatify/persistence.
 *
 * This allows consumers to catch all package-level exceptions using:
 *
 *     catch (PersistenceException $e) { ... }
 *
 * Package exceptions implement this interface while using the shared
 * maatify/exceptions hierarchy for their concrete exception base classes.
 */
interface PersistenceException extends \Throwable
{
}

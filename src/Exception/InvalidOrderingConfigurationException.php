<?php

declare(strict_types=1);

namespace Maatify\Persistence\Exception;

use Maatify\Exceptions\Exception\Validation\InvalidArgumentMaatifyException;

/**
 * Thrown when an ordering configuration is invalid.
 *
 * Examples:
 * - Invalid table identifier.
 * - Invalid column identifier.
 * - Unsafe SQL identifier containing unsupported characters.
 *
 * This exception is intended for configuration/programming mistakes, not for
 * user-input validation failures.
 */
final class InvalidOrderingConfigurationException extends InvalidArgumentMaatifyException implements PersistenceException
{
}

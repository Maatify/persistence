<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;

final readonly class SortWhitelist
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var non-empty-array<non-empty-string, non-empty-string> */
    private array $quotedSorts;

    /**
     * @param non-empty-array<non-empty-string, non-empty-string> $sorts
     */
    public function __construct(array $sorts)
    {
        $this->quotedSorts = $this->normalizeSorts($sorts);
    }

    public function contains(string $key): bool
    {
        return array_key_exists($key, $this->quotedSorts);
    }

    /**
     * @return non-empty-string
     */
    public function quotedIdentifierFor(string $key): string
    {
        if (! $this->contains($key)) {
            throw new InvalidPaginationConfigurationException('Unknown pagination sort key.');
        }

        return $this->quotedSorts[$key];
    }

    /**
     * @param array<mixed, mixed> $sorts
     *
     * @return non-empty-array<non-empty-string, non-empty-string>
     */
    private function normalizeSorts(array $sorts): array
    {
        if ($sorts === []) {
            throw new InvalidPaginationConfigurationException('Sort whitelist cannot be empty.');
        }

        $quotedSorts = [];
        foreach ($sorts as $key => $identifierPath) {
            if (! is_string($key) || ! preg_match(self::IDENTIFIER_PATTERN, $key)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort key.');
            }

            if (! is_string($identifierPath)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier.');
            }

            $quotedSorts[$key] = $this->quoteIdentifierPath($identifierPath);
        }
        return $quotedSorts;
    }

    /**
     * @return non-empty-string
     */
    private function quoteIdentifierPath(string $identifierPath): string
    {
        $segments = explode('.', $identifierPath);
        if (count($segments) > 3) {
            throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier path.');
        }

        foreach ($segments as $segment) {
            if (! preg_match(self::IDENTIFIER_PATTERN, $segment)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier segment.');
            }
        }

        return '`' . implode('`.`', $segments) . '`';
    }
}

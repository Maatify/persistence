<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Exception;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Exception\InvalidPaginationQueryException;
use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Exception\PersistenceException;
use PHPUnit\Framework\TestCase;

final class PaginationExceptionContractTest extends TestCase
{
    public function testExceptionBasesMarkersAndErrorCodes(): void { foreach([new InvalidPaginationConfigurationException('x'), new InvalidPaginationQueryException('x'), new PaginationExecutionException('x')] as $e){ self::assertInstanceOf(SystemMaatifyException::class,$e); self::assertInstanceOf(PersistenceException::class,$e); self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $e->getErrorCode()); } }
}

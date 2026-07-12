<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Ordering;

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OrderingPublicApiRegressionTest extends TestCase
{
    public function testOrderingPublicApiUnchanged(): void { self::assertTrue((new ReflectionClass(ScopedOrderingConfig::class))->isFinal()); self::assertTrue((new ReflectionClass(ScopedOrderingManager::class))->isFinal()); self::assertSame(['tableName','idColumn','positionColumn','scopeColumns','deletedAtColumn'], array_map(fn($p)=>$p->getName(), (new ReflectionClass(ScopedOrderingConfig::class))->getConstructor()->getParameters())); $methods=array_map(fn($m)=>$m->getName(), (new ReflectionClass(ScopedOrderingManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC)); foreach(['moveToPosition','moveBefore','moveAfter','compact','nextPosition','maxPosition'] as $name){ self::assertContains($name,$methods); } }
}

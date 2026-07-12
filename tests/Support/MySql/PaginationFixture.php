<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;

final readonly class PaginationFixture
{
    public function __construct(private PDO $pdo) {}
    /** @param list<array{tenant_id:int,category:string|null,active:bool,name:string,score:int,created_at:string}> $rows */
    public function insert(array $rows): void { $s=$this->pdo->prepare('INSERT INTO `'.PaginationSchemaManager::TABLE.'` (`tenant_id`,`category`,`active`,`name`,`score`,`created_at`) VALUES (:tenant_id,:category,:active,:name,:score,:created_at)'); foreach($rows as $r){ $s->bindValue(':tenant_id',$r['tenant_id'],PDO::PARAM_INT); $s->bindValue(':category',$r['category'], $r['category']===null?PDO::PARAM_NULL:PDO::PARAM_STR); $s->bindValue(':active',$r['active'],PDO::PARAM_BOOL); $s->bindValue(':name',$r['name'],PDO::PARAM_STR); $s->bindValue(':score',$r['score'],PDO::PARAM_INT); $s->bindValue(':created_at',$r['created_at'],PDO::PARAM_STR); $s->execute(); } }
    public function seedDefault(): void { $this->insert([['tenant_id'=>1,'category'=>'book','active'=>true,'name'=>'A','score'=>10,'created_at'=>'2026-01-01 00:00:00'],['tenant_id'=>1,'category'=>'book','active'=>true,'name'=>'B','score'=>10,'created_at'=>'2026-01-02 00:00:00'],['tenant_id'=>1,'category'=>'toy','active'=>true,'name'=>'C','score'=>20,'created_at'=>'2026-01-03 00:00:00'],['tenant_id'=>1,'category'=>null,'active'=>false,'name'=>'D','score'=>30,'created_at'=>'2026-01-04 00:00:00'],['tenant_id'=>2,'category'=>'book','active'=>true,'name'=>'E','score'=>40,'created_at'=>'2026-01-05 00:00:00']]); }
}

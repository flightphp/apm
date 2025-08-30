<?php

declare(strict_types=1);

namespace flight\apm\writer;

use PDO;

class MysqlWriter extends WriterAbstract implements WriterSqlInterface
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

	public function getLastInsertId()
    {
        return $this->pdo->lastInsertId();
    }
}

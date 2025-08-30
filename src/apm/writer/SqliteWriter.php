<?php

declare(strict_types=1);

namespace flight\apm\writer;

use PDO;

class SqliteWriter extends WriterAbstract implements WriterSqlInterface
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        // SQLite PRAGMA settings for performance
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        $this->pdo->exec('PRAGMA temp_store = MEMORY;');
        $this->pdo->exec('PRAGMA mmap_size = 30000000000;');
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
    }

	public function getLastInsertId()
    {
        return $this->pdo->lastInsertId();
    }
}

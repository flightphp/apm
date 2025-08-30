<?php
namespace flight\apm\reader;

use PDO;
use flight\apm\reader\ReaderInterface;

class MysqlReader extends ReaderAbstract implements ReaderInterface
{
    /**
     * Create a new MySQL reader
     *
     * @param PDO $pdo PDO instance for database access
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
}

<?php

namespace flight\apm\reader;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;
use flight\apm\reader\MysqlReader;
use PDO;

class ReaderFactory extends ApmFactoryAbstract
{
    /**
     * Create a reader based on the database connection
     *
     * @param string|null $runwayConfigPath Path to the runway config file
     * @return ReaderInterface A reader implementation
     */
    public static function create(?string $runwayConfigPath = null): ReaderInterface
    {
		if ($runwayConfigPath === null) {
			$runwayConfigPath = self::autoLocateRunwayConfigPath();
		}
		$runwayConfig = self::loadConfig($runwayConfigPath);

		$storageType = $runwayConfig['apm']['source_type'];
		$dsn = $runwayConfig['apm']['source_db_dsn'] ?? '';
		$options = $runwayConfig['apm']['source_db_options'] ?: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
		switch ($storageType) {
			case 'sqlite':
				$pdo = new PDO($dsn, null, null, $options);
				return new SqliteReader($pdo);
			case 'mysql':
				$user = $runwayConfig['apm']['source_db_user'] ?? null;
				$pass = $runwayConfig['apm']['source_db_pass'] ?? null;
				$pdo = new PDO($dsn, $user, $pass, $options);
				return new MysqlReader($pdo);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

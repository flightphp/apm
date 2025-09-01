<?php

namespace flight\apm\writer;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;
use PDO;

class WriterFactory extends ApmFactoryAbstract
{
    /**
     * Create a writer based on the database connection
     *
     * @param string|null $runwayConfigPath Path to the runway config file
     * @return WriterInterface A writer implementation
     */
    public static function create(?string $runwayConfigPath = null): WriterInterface
    {
		if ($runwayConfigPath === null) {
			$runwayConfigPath = self::autoLocateRunwayConfigPath();
		}
		$runwayConfig = self::loadConfig($runwayConfigPath);

		$dsn = $runwayConfig['apm']['dest_db_dsn'] ?? '';
		$options = !empty($runwayConfig['apm']['dest_db_options']) ? $runwayConfig['apm']['dest_db_options'] : [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
		$storageType = $runwayConfig['apm']['storage_type'];
		switch($storageType) {
			case 'sqlite':
				$pdo = new PDO($dsn, null, null, $options);
				return new SqliteWriter($pdo);
			case 'mysql':
				$user = $runwayConfig['apm']['dest_db_user'] ?? null;
				$pass = $runwayConfig['apm']['dest_db_pass'] ?? null;
				$pdo = new PDO($dsn, $user, $pass, $options);
				return new MysqlWriter($pdo);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

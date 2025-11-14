<?php

namespace flight\apm\logger;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;
use flight\apm\logger\MysqlLogger;
use flight\database\PdoWrapper;
use PDO;

class LoggerFactory extends ApmFactoryAbstract
{
    /**
     * Create a logger based on the database connection
     *
     * @param string|null|array $runwayConfig Path to the runway config file or the config array itself
     * @return LoggerInterface A logger implementation
     */
    public static function create($runwayConfig = null): LoggerInterface
    {
		$runwayConfigPath = '';
		if ($runwayConfig === null) {
			$runwayConfigPath = self::autoLocateRunwayConfigPath();
		}
		if (is_string($runwayConfig) === true || $runwayConfigPath !== '') {
			$runwayConfigPath = $runwayConfigPath !== '' ? $runwayConfigPath : $runwayConfig;
			$runwayConfig = self::loadConfig($runwayConfigPath);
		}

		$dsn = $runwayConfig['apm']['source_db_dsn'];
		$storageType = $runwayConfig['apm']['source_type'];
		$options = !empty($runwayConfig['apm']['source_db_options']) ? $runwayConfig['apm']['source_db_options'] : [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];
		switch ($storageType) {
			case 'sqlite':
				$pdo = new PDO($dsn, null, null, $options);
				return new SqliteLogger($pdo);
			case 'mysql':
				$user = $runwayConfig['apm']['source_db_user'] ?? null;
				$pass = $runwayConfig['apm']['source_db_pass'] ?? null;
				$pdo = new PDO($dsn, $user, $pass, $options);
				return new MysqlLogger($pdo);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

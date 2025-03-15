<?php

namespace flight\apm\logger;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;

class LoggerFactory extends ApmFactoryAbstract
{
    /**
     * Create a logger based on the database connection
     *
     * @param string|null $runwayConfigPath Path to the runway config file
     * @return LoggerInterface A logger implementation
     */
    public static function create(?string $runwayConfigPath = null): LoggerInterface
    {
		if ($runwayConfigPath === null) {
			$runwayConfigPath = self::autoLocateRunwayConfigPath();
		}
		$runwayConfig = self::loadConfig($runwayConfigPath);

        $storageType = $runwayConfig['apm']['source_type'];
		switch($storageType) {
			case 'sqlite':
				return new SqliteLogger($runwayConfig['apm']['dest_db_dsn']);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

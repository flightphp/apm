<?php

namespace flight\apm\writer;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;

class WriterFactory extends ApmFactoryAbstract
{
    /**
     * Create a writer based on the database connection
     *
     * @param string|null $runwayConfigPath Path to the runway config file
     * @return WriterInterface A writer implementation
     */
    public static function create(?string $runwayConfigPath = null): StorageInterface
    {
		if ($runwayConfigPath === null) {
			$runwayConfigPath = self::autoLocateRunwayConfigPath();
		}
		$runwayConfig = self::loadConfig($runwayConfigPath);

        $storageType = $runwayConfig['apm']['storage_type'];
		switch($storageType) {
			case 'sqlite':
				return new SqliteStorage($runwayConfig['apm']['dest_db_dsn']);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

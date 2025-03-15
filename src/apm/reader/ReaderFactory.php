<?php

namespace flight\apm\reader;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;

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
		switch($storageType) {
			case 'sqlite':
				return new SqliteReader($runwayConfig['apm']['source_db_dsn']);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

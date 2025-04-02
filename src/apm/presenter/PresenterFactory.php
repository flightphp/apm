<?php

namespace flight\apm\presenter;

use flight\apm\ApmFactoryAbstract;
use InvalidArgumentException;

class PresenterFactory extends ApmFactoryAbstract
{
    /**
     * Create a presenter based on the database connection
     *
     * @param string|null $runwayConfigPath Path to the runway config file
     * @return PresenterInterface A presenter implementation
     */
    public static function create(string $runwayConfigPath): PresenterInterface
    {
		if ($runwayConfigPath === null) {
			$runwayConfigPath = self::autoLocateRunwayConfigPath();
		}
		$runwayConfig = self::loadConfig($runwayConfigPath);
        $storageType = $runwayConfig['apm']['storage_type'];
		switch($storageType) {
			case 'sqlite':
				return new SqlitePresenter($runwayConfig);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

<?php

namespace flight\apm\presenter;

use flight\apm\ApmFactoryAbstract;
use flight\database\PdoWrapper;
use InvalidArgumentException;
use PDO;

class PresenterFactory extends ApmFactoryAbstract
{
    /**
     * Create a presenter based on the database connection
     *
     * @param array $runwayConfig The runway config array
     * @return PresenterInterface A presenter implementation
     */
    public static function create(array $runwayConfig): PresenterInterface
    {
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
				$pdo = new PdoWrapper($dsn, null, null, $options);
				return new SqlitePresenter($pdo, $runwayConfig);
			case 'mysql':
				$user = $runwayConfig['apm']['dest_db_user'] ?? null;
				$pass = $runwayConfig['apm']['dest_db_pass'] ?? null;
				$pdo = new PdoWrapper($dsn, $user, $pass, $options);
				return new MysqlPresenter($pdo, $runwayConfig);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

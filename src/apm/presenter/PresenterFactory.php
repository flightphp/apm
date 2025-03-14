<?php

namespace Flight\Apm\Presenter;

use PDO;
use InvalidArgumentException;

class PresenterFactory
{
    /**
     * Create a presenter based on the database connection
     *
     * @param array<string,mixed> $runwayConfig Configuration array containing the database connection details
     * @return PresenterInterface A presenter implementation
     */
    public static function create(array $runwayConfig): PresenterInterface
    {
        $storageType = $runwayConfig['apm']['storage_type'];
		switch($storageType) {
			case 'sqlite':
				return new SqlitePresenter($runwayConfig['apm']['dest_db_dsn']);
			default:
				throw new InvalidArgumentException("Unsupported storage type: $storageType");
		}
    }
}

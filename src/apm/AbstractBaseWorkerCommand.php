<?php

declare(strict_types=1);

namespace flight\apm;

use flight\apm\worker\StorageInterface;
use flight\commands\AbstractBaseCommand;

abstract class AbstractBaseWorkerCommand extends AbstractBaseCommand
{
	protected string $storage_type;
	protected string $storage_path;
	protected string $db_dsn;
	protected string $db_user;
	protected string $db_pass;

	protected function getStorageWorker(): StorageInterface
	{
		switch($this->storage_type) {
			case 'file':
				return new \flight\apm\worker\FileStorage($this->storage_path);
			case 'sqlite':
				return new \flight\apm\worker\SqliteStorage($this->db_dsn);
			case 'mysql':
				return new \flight\apm\worker\MysqlStorage($this->db_dsn, $this->db_user, $this->db_pass);
			case 'timescaledb':
				return new \flight\apm\worker\TimescaledbStorage($this->db_dsn, $this->db_user, $this->db_pass);
			default:
				throw new \InvalidArgumentException('Invalid storage type');
		}
	}

	protected function registerStorageWorkerOptions(): void
	{
		$this->option('--storage_type=VALUE', 'Storage type timescaledb, mysql, sqlite, file (default: timescaledb)');
		$this->option('--storage_path=VALUE', 'Path to store the file (default: /tmp/apm_metrics.json)');
		$this->option('--db_dsn=VALUE', 'Database connection string (default: pgsql:host=localhost;dbname=apm)');
		$this->option('--db_user=VALUE', 'Database user (default: postgres)');
		$this->option('--db_pass=VALUE', 'Database password (default: empty)');
	}
}
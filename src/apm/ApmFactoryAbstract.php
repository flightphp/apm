<?php

declare(strict_types=1);

namespace flight\apm;

abstract class ApmFactoryAbstract
{
	/**
	 * Auto-locates the path to the .runway-config.json file.
	 *
	 * @return string
	 */
	protected static function autoLocateRunwayConfigPath(): string
	{
		$paths = [
			getcwd().'/.runway-config.json',
			__DIR__.'/../.runway-config.json',
			__DIR__.'/../../.runway-config.json'
		];

		foreach ($paths as $path) {
			if (file_exists($path) === true) {
				return $path;
			}
		}

		throw new \RuntimeException('Could not find .runway-config.json file. Please define the path to the config file for the Factory object. It should be in /path/to/project-root/.runway-config.json');
	}

	/**
	 * Loads the configuration from the .runway-config.json file.
	 *
	 * @param string $configPath Path to the .runway-config.json file.
	 * @return array
	 */
	protected static function loadConfig(string $configPath): array
	{
		if (file_exists($configPath) === false) {
			throw new \RuntimeException('Config file not found: ' . $configPath);
		}

		$config = json_decode(file_get_contents($configPath), true);

		if ($config === null) {
			throw new \RuntimeException('Failed to decode JSON from config file: ' . $configPath);
		}

		return $config;
	}
}
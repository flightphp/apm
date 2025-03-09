<?php

declare(strict_types=1);

namespace flight\apm\worker;

interface StorageInterface
{
	/**
	 * Store the given data.
	 *
	 * @param array $data The data to store.
	 * @return void
	 */
	public function store(array $data): void;
}	
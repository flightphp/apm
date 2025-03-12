<?php

declare(strict_types=1);

namespace flight\apm\reader;

interface ReaderInterface
{
    /**
     * Read metrics from the source
     *
     * @return array The raw metrics data
     */
    public function read(): array;
    
	/**
	 * Mark metrics as processed
	 *
	 * @param array $ids IDs of the processed metrics
	 * @return bool Success status
	 */
	public function markProcessed(array $ids): bool;
	
	/**
	 * Check if there are more records to read
	 *
	 * @return bool
	 */
	public function hasMore(): bool;
	
}

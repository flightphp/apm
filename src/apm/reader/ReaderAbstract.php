<?php
declare(strict_types=1);

namespace flight\apm\reader;

use PDO;

abstract class ReaderAbstract
{
	protected $pdo;
	protected bool $hasMore = true;

	/**
     * Read metrics from the SQLite source
     *
     * @param int $limit Maximum number of records to read
     * @return array Array of metric records
     */
    public function read(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM apm_metrics_log ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $this->hasMore = count($results) === $limit;
        
        return $results;
    }

    /**
     * Mark metrics as processed by deleting them
     *
     * @param array $ids IDs of the processed metrics
     * @return bool Success status
     */
    public function markProcessed(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM apm_metrics_log WHERE id IN ($placeholders)");

        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id);
        }
        
        return $stmt->execute();
    }

    /**
     * Check if there are more metrics to process
     *
     * @return bool True if there are more metrics
     */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }
}
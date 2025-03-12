<?php

declare(strict_types=1);

namespace flight\apm\reader;

class FileReader implements ReaderInterface
{
    private string $filePath;
    private array $metrics = [];
    private bool $hasMore = false;
    private array $processedIds = [];

    /**
     * Create a new File reader
     *
     * @param string $filePath Path to the JSON file containing metrics
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        
        // Ensure the file exists
        if (!file_exists($this->filePath)) {
            file_put_contents($this->filePath, json_encode(['metrics' => []]), LOCK_EX);
        }
    }

    /**
     * Read metrics from the file source
     *
     * @param int $limit Maximum number of records to read
     * @return array Array of metric records
     */
    public function read(int $limit = 100): array
    {
        $fileContent = file_get_contents($this->filePath);
        $data = json_decode($fileContent, true) ?: ['metrics' => []];
        
        $metrics = $data['metrics'] ?? [];
        $unprocessedMetrics = array_filter($metrics, fn($metric) => !($metric['processed'] ?? false));
        
        // Sort by ID if present
        usort($unprocessedMetrics, function($a, $b) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        
        $this->metrics = array_slice($unprocessedMetrics, 0, $limit);
        $this->hasMore = count($unprocessedMetrics) > $limit;
        
        return $this->metrics;
    }

    /**
     * Mark metrics as processed
     *
     * @param array $ids IDs of the processed metrics
     * @return bool Success status
     */
    public function markProcessed(array $ids): bool
    {
        $this->processedIds = array_merge($this->processedIds, $ids);
        
        $fileContent = file_get_contents($this->filePath);
        $data = json_decode($fileContent, true) ?: ['metrics' => []];
        
        $metrics = $data['metrics'] ?? [];
        
        foreach ($metrics as $key => $metric) {
            if (in_array($metric['id'] ?? null, $ids)) {
                $metrics[$key]['processed'] = true;
            }
        }
        
        $data['metrics'] = $metrics;
        return file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT)) !== false;
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

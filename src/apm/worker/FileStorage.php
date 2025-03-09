<?php

declare(strict_types=1);

namespace flight\apm\worker;

class FileStorage implements StorageInterface
{
    /**
     * Base directory for storing metrics
     * @var string
     */
    private string $storageDir;

    /**
     * Options for file storage
     * @var array
     */
    private array $options;

    /**
     * @param string $storageDir Base directory to store metric files
     * @param array $options Configuration options:
     *                     - directory_structure: 'daily' (default), 'hourly', 'monthly', 'flat'
     *                     - file_extension: 'json' (default), 'jsonl', 'ndjson'
     *                     - compress: Whether to compress old files (default: false)
     *                     - compression_age_days: After how many days to compress files (default: 7)
     *                     - max_age_days: Max number of days to keep files (default: 30, 0 = forever)
     *                     - file_permissions: File permissions as octal (default: 0644)
     *                     - dir_permissions: Directory permissions as octal (default: 0755)
     */
    public function __construct(string $storageDir, array $options = [])
    {
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->options = array_merge([
            'directory_structure' => 'daily', // daily, hourly, monthly, flat
            'file_extension' => 'json',
            'compress' => false,
            'compression_age_days' => 7,
            'max_age_days' => 30, // 0 = keep forever
            'file_permissions' => 0644,
            'dir_permissions' => 0755,
        ], $options);
        
        $this->ensureStorageDirExists();
        
        // Handle cleanup of old files if configured to do so
        if ($this->options['max_age_days'] > 0 || $this->options['compress']) {
            $this->scheduleMaintenanceTasks();
        }
    }

    /**
     * Store metrics in a JSON file
     *
     * @param array $metrics The metrics data to store
     * @return void
     * @throws \RuntimeException If unable to write to file
     */
    public function store(array $metrics): void
    {
        // Generate a unique ID if one isn't set
        if (empty($metrics['request_id'])) {
            $metrics['request_id'] = $this->generateRequestId();
        }
        
        // Add timestamp if not present
        if (empty($metrics['timestamp'])) {
            $metrics['timestamp'] = microtime(true);
        }
        
        // Determine file path based on directory structure and current time
        $filePath = $this->getFilePath($metrics['timestamp']);
        
        // Ensure the directory for the file exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, $this->options['dir_permissions'], true)) {
                throw new \RuntimeException("Failed to create directory: $dir");
            }
        }
        
        // Use file locking to prevent corruption when writing concurrently
        $json = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode metrics to JSON: ' . json_last_error_msg());
        }
        
        $success = false;
        $fp = fopen($filePath, 'a');
        
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                if (fwrite($fp, $json . PHP_EOL) !== false) {
                    $success = true;
                }
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
        
        if (!$success) {
            throw new \RuntimeException("Failed to write metrics to file: $filePath");
        }
        
        // Set file permissions
        chmod($filePath, $this->options['file_permissions']);
    }
    
    /**
     * Generate the file path based on timestamp and directory structure
     *
     * @param float $timestamp The request timestamp
     * @return string The complete file path
     */
    protected function getFilePath(float $timestamp): string
    {
        $time = (int)$timestamp;
        $date = date('Y-m-d', $time);
        $hour = date('H', $time);
        $month = date('Y-m', $time);
        $fileExt = $this->options['file_extension'];
        
        switch ($this->options['directory_structure']) {
            case 'hourly':
                return "{$this->storageDir}/{$date}/{$hour}/metrics.{$fileExt}";
            case 'monthly':
                return "{$this->storageDir}/{$month}/metrics_{$date}.{$fileExt}";
            case 'flat':
                return "{$this->storageDir}/metrics_{$date}.{$fileExt}";
            case 'daily':
            default:
                return "{$this->storageDir}/{$date}/metrics.{$fileExt}";
        }
    }
    
    /**
     * Generate a unique request ID
     *
     * @return string
     */
    protected function generateRequestId(): string
    {
        return uniqid('req_', true);
    }
    
    /**
     * Ensure the storage directory exists
     *
     * @return void
     * @throws \RuntimeException If directory creation fails
     */
    protected function ensureStorageDirExists(): void
    {
        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, $this->options['dir_permissions'], true)) {
                throw new \RuntimeException("Failed to create storage directory: {$this->storageDir}");
            }
        }
        
        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException("Storage directory is not writable: {$this->storageDir}");
        }
    }
    
    /**
     * Schedule maintenance tasks (file compression and cleanup) with a low probability
     * This ensures maintenance runs occasionally but not on every request
     *
     * @return void
     */
    protected function scheduleMaintenanceTasks(): void
    {
        // Run with 0.1% probability (1 in 1000 requests)
        if (mt_rand(1, 1000) === 1) {
            // Run in a separate process so it doesn't affect request handling
            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    // Child process - run maintenance and exit
                    $this->performMaintenance();
                    exit(0);
                }
                // Parent process continues normally
            } else {
                // Fallback for environments without pcntl_fork
                // Use a very short timeout to avoid blocking
                $this->performMaintenance(true);
            }
        }
    }
    
    /**
     * Perform maintenance tasks like compression and cleanup
     *
     * @param bool $quick Whether to do a quick maintenance (fewer files)
     * @return void
     */
    protected function performMaintenance(bool $quick = false): void
    {
        $now = time();
        $maxAgeDays = $this->options['max_age_days'];
        $compressionAgeDays = $this->options['compression_age_days'];
        
        // Limit processing to just a few directories when in quick mode
        $maxDirsToProcess = $quick ? 3 : 100;
        $processedDirs = 0;
        
        // Process files by iterating through the directory structure
        $iterator = new \RecursiveDirectoryIterator($this->storageDir);
        $iterator = new \RecursiveIteratorIterator($iterator);
        
        foreach ($iterator as $file) {
            // Skip if not a file or doesn't match our extension
            if (!$file->isFile() || 
                !preg_match("/\.{$this->options['file_extension']}$/", $file->getFilename())) {
                continue;
            }
            
            $filePath = $file->getPathname();
            $fileAge = $now - $file->getMTime();
            $fileAgeDays = $fileAge / 86400; // Convert to days
            
            // Check if file should be deleted
            if ($maxAgeDays > 0 && $fileAgeDays > $maxAgeDays) {
                @unlink($filePath);
                continue;
            }
            
            // Check if file should be compressed
            if ($this->options['compress'] && 
                $fileAgeDays > $compressionAgeDays && 
                !preg_match('/\.gz$/', $filePath)) {
                $this->compressFile($filePath);
                
                // Count this directory as processed
                $dir = $file->getPath();
                static $processedDirPaths = [];
                if (!isset($processedDirPaths[$dir])) {
                    $processedDirPaths[$dir] = true;
                    $processedDirs++;
                }
                
                // Break early in quick mode
                if ($quick && $processedDirs >= $maxDirsToProcess) {
                    break;
                }
            }
        }
    }
    
    /**
     * Compress a file with gzip
     *
     * @param string $filePath The file to compress
     * @return bool True if successful, false otherwise
     */
    protected function compressFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $gzFilePath = $filePath . '.gz';
        
        // Open input and output files
        $fp = fopen($filePath, 'rb');
        $zp = gzopen($gzFilePath, 'wb9'); // Maximum compression
        
        // Compress if both handles are valid
        if ($fp && $zp) {
            while (!feof($fp)) {
                $data = fread($fp, 64 * 1024); // 64KB chunks
                gzwrite($zp, $data);
            }
            
            fclose($fp);
            gzclose($zp);
            
            // Remove the original file if compression was successful
            if (file_exists($gzFilePath)) {
                @unlink($filePath);
                return true;
            }
        }
        
        // Clean up on failure
        if ($fp) fclose($fp);
        if ($zp) gzclose($zp);
        if (file_exists($gzFilePath)) @unlink($gzFilePath);
        
        return false;
    }
    
    /**
     * Set a new storage directory
     *
     * @param string $dir New storage directory path
     * @return self
     */
    public function setStorageDirectory(string $dir): self
    {
        $this->storageDir = rtrim($dir, '/\\');
        $this->ensureStorageDirExists();
        return $this;
    }
    
    /**
     * Get the current storage directory
     *
     * @return string Storage directory path
     */
    public function getStorageDirectory(): string
    {
        return $this->storageDir;
    }
    
    /**
     * Update storage options
     *
     * @param array $options New options to merge with existing ones
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
}

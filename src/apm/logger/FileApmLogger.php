<?php

declare(strict_types=1);

namespace flight\apm\logger;

class FileApmLogger implements ApmLoggerInterface
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        // Ensure directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function log(array $metrics): void
    {
        $json = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->filePath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
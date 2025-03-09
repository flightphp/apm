<?php

declare(strict_types=1);

namespace flight\apm\logger;

interface ApmLoggerInterface
{
    public function log(array $metrics): void;
}
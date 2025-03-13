<?php

declare(strict_types=1);

namespace flight\apm\logger;

interface LoggerInterface
{
    public function log(array $metrics): void;
}
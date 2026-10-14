<?php

declare(strict_types=1);

namespace Tests;

use flight\commands\PurgeCommand;
use PHPUnit\Framework\TestCase;

class PurgeCutoffTest extends TestCase
{
    public function testCutoffIsUtc(): void
    {
        $probe = new class (['runway' => []]) extends PurgeCommand {
            public function cutoff(int $days): string
            {
                return $this->cutoffDate($days);
            }
        };

        $expected = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        $this->assertSame($expected, $probe->cutoff(7));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $probe->cutoff(7));
    }
}

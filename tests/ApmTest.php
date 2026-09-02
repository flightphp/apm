<?php

declare(strict_types=1);

use flight\Apm;
use flight\apm\logger\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ApmTest extends TestCase
{
    private Apm $apm;

    protected function setUp(): void
    {
        $logger = new class implements LoggerInterface {
            public function log(array $metrics): void
            {
            }
        };
        $this->apm = new Apm($logger);
    }

    public function testExistingBotNamesStillMatch(): void
    {
        $names = [
            'Googlebot',
            'Bingbot',
            'Slurp',
            'DuckDuckBot',
            'Baiduspider',
            'YandexBot',
            'Sogou',
            'Exabot',
            'facebot',
            'ia_archiver',
        ];

        foreach ($names as $name) {
            $this->assertTrue(
                $this->apm->isBot($name),
                $name . ' should still be detected as a bot'
            );
        }
    }

    public function testAhrefsBotMatches(): void
    {
        $userAgent = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';
        $this->assertTrue($this->apm->isBot($userAgent));
    }

    public function testClaudeSearchBotMatches(): void
    {
        $userAgent = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +searchbot@anthropic.com)';
        $this->assertTrue($this->apm->isBot($userAgent));
    }

    public function testNormalBrowserUserAgentIsNotABot(): void
    {
        $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->assertFalse($this->apm->isBot($userAgent));
    }

    public function testEmptyStringIsNotABot(): void
    {
        $this->assertFalse($this->apm->isBot(''));
    }
}

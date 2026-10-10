<?php

declare(strict_types=1);

namespace Tests;

use flight\Apm;
use flight\apm\logger\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ApmTest extends TestCase
{
    public function testGenerateRequestTokenIsStableForTheRequest(): void
    {
        $apm = new Apm(new class implements LoggerInterface {
            public function log(array $metrics): void
            {
            }
        });
        $token = $apm->generateRequestToken();

        $this->assertNotSame('', $token);
        $this->assertStringStartsWith('req_', $token);
        $this->assertSame($token, $apm->generateRequestToken());
        $this->assertSame($token, $apm->getRequestId());
    }

    public function testIsBotMatchesSearchAndLlmAgents(): void
    {
        $apm = new Apm(new class implements LoggerInterface {
            public function log(array $metrics): void
            {
            }
        });

        $gptBot = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';
        $this->assertTrue($apm->isBot($gptBot));
        $this->assertTrue($apm->isBot('Mozilla/5.0 (compatible; ClaudeBot/1.0)'));
        $agents = 'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)';
        $this->assertTrue($apm->isBot($agents));
        $this->assertTrue($apm->isBot('SearxNG/1.0'));
        $this->assertTrue($apm->isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        $this->assertTrue($apm->isBot('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'));
        $claude = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0)';
        $this->assertTrue($apm->isBot($claude));
        $this->assertTrue($apm->isBot('Anthropic-AI'));
        $this->assertTrue($apm->isBot('facebookexternalhit/1.1'));
        $browser = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';
        $this->assertFalse($apm->isBot($browser));
        $this->assertFalse($apm->isBot(''));
    }

    public function testExistingBotNamesStillMatch(): void
    {
        $apm = new Apm(new class implements LoggerInterface {
            public function log(array $metrics): void
            {
            }
        });
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
            $this->assertTrue($apm->isBot($name), $name . ' should still be detected as a bot');
        }
    }
}

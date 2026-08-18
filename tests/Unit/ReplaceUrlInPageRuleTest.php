<?php

namespace Padosoft\MigrateCloudflareRules\Tests\Unit;

use Padosoft\MigrateCloudflareRules\Console\Commands\MigrateCloudflareRules;
use Padosoft\MigrateCloudflareRules\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ReplaceUrlInPageRuleTest extends TestCase
{
    #[Test]
    public function it_replaces_the_source_url_in_every_target(): void
    {
        $command = new MigrateCloudflareRules;

        $rule = [
            'targets' => [
                ['target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => 'www.example.com/*']],
                ['target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => '*.example.com/api/*']],
                ['target' => 'url', 'constraint' => ['operator' => 'matches']],  // no value: left untouched
            ],
            'actions' => [['id' => 'always_use_https']],
        ];

        $result = $command->replaceUrlInPageRule($rule, 'example.com', 'example.org');

        $this->assertSame('www.example.org/*', $result['targets'][0]['constraint']['value']);
        $this->assertSame('*.example.org/api/*', $result['targets'][1]['constraint']['value']);
        $this->assertArrayNotHasKey('value', $result['targets'][2]['constraint']);
        $this->assertSame($rule['actions'], $result['actions']);
    }

    #[Test]
    public function it_returns_the_rule_untouched_when_there_are_no_targets(): void
    {
        $command = new MigrateCloudflareRules;

        $rule = ['actions' => [['id' => 'always_use_https']]];

        $this->assertSame($rule, $command->replaceUrlInPageRule($rule, 'example.com', 'example.org'));
    }
}

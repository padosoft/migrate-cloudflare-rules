<?php

namespace Padosoft\MigrateCloudflareRules\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Padosoft\MigrateCloudflareRules\array_getEx;

class HelpersTest extends TestCase
{
    #[Test]
    public function array_get_ex_returns_the_value_or_the_default(): void
    {
        $data = ['description' => 'hello', 'nested' => ['key' => 'value'], 'empty' => ''];

        $this->assertSame('hello', array_getEx($data, 'description', 'default'));
        $this->assertSame('', array_getEx($data, 'empty', 'default'));
        $this->assertSame('default', array_getEx($data, 'missing', 'default'));
        $this->assertSame('value', array_getEx($data, 'nested.key', 'default'));
        $this->assertSame('default', array_getEx($data, 'nested.missing', 'default'));
        $this->assertSame('default', array_getEx('not an array', 'description', 'default'));
        $this->assertSame($data, array_getEx($data, null, 'default'));
    }
}

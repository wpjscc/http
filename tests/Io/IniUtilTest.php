<?php

namespace React\Tests\Http\Io;

use React\Http\Io\IniUtil;
use React\Tests\Http\TestCase;

class IniUtilTest extends TestCase
{
    public function provideIniSizes()
    {
        return [
            [
                '1',
                1,
            ],
            [
                '10',
                10,
            ],
            [
                '1024',
                1024,
            ],
            [
                '1K',
                1024,
            ],
            [
                '1.5M',
                1572864,
            ],
            [
                '64M',
                67108864,
            ],
            [
                '8G',
                8589934592,
            ],
            [
                '1T',
                1099511627776,
            ],
        ];
    }

    /**
     * @dataProvider provideIniSizes
     */
    public function testIniSizeToBytes($input, $output)
    {
        $this->assertEquals($output, IniUtil::iniSizeToBytes($input));
    }

    public function testIniSizeToBytesWithInvalidSuffixReturnsNumberWithoutSuffix()
    {
        $this->assertEquals('2', IniUtil::iniSizeToBytes('2x'));
    }

    public function provideInvalidInputIniSizeToBytes()
    {
        return [
            ['-1G'],
            ['0G'],
            ['foo'],
            ['fooK'],
            ['1ooL'],
            ['1ooL'],
        ];
    }

    /**
     * @dataProvider provideInvalidInputIniSizeToBytes
     */
    public function testInvalidInputIniSizeToBytes($input)
    {
        $this->setExpectedException('InvalidArgumentException');
        IniUtil::iniSizeToBytes($input);
    }
}

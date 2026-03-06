<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\BehavioralLogParser;
use PHPUnit\Framework\TestCase;

class BehavioralLogParserTest extends TestCase
{
    private BehavioralLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BehavioralLogParser();
    }

    public function testValidMouseLog(): void
    {
        $json = json_encode([
            'moves' => [
                ['t' => 100, 'x' => 10, 'y' => 20],
                ['t' => 150, 'x' => 30, 'y' => 40],
            ],
            'check' => ['type' => 'click', 't' => 200, 'x' => 50, 'y' => 60, 'offset' => ['x' => 3, 'y' => -2]],
            'tabs'  => [],
            'dt'    => 200,
        ]);

        $result = $this->parser->parse($json);

        $this->assertTrue($result['valid']);
        $this->assertCount(2, $result['data']['moves']);
        $this->assertSame('click', $result['data']['check']['type']);
    }

    public function testValidKeyboardLog(): void
    {
        $json = json_encode([
            'moves' => [],
            'check' => ['type' => 'key', 't' => 500],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab'],
                ['t' => 250, 'key' => 'Tab'],
                ['t' => 400, 'key' => 'Space'],
            ],
            'dt'    => 500,
        ]);

        $result = $this->parser->parse($json);

        $this->assertTrue($result['valid']);
        $this->assertCount(3, $result['data']['tabs']);
    }

    public function testEmptyStringIsInvalid(): void
    {
        $result = $this->parser->parse('');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['data']);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $result = $this->parser->parse('{not valid json}');

        $this->assertFalse($result['valid']);
    }

    public function testMissingCheckIsInvalid(): void
    {
        $json = json_encode(['moves' => [], 'dt' => 100]);

        $result = $this->parser->parse($json);

        $this->assertFalse($result['valid']);
    }

    public function testMissingDtIsInvalid(): void
    {
        $json = json_encode([
            'check' => ['type' => 'click', 't' => 100],
        ]);

        $result = $this->parser->parse($json);

        $this->assertFalse($result['valid']);
    }

    public function testInvalidCheckTypeIsRejected(): void
    {
        $json = json_encode([
            'check' => ['type' => 'invalid', 't' => 100],
            'dt'    => 100,
        ]);

        $result = $this->parser->parse($json);

        $this->assertFalse($result['valid']);
    }

    public function testMalformedMovesAreFiltered(): void
    {
        $json = json_encode([
            'moves' => [
                ['t' => 100, 'x' => 10, 'y' => 20],
                ['bad' => 'data'],
                'not an array',
                ['t' => 200, 'x' => 30, 'y' => 40],
            ],
            'check' => ['type' => 'click', 't' => 300],
            'tabs'  => [],
            'dt'    => 300,
        ]);

        $result = $this->parser->parse($json);

        $this->assertTrue($result['valid']);
        $this->assertCount(2, $result['data']['moves']);
    }

    public function testMissingMovesDefaultsToEmptyArray(): void
    {
        $json = json_encode([
            'check' => ['type' => 'key', 't' => 100],
            'dt'    => 100,
        ]);

        $result = $this->parser->parse($json);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['data']['moves']);
    }

    public function testTouchCheckTypeIsValid(): void
    {
        $json = json_encode([
            'moves' => [['t' => 50, 'x' => 100, 'y' => 200]],
            'check' => ['type' => 'touch', 't' => 100, 'offset' => ['x' => 5, 'y' => 3]],
            'dt'    => 100,
        ]);

        $result = $this->parser->parse($json);

        $this->assertTrue($result['valid']);
    }
}

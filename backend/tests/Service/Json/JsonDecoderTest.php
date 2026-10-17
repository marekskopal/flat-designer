<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Json;

use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JsonDecoder::class)]
final class JsonDecoderTest extends TestCase
{
	public function testDecodePolygon(): void
	{
		self::assertSame([[0, 0], [6151, 0], [6151, 5935]], JsonDecoder::decodePolygon('[[0,0],[6151,0],[6151,5935]]'));
	}

	public function testDecodePolygonRejectsNonIntegerPoints(): void
	{
		$this->expectException(RuntimeException::class);
		JsonDecoder::decodePolygon('[[0,0.5]]');
	}

	public function testDecodeObject(): void
	{
		self::assertSame(['plinth' => 100], JsonDecoder::decodeObject('{"plinth":100}'));
		self::assertSame([], JsonDecoder::decodeObject('{}'));
	}

	public function testDecodeObjectRejectsList(): void
	{
		$this->expectException(RuntimeException::class);
		JsonDecoder::decodeObject('[1,2]');
	}
}

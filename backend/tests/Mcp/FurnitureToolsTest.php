<?php

declare(strict_types=1);

namespace Izbik\Tests\Mcp;

use Izbik\Mcp\Tool\FurnitureTools;
use Izbik\Service\Furniture\PartTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FurnitureTools::class)]
final class FurnitureToolsTest extends TestCase
{
	/** The format agents read in the tool schema names every part type, so a new one cannot be left out. */
	public function testFormatNamesEveryPartType(): void
	{
		foreach (PartTypeEnum::cases() as $type) {
			self::assertStringContainsString(sprintf('"%s"', $type->value), FurnitureTools::Format);
		}
	}
}

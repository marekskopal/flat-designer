<?php

declare(strict_types=1);

namespace Izbik\Tests\Mcp;

use Izbik\Mcp\Tool\QuantityTools;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuantityTools::class)]
final class QuantityToolsTest extends TestCase
{
	/** The schema agents read offers every surface, so a new one cannot be left out. */
	public function testSchemaOffersEverySurface(): void
	{
		foreach (QuantitySurfaceEnum::cases() as $surface) {
			self::assertContains($surface->value, QuantityTools::Surfaces);
		}
		self::assertContains(null, QuantityTools::PricesSchema['items']['properties']['surface']['enum']);
	}
}

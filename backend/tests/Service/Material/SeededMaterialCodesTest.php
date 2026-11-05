<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Material;

use Izbik\Model\Repository\MaterialRepository;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * `tests/fixtures/material-codes.json` lists the seeded materials' codes, whose names the frontend translates
 * (`materials.<code>` in its i18n files, checked by its spec): a migration seeding a material adds its code there.
 */
#[CoversNothing]
final class SeededMaterialCodesTest extends IntegrationTestCase
{
	public function testTheFixtureListsTheSeededMaterials(): void
	{
		$repository = AppHarness::container()->get(MaterialRepository::class);
		assert($repository instanceof MaterialRepository);
		$seeded = [];
		foreach ($repository->findAllOrdered() as $material) {
			if ($material->createdBy === null) {
				$seeded[] = $material->code;
			}
		}
		sort($seeded);

		$listed = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/material-codes.json'), true);

		self::assertSame($seeded, $listed);
	}
}

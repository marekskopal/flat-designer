<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Service\Dbal\ReconnectableDatabase;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\ConvertHallStandRailAndPegsMigration;
use Migrations\SeedHallStandFittingDefinitionsMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/** The hall stand's rail and pegs become Custom items of the fitting definitions in place, and back. */
#[CoversClass(ConvertHallStandRailAndPegsMigration::class)]
final class ConvertHallStandRailAndPegsTest extends EditorTestCase
{
	public function testConvertsTheRailAndPegsInPlaceAndBack(): void
	{
		self::assertSame(1, $this->project->id, 'The migration converts project 1.');
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, SeedHallStandFittingDefinitionsMigration::Files);

		$this->create(
			'items',
			['kind' => 'Box', 'name' => 'Hall stand – oak soffit board', 'z' => 1880, 'width' => 1200, 'depth' => 400, 'height' => 20],
		);
		$rail = $this->create(
			'items',
			['kind' => 'Box', 'name' => 'Hall stand – hanging rail', 'x' => 840, 'z' => 1700, 'width' => 400, 'depth' => 20, 'height' => 20],
		);
		$peg = $this->create(
			'items',
			['kind' => 'Box', 'name' => 'Hall stand – coat peg 1', 'z' => 1650, 'width' => 25, 'depth' => 60, 'height' => 25],
		);
		$other = $this->create('items', ['kind' => 'Box', 'name' => 'Gravel strip', 'width' => 6000, 'depth' => 158, 'height' => 20]);
		$revision = $this->revision();

		$migration = $this->migration();
		$migration->up();
		AppHarness::app()->dbContext->resetOrmState();

		$items = array_column($this->sceneList('items'), null, 'id');
		self::assertSame($revision + 1, $this->revision());
		$converted = $items[self::id($rail)];
		self::assertSame('Custom', $converted['kind']);
		self::assertSame(['values' => ['mount' => 'top']], $converted['params']);
		// The tube's bottom stays; the box reaches up to the soffit board.
		self::assertSame(
			[840, 1700, 400, 50, 180],
			[$converted['x'], $converted['z'], $converted['width'], $converted['depth'], $converted['height']],
		);
		self::assertSame('Custom', $items[self::id($peg)]['kind']);
		self::assertSame(
			[25, 60, 25],
			[$items[self::id($peg)]['width'], $items[self::id($peg)]['depth'], $items[self::id($peg)]['height']],
		);
		self::assertSame('Box', $items[self::id($other)]['kind']);
		$names = array_column($this->sceneList('definitions'), 'name');
		sort($names);
		self::assertSame(['Coat peg', 'Hanging rail'], $names);

		// The converted items validate when edited.
		$moved = $this->edit('PATCH', 'items', self::id($rail), ['params' => ['values' => ['mount' => 'top', 'tube' => 25]]]);
		self::assertSame(200, $moved->getStatusCode(), (string) $moved->getBody());

		$migration->down();
		AppHarness::app()->dbContext->resetOrmState();
		$back = array_column($this->sceneList('items'), null, 'id');
		self::assertSame('Box', $back[self::id($rail)]['kind']);
		self::assertNull($back[self::id($rail)]['definitionId']);
		self::assertSame(
			[400, 20, 20],
			[$back[self::id($rail)]['width'], $back[self::id($rail)]['depth'], $back[self::id($rail)]['height']],
		);
		self::assertSame('Box', $back[self::id($peg)]['kind']);
		self::assertSame([], $this->sceneList('definitions'));
	}

	public function testLeavesTheRailWithoutABoardAbove(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, SeedHallStandFittingDefinitionsMigration::Files);
		$rail = $this->create(
			'items',
			['kind' => 'Box', 'name' => 'Hall stand – hanging rail', 'z' => 1700, 'width' => 400, 'depth' => 20, 'height' => 20],
		);
		$revision = $this->revision();

		$this->migration()->up();
		AppHarness::app()->dbContext->resetOrmState();

		self::assertSame('Box', array_column($this->sceneList('items'), null, 'id')[self::id($rail)]['kind']);
		self::assertSame($revision, $this->revision());
	}

	private function migration(): ConvertHallStandRailAndPegsMigration
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new ConvertHallStandRailAndPegsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
	}
}

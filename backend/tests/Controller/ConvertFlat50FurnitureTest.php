<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Dbal\ReconnectableDatabase;
use FlatDesigner\Service\Furniture\BuiltInFurnitureConverter;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use Migrations\ConvertFlat50FurnitureToDefinitionsMigration;
use Migrations\SeedFlat50FurnitureDefinitionsMigration;
use PHPUnit\Framework\Attributes\CoversClass;

/** The conversion migration on a project laid out like flat 50 (project 1 of the test database): in place and back. */
#[CoversClass(ConvertFlat50FurnitureToDefinitionsMigration::class)]
#[CoversClass(BuiltInFurnitureConverter::class)]
final class ConvertFlat50FurnitureTest extends EditorTestCase
{
	public function testConvertsTheCustomMadeFurnitureInPlaceAndBack(): void
	{
		self::assertSame(1, $this->project->id, 'The migration converts project 1.');
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, SeedFlat50FurnitureDefinitionsMigration::Files);

		$cabinetParams = ['plinth' => 100, 'handle' => 'horizontal', 'fronts' => [['type' => 'drawer', 'height' => 180], ['type' => 'door']]];
		$cabinet = $this->create('items', ['kind' => 'BaseCabinet', 'name' => 'Base 600', 'params' => $cabinetParams]);
		$aluminium = Fixture::service(MaterialRepository::class)->findByCode('aluminium-brushed') ?? self::fail('No aluminium.');
		$wardrobe = $this->create('items', [
			'kind' => 'Wardrobe',
			'name' => 'Wardrobe',
			'width' => 2468,
			'params' => ['doors' => 4, 'mirrorDoors' => [3], 'profileMaterialId' => $aluminium->id],
		]);
		$peg = $this->create(
			'items',
			['kind' => 'Box', 'name' => 'Hall stand – coat peg 1', 'width' => 25, 'depth' => 60, 'height' => 25],
		);
		// Too narrow for the definition's parts: stays built-in.
		$odd = $this->create('items', ['kind' => 'BaseCabinet', 'name' => 'Odd', 'width' => 20, 'params' => $cabinetParams]);
		$panel = $this->create(
			'items',
			['kind' => 'Box', 'name' => 'Hall stand – oak back panel', 'width' => 1200, 'depth' => 18, 'height' => 1400],
		);
		$gravel = $this->create('items', ['kind' => 'Box', 'name' => 'Gravel strip', 'width' => 6000, 'depth' => 158, 'height' => 20]);
		$sink = $this->create('items', ['kind' => 'Sink', 'name' => 'Sink']);
		$deleted = $this->create('items', ['kind' => 'Chair', 'name' => 'Spare chair']);
		$this->edit('DELETE', 'items', self::id($deleted));
		$group = $this->jsonBody($this->edit('POST', 'groups', body: ['name' => 'Kitchen', 'itemIds' => [self::id($cabinet)]]));
		self::assertIsArray($group['element']);
		$revision = $this->revision();

		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);
		$migration = new ConvertFlat50FurnitureToDefinitionsMigration(new DatabaseProviderFactory()->create($database->getInnerDatabase()));
		$migration->up();
		AppHarness::app()->dbContext->resetOrmState();

		$items = array_column($this->sceneList('items'), null, 'id');
		self::assertSame($revision + 1, $this->revision());
		// Same ids, the group kept, the material and size as they were.
		$converted = $items[self::id($cabinet)];
		self::assertSame('Custom', $converted['kind']);
		self::assertSame($group['element']['id'], $converted['groupId']);
		self::assertSame([600, 580, 860], [$converted['width'], $converted['depth'], $converted['height']]);
		self::assertSame(
			['values' => ['plinth' => 100, 'fronts' => [['type' => 'drawer', 'height' => 180], ['type' => 'door']], 'handle' => 'horizontal', 'handleSide' => 'right', 'blindLeft' => 0, 'blindRight' => 0]],
			$converted['params'],
		);
		self::assertSame(
			['values' => ['doors' => [[], [], ['mirror' => true], []], 'plinth' => 60], 'materials' => ['profile' => $aluminium->id]],
			$items[self::id($wardrobe)]['params'],
		);
		self::assertSame('Custom', $items[self::id($panel)]['kind']);
		self::assertSame(
			['Box', 'Box', 'Sink', 'BaseCabinet'],
			[
				$items[self::id($gravel)]['kind'],
				$items[self::id($peg)]['kind'],
				$items[self::id($sink)]['kind'], $items[self::id(
					$odd,
				)]['kind']],
		);
		$names = array_column($this->sceneList('definitions'), 'name');
		sort($names);
		self::assertSame(['Kitchen base cabinet', 'Panel', 'Sliding wardrobe'], $names);

		// The converted items are ordinary Custom items: they validate when edited.
		self::assertSame(200, $this->edit('PATCH', 'items', self::id($cabinet), ['x' => 1500])->getStatusCode());
		$wider = $this->edit(
			'PATCH',
			'items',
			self::id($cabinet),
			['params' => ['values' => ['fronts' => [['type' => 'door', 'count' => 2]]]]],
		);
		self::assertSame(200, $wider->getStatusCode(), (string) $wider->getBody());
		// Undo works from the change log. Back over the edits since the conversion first.
		self::assertSame(200, $this->edit('PATCH', 'items', self::id($cabinet), ['x' => 1600])->getStatusCode());
		for ($step = 1; $step <= 3; $step++) {
			self::assertSame(200, $this->undo()->getStatusCode(), sprintf('undo %d', $step));
		}
		// The grouping from before the conversion logged a built-in cabinet, which the converted item no longer is: a
		// conflict, which closes that batch (the group and its member). The next undo is the chair's deletion, undone from
		// the log too: it comes back as the built-in kind it was deleted as.
		self::assertSame(409, $this->undo()->getStatusCode());
		self::assertSame(200, $this->undo()->getStatusCode());
		$restored = array_column($this->sceneList('items'), null, 'id');
		self::assertSame('Chair', $restored[self::id($deleted)]['kind']);
		// An item made from a definition since, with its own gap, is not a built-in cabinet: down() leaves it.
		$definitionId = $items[self::id($cabinet)]['definitionId'];
		$own = $this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId, 'params' => ['values' => ['gap' => 2]]]);

		$migration->down();
		AppHarness::app()->dbContext->resetOrmState();
		$back = array_column($this->sceneList('items'), null, 'id');
		self::assertSame('BaseCabinet', $back[self::id($cabinet)]['kind']);
		self::assertNull($back[self::id($cabinet)]['definitionId']);
		// The edits were undone: the cabinet is back as it was made, every built-in key written out.
		self::assertSame(
			['plinth' => 100, 'handle' => 'horizontal', 'handleSide' => 'right', 'fronts' => [['type' => 'drawer', 'height' => 180], ['type' => 'door']]],
			$back[self::id($cabinet)]['params'],
		);
		self::assertSame(
			['doors' => 4, 'mirrorDoors' => [3], 'plinth' => 60, 'profileMaterialId' => $aluminium->id],
			$back[self::id($wardrobe)]['params'],
		);
		self::assertSame('Box', $back[self::id($panel)]['kind']);
		self::assertSame('Custom', $back[self::id($own)]['kind']);
		self::assertSame([$definitionId], array_column($this->sceneList('definitions'), 'id'));
	}
}

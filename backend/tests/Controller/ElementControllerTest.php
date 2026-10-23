<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ElementController;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\ColumnParamsValidator;
use FlatDesigner\Service\Editor\Element\ItemHandler;
use FlatDesigner\Service\Editor\Element\OpeningHandler;
use FlatDesigner\Service\Editor\Element\OpeningLook;
use FlatDesigner\Service\Editor\Element\RoomCeiling;
use FlatDesigner\Service\Editor\Element\RoomHandler;
use FlatDesigner\Service\Editor\Element\StructureSize;
use FlatDesigner\Service\Editor\Element\WallCurve;
use FlatDesigner\Service\Editor\Element\WallHandler;
use FlatDesigner\Service\Editor\Geometry\WallLine;
use FlatDesigner\Service\Editor\PolygonValidator;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ElementController::class)]
#[CoversClass(SceneEditor::class)]
#[CoversClass(ItemHandler::class)]
#[CoversClass(WallHandler::class)]
#[CoversClass(OpeningHandler::class)]
#[CoversClass(OpeningLook::class)]
#[CoversClass(RoomHandler::class)]
#[CoversClass(RoomCeiling::class)]
#[CoversClass(StructureSize::class)]
#[CoversClass(PolygonValidator::class)]
#[CoversClass(ColumnParamsValidator::class)]
#[CoversClass(WallCurve::class)]
#[CoversClass(WallLine::class)]
final class ElementControllerTest extends EditorTestCase
{
	public function testCreateItemFillsDefaultsLogsAndBumpsRevision(): void
	{
		$revision = $this->revision();

		$response = $this->edit('POST', 'items', body: ['kind' => 'Sink', 'name' => 'Sink', 'x' => 300, 'rotation' => -90]);

		self::assertSame(201, $response->getStatusCode());
		$result = $this->jsonBody($response);
		self::assertSame($revision + 1, $result['revision']);
		self::assertIsArray($result['element']);
		self::assertSame('Sink', $result['element']['kind']);
		self::assertSame(270, $result['element']['rotation']);
		self::assertSame(780, $result['element']['width']);
		self::assertIsArray($result['change']);
		self::assertSame('Added item "Sink"', $result['change']['summary']);
		self::assertSame('Jane', $result['change']['actorName']);
		self::assertSame(['Sink'], array_column($this->sceneList('items'), 'name'));
	}

	public function testUpdateChangesOnlyGivenFieldsAndSkipsNoOps(): void
	{
		$item = $this->create('items', ['name' => 'Base 60', 'width' => 600]);

		$moved = $this->jsonBody($this->edit('PATCH', 'items', self::id($item), ['x' => 1200, 'y' => 50]));
		self::assertIsArray($moved['change']);
		self::assertSame('Moved item "Base 60"', $moved['change']['summary']);
		self::assertIsArray($moved['element']);
		self::assertSame(600, $moved['element']['width']);
		self::assertSame(1200, $moved['element']['x']);

		$changed = $this->jsonBody($this->edit('PATCH', 'items', self::id($item), ['width' => 450, 'params' => ['handle' => 'none']]));
		self::assertIsArray($changed['change']);
		self::assertSame('Changed item "Base 60" (width, params)', $changed['change']['summary']);

		$revision = $this->revision();
		$noop = $this->jsonBody($this->edit('PATCH', 'items', self::id($item), ['x' => 1200]));
		self::assertNull($noop['change']);
		self::assertSame($revision, $noop['revision']);
	}

	public function testDeleteHidesElement(): void
	{
		$item = $this->create('items', ['name' => 'Gone']);

		self::assertSame(200, $this->edit('DELETE', 'items', self::id($item))->getStatusCode());

		self::assertSame([], $this->sceneList('items'));
		self::assertSame(404, $this->edit('PATCH', 'items', self::id($item), ['x' => 1])->getStatusCode());
		self::assertSame(404, $this->edit('DELETE', 'items', self::id($item))->getStatusCode());
	}

	public function testItemValidation(): void
	{
		$otherFloor = Fixture::firstFloor(Fixture::createProject($this->user, 'Other'));
		$foreignRoom = $this->jsonBody($this->request(
			'POST',
			sprintf('/api/projects/%d/floors/%d/rooms', $otherFloor->project->id, $otherFloor->id),
			['name' => 'Elsewhere'],
			$this->user,
		))['element'];

		self::assertSame(422, $this->edit('POST', 'items', body: ['kind' => 'Spaceship'])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'items', body: ['width' => 0])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'items', body: ['x' => 1.5])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'items', body: ['materialId' => 99999])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'items', body: ['roomId' => self::id($foreignRoom)])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'items', body: ['params' => [1, 2]])->getStatusCode());
		$unknown = $this->edit('POST', 'items', body: ['colour' => 'red']);
		self::assertSame(422, $unknown->getStatusCode());
		self::assertStringContainsString('Unknown field(s): colour', (string) $unknown->getBody());
	}

	public function testParamsOfPlainKindsAreFreeForm(): void
	{
		$box = $this->create('items', ['kind' => 'Box', 'params' => ['handle' => 'knob']]);
		self::assertSame(['handle' => 'knob'], $box['params']);
	}

	public function testColumnsAndBeamsFitTheCeilingOfTheirRoom(): void
	{
		$polygon = [[0, 0], [4000, 0], [4000, 3000], [0, 3000]];
		$flat = $this->create('rooms', ['name' => 'Hall', 'polygon' => $polygon, 'ceilingHeight' => 2600]);
		$vaulted = $this->create('rooms', ['name' => 'Living', 'polygon' => $polygon, 'ceilingHeight' => 2920, 'vaultSpacing' => 550]);

		$column = $this->create('items', ['kind' => 'Column', 'roomId' => self::id($flat)]);
		self::assertSame(
			['Column', 0, 300, 300, 2600],
			[$column['name'], $column['z'], $column['width'], $column['depth'], $column['height']],
		);
		$beam = $this->create('items', ['kind' => 'Beam', 'roomId' => self::id($vaulted)]);
		self::assertSame([2920 - 130 - 130, 3000, 100, 130], [$beam['z'], $beam['width'], $beam['depth'], $beam['height']]);
		$given = $this->create('items', ['kind' => 'Beam', 'roomId' => self::id($flat), 'height' => 200, 'z' => 2000]);
		self::assertSame([2000, 200], [$given['z'], $given['height']]);
		$tall = $this->create('items', ['kind' => 'Beam', 'roomId' => self::id($flat), 'height' => 300]);
		self::assertSame([2300, 300], [$tall['z'], $tall['height']]);
		$plinth = $this->create('items', ['kind' => 'Column', 'roomId' => self::id($flat), 'z' => 300]);
		self::assertSame([300, 2600], [$plinth['z'], $plinth['height']]);
		$outside = $this->create('items', ['kind' => 'Column']);
		self::assertSame(2600, $outside['height']);

		$round = $this->jsonBody($this->edit('PATCH', 'items', self::id($column), ['params' => ['shape' => 'round']]));
		self::assertIsArray($round['element']);
		self::assertSame(['shape' => 'round'], $round['element']['params']);
		foreach ([['shape' => 'oval'], ['shape' => 1]] as $invalid) {
			$response = $this->edit('PATCH', 'items', self::id($column), ['params' => $invalid]);
			self::assertSame(422, $response->getStatusCode(), (string) json_encode($invalid));
		}
	}

	public function testStaleRevisionIsRejected(): void
	{
		$item = $this->create('items', ['name' => 'Base']);
		$revision = $this->revision();
		$this->create('items', ['name' => 'Concurrent edit']);

		self::assertSame(409, $this->edit('PATCH', 'items', self::id($item), ['x' => 10], baseRevision: $revision)->getStatusCode());
		self::assertSame(
			200,
			$this->edit('PATCH', 'items', self::id($item), ['x' => 10], baseRevision: $this->revision())->getStatusCode(),
		);
	}

	public function testOpeningsMustFitTheirWall(): void
	{
		$wall = $this->create('walls', ['name' => 'South', 'startX' => 0, 'startY' => 0, 'endX' => 3000, 'endY' => 0, 'height' => 2600]);
		$door = $this->create('openings', ['wallId' => self::id($wall), 'offset' => 500, 'width' => 900]);

		self::assertSame(
			422,
			$this->edit('POST', 'openings', body: ['wallId' => self::id($wall), 'offset' => 1000, 'width' => 900])->getStatusCode(),
		);
		self::assertSame(
			422,
			$this->edit('POST', 'openings', body: ['wallId' => self::id($wall), 'offset' => 2500, 'width' => 900])->getStatusCode(),
		);
		self::assertSame(
			422,
			$this->edit('POST', 'openings', body: ['wallId' => self::id($wall), 'offset' => 2000, 'height' => 2700])->getStatusCode(),
		);
		$window = $this->create(
			'openings',
			[
				'wallId' => self::id(
					$wall,
				),
				'type' => 'Window',
				'name' => 'W',
				'offset' => 1600,
				'width' => 1200,
				'sillHeight' => 900,
				'height' => 1200],
		);
		self::assertSame('Window', $window['type']);

		// Moving the door onto the window, or shortening the wall under the window, is refused.
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($door), ['offset' => 1500])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'walls', self::id($wall), ['endX' => 2000])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($door), ['wallId' => 999])->getStatusCode());

		$walls = $this->sceneList('walls');
		self::assertIsList($walls[0]['openings']);
		self::assertCount(2, $walls[0]['openings']);
	}

	public function testDoorKindsGetTheirDefaultsAndNormalisedFields(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 6000, 'endY' => 0, 'thickness' => 150]);

		$double = $this->create('openings', ['wallId' => self::id($wall), 'type' => 'DoubleDoor', 'offset' => 100]);
		self::assertSame(['Double door', 1400, null, 'Start', 'Left', 'Lining', null], [
			$double['name'],
			$double['width'],
			$double['leafWidth'],
			$double['hinge'],
			$double['side'],
			$double['frameStyle'],
			$double['handle'],
		]);

		$passage = $this->create(
			'openings',
			['wallId' => self::id($wall), 'type' => 'Passage', 'offset' => 2000, 'leafWidth' => 800, 'hinge' => 'End', 'handle' => 'Knob'],
		);
		self::assertSame([2100, null, null, null, 'None', null], [
			$passage['height'],
			$passage['leafWidth'],
			$passage['hinge'],
			$passage['side'],
			$passage['frameStyle'],
			$passage['handle'],
		]);

		$sliding = $this->create(
			'openings',
			[
				'wallId' => self::id(
					$wall,
				),
				'type' => 'SlidingDoor',
				'offset' => 4000,
				'hinge' => 'End',
				'handle' => 'Pull',
				'frameStyle' => 'Architrave'],
		);
		self::assertSame(
			['End', null, 'Pull', 'Architrave'],
			[$sliding['hinge'], $sliding['leafWidth'], $sliding['handle'], $sliding['frameStyle']],
		);

		$changes = $this->jsonBody($this->edit('PATCH', 'openings', self::id($sliding), ['type' => 'Door']));
		self::assertIsArray($changes['change']);
		self::assertSame('Changed door "Sliding door" (type)', $changes['change']['summary']);
	}

	public function testWindowsHaveDivisionsAndSillBoardsDoorsDoNot(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 6000, 'endY' => 0, 'thickness' => 300]);
		$wallId = self::id($wall);

		$window = $this->create('openings', ['wallId' => $wallId, 'type' => 'Window', 'offset' => 100]);
		self::assertSame([1, 0, null], [$window['mullions'], $window['transoms'], $window['sillBoard']]);
		$divided = $this->jsonBody(
			$this->edit('PATCH', 'openings', self::id($window), ['mullions' => 3, 'transoms' => 1, 'sillBoard' => 40]),
		);
		self::assertIsArray($divided['element']);
		self::assertSame([3, 1, 40], [$divided['element']['mullions'], $divided['element']['transoms'], $divided['element']['sillBoard']]);
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($window), ['mullions' => 9])->getStatusCode());

		// A walkable window has one or two sashes.
		$french = ['wallId' => $wallId, 'type' => 'Window', 'offset' => 2000, 'sillHeight' => 0, 'height' => 2200, 'walkable' => true];
		self::assertSame(422, $this->edit('POST', 'openings', body: $french + ['mullions' => 2])->getStatusCode());
		self::assertSame(0, $this->create('openings', $french + ['mullions' => 0])['mullions']);

		$door = $this->create('openings', ['wallId' => $wallId, 'type' => 'Door', 'offset' => 4000, 'mullions' => 4, 'sillBoard' => 40]);
		self::assertSame([1, 0, null], [$door['mullions'], $door['transoms'], $door['sillBoard']]);
	}

	public function testDoorLeafStylesAndWindowPanelsAndShutters(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 6000, 'endY' => 0, 'thickness' => 300]);
		$wallId = self::id($wall);
		$timber = Fixture::service(MaterialRepository::class)->findByCode('kitchen-oak');
		self::assertNotNull($timber);

		$door = $this->create('openings', ['wallId' => $wallId, 'type' => 'Door', 'offset' => 100]);
		self::assertSame(['Flush', 2], [$door['leafStyle'], $door['leafPanels']]);
		$panelled = $this->jsonBody($this->edit('PATCH', 'openings', self::id($door), ['leafStyle' => 'Panelled', 'leafPanels' => 4]));
		self::assertIsArray($panelled['element']);
		self::assertSame(['Panelled', 4], [$panelled['element']['leafStyle'], $panelled['element']['leafPanels']]);
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($door), ['leafPanels' => 7])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($door), ['leafStyle' => 'Carved'])->getStatusCode());
		// Doors have no shutters or panel; a passage no leaf to style.
		$plain = $this->jsonBody($this->edit('PATCH', 'openings', self::id($door), ['shutters' => 'Louvred', 'panelHeight' => 500]));
		self::assertIsArray($plain['element']);
		self::assertSame([null, null], [$plain['element']['shutters'], $plain['element']['panelHeight']]);
		$passage = $this->create('openings', ['wallId' => $wallId, 'type' => 'Passage', 'offset' => 1200, 'leafStyle' => 'Glazed']);
		self::assertSame('Flush', $passage['leafStyle']);

		$french = [
			'wallId' => $wallId,
			'type' => 'Window',
			'offset' => 2500,
			'sillHeight' => 0,
			'height' => 2400,
			'walkable' => true,
			'panelHeight' => 600,
			'shutters' => 'Louvred',
			'shutterMaterialId' => $timber->id,
		];
		$window = $this->create('openings', $french);
		self::assertSame(
			[600, 'Louvred', $timber->id, false],
			[$window['panelHeight'], $window['shutters'], $window['shutterMaterialId'], $window['shuttersClosed']],
		);
		$closed = $this->jsonBody($this->edit('PATCH', 'openings', self::id($window), ['shuttersClosed' => true]));
		self::assertIsArray($closed['element']);
		self::assertTrue($closed['element']['shuttersClosed']);
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($window), ['panelHeight' => 2300])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($window), ['shutterMaterialId' => 999999])->getStatusCode());

		// Without shutters, their material and state go too; undo brings them back.
		$bare = $this->jsonBody($this->edit('PATCH', 'openings', self::id($window), ['shutters' => null]));
		self::assertIsArray($bare['element']);
		self::assertSame(
			[null, null, false],
			[$bare['element']['shutters'], $bare['element']['shutterMaterialId'], $bare['element']['shuttersClosed']],
		);
		$this->undo();
		$openings = $this->sceneList('walls')[0]['openings'];
		self::assertIsList($openings);
		$restored = array_values(
			array_filter($openings, static fn (mixed $o): bool => is_array($o) && $o['id'] === $window['id']),
		)[0] ?? null;
		self::assertIsArray($restored);
		self::assertSame(
			['Louvred', $timber->id, true],
			[$restored['shutters'], $restored['shutterMaterialId'], $restored['shuttersClosed']],
		);
	}

	public function testSlidingAndPocketDoorsNeedWallBesideThem(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 3000, 'endY' => 0, 'thickness' => 150]);
		$wallId = self::id($wall);

		// Opening at 1000–1900 sliding towards the end needs 1900–2900 free for its 1000 mm leaf.
		self::assertSame(
			422,
			$this->edit(
				'POST',
				'openings',
				body: ['wallId' => $wallId, 'type' => 'SlidingDoor', 'offset' => 1200, 'hinge' => 'End'],
			)->getStatusCode(),
		);
		$sliding = $this->create('openings', ['wallId' => $wallId, 'type' => 'SlidingDoor', 'offset' => 1000, 'hinge' => 'End']);

		// Nothing may sit where the leaf parks, and the wall cannot be shortened under it.
		self::assertSame(
			422,
			$this->edit(
				'POST',
				'openings',
				body: ['wallId' => $wallId, 'type' => 'Window', 'offset' => 2000, 'width' => 600],
			)->getStatusCode(),
		);
		self::assertSame(422, $this->edit('PATCH', 'walls', $wallId, ['endX' => 2800])->getStatusCode());
		$this->create('openings', ['wallId' => $wallId, 'type' => 'Window', 'offset' => 100, 'width' => 600]);

		// A pocket door needs a wall thick enough for the pocket, and keeps it that thick.
		$thin = $this->create('walls', ['startX' => 0, 'startY' => 1000, 'endX' => 3000, 'endY' => 1000, 'thickness' => 80]);
		self::assertSame(
			422,
			$this->edit('POST', 'openings', body: ['wallId' => self::id($thin), 'type' => 'PocketDoor', 'offset' => 1000])->getStatusCode(),
		);
		self::assertSame(200, $this->edit('PATCH', 'openings', self::id($sliding), ['type' => 'PocketDoor'])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'walls', $wallId, ['thickness' => 90])->getStatusCode());
	}

	public function testNichesAreRecessesIntoOneFace(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 4000, 'endY' => 0, 'thickness' => 200]);
		$wallId = self::id($wall);

		$niche = $this->create('openings', ['wallId' => $wallId, 'type' => 'Niche', 'offset' => 500, 'hinge' => 'End', 'handle' => 'Knob']);
		self::assertSame(['Niche', 600, 600, 900, 100, 'Left', null, null, 'None'], [
			$niche['name'],
			$niche['width'],
			$niche['height'],
			$niche['sillHeight'],
			$niche['depth'],
			$niche['side'],
			$niche['hinge'],
			$niche['handle'],
			$niche['frameStyle'],
		]);

		// At most the wall less 30 mm deep, never the entrance; the wall cannot get thinner than the niche needs.
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($niche), ['depth' => 171])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($niche), ['entrance' => true])->getStatusCode());
		$deep = $this->jsonBody($this->edit('PATCH', 'openings', self::id($niche), ['depth' => 170, 'side' => 'Right']));
		self::assertIsArray($deep['element']);
		self::assertSame([170, 'Right'], [$deep['element']['depth'], $deep['element']['side']]);
		self::assertSame(422, $this->edit('PATCH', 'walls', $wallId, ['thickness' => 190])->getStatusCode());
		self::assertSame(200, $this->edit('PATCH', 'walls', $wallId, ['thickness' => 250])->getStatusCode());

		// Niches from both faces may share a stretch of wall while 30 mm stays between them.
		$this->create(
			'openings',
			['wallId' => $wallId, 'type' => 'Niche', 'offset' => 2000, 'width' => 600, 'depth' => 100, 'side' => 'Left'],
		);
		self::assertSame(
			422,
			$this->edit(
				'POST',
				'openings',
				body: ['wallId' => $wallId, 'type' => 'Niche', 'offset' => 2300, 'depth' => 130, 'side' => 'Right'],
			)->getStatusCode(),
		);
		$back = $this->create('openings', ['wallId' => $wallId, 'type' => 'Niche', 'offset' => 2300, 'depth' => 120, 'side' => 'Right']);
		self::assertSame(120, $back['depth']);
		self::assertSame(422, $this->edit('PATCH', 'walls', $wallId, ['thickness' => 240])->getStatusCode());
		self::assertSame(200, $this->edit('PATCH', 'walls', $wallId, ['thickness' => 260])->getStatusCode());

		// Another type has no depth.
		$window = $this->jsonBody($this->edit('PATCH', 'openings', self::id($niche), ['type' => 'Window']));
		self::assertIsArray($window['element']);
		self::assertNull($window['element']['depth']);

		$thin = $this->create('walls', ['startX' => 0, 'startY' => 1000, 'endX' => 3000, 'endY' => 1000, 'thickness' => 30]);
		self::assertSame(
			422,
			$this->edit('POST', 'openings', body: ['wallId' => self::id($thin), 'type' => 'Niche', 'offset' => 100])->getStatusCode(),
		);
	}

	public function testMovingWallStartKeepsOpeningsInPlace(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 4000, 'endY' => 0]);
		$door = $this->create('openings', ['wallId' => self::id($wall), 'offset' => 1000, 'width' => 900]);
		self::assertSame([], $wall['openingOffsets']);

		// Shortened from the start by 500 mm: the door stays at x = 1000.
		$moved = $this->jsonBody($this->edit('PATCH', 'walls', self::id($wall), ['startX' => 500]))['element'];
		self::assertIsArray($moved);
		self::assertSame([['id' => self::id($door), 'offset' => 500]], $moved['openingOffsets']);
		self::assertSame(500, $this->openingOffset(self::id($door)));

		// Lengthened at the start too.
		$this->edit('PATCH', 'walls', self::id($wall), ['startX' => -1000]);
		self::assertSame(2000, $this->openingOffset(self::id($door)));

		// Past the door the wall cannot be shortened, from either end.
		self::assertSame(422, $this->edit('PATCH', 'walls', self::id($wall), ['startX' => 1500])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'walls', self::id($wall), ['endX' => 1500])->getStatusCode());

		// Moving the end leaves the offsets alone; undo puts both the wall and the door back exactly.
		$this->edit('PATCH', 'walls', self::id($wall), ['endX' => 3000]);
		self::assertSame(2000, $this->openingOffset(self::id($door)));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(500, $this->openingOffset(self::id($door)));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(1000, $this->openingOffset(self::id($door)));
		self::assertSame(200, $this->redo()->getStatusCode());
		self::assertSame(500, $this->openingOffset(self::id($door)));
	}

	public function testEntranceFlag(): void
	{
		$wall = $this->create('walls', []);
		$door = $this->create('openings', ['wallId' => self::id($wall)]);
		self::assertFalse($door['entrance']);

		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($door), ['entrance' => 'yes'])->getStatusCode());
		$this->edit('PATCH', 'openings', self::id($door), ['entrance' => true]);

		$walls = $this->sceneList('walls');
		self::assertIsList($walls[0]['openings']);
		self::assertIsArray($walls[0]['openings'][0]);
		self::assertTrue($walls[0]['openings'][0]['entrance']);
	}

	public function testOnlyWindowsDownToTheFloorAreWalkable(): void
	{
		$wall = $this->create('walls', []);
		$door = $this->create('openings', ['wallId' => self::id($wall)]);
		$window = $this->create('openings', ['wallId' => self::id($wall), 'type' => 'Window', 'offset' => 1500]);
		self::assertFalse($window['walkable']);

		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($door), ['walkable' => true])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($window), ['walkable' => true])->getStatusCode());
		$this->edit('PATCH', 'openings', self::id($window), ['walkable' => true, 'sillHeight' => 0, 'height' => 2200]);
		// Raising the sill again needs the flag cleared.
		self::assertSame(422, $this->edit('PATCH', 'openings', self::id($window), ['sillHeight' => 300])->getStatusCode());

		$walls = $this->sceneList('walls');
		self::assertIsList($walls[0]['openings']);
		self::assertIsArray($walls[0]['openings'][1]);
		self::assertTrue($walls[0]['openings'][1]['walkable']);
	}

	public function testDeletingWallHidesItsOpenings(): void
	{
		$wall = $this->create('walls', []);
		$this->create('openings', ['wallId' => self::id($wall)]);

		$this->edit('DELETE', 'walls', self::id($wall));

		self::assertSame([], $this->sceneList('walls'));
		self::assertSame(422, $this->edit('POST', 'openings', body: ['wallId' => self::id($wall)])->getStatusCode());
	}

	public function testWallFacesHaveFinishesOfTheirOwn(): void
	{
		$tiles = Fixture::service(MaterialRepository::class)->findByCode('tile-wall-beige');
		self::assertNotNull($tiles);
		$band = ['materialId' => $tiles->id, 'bottom' => 0, 'top' => 2100];

		$wall = $this->create('walls', []);
		self::assertSame([null, null], [$wall['leftFinishes'], $wall['rightFinishes']]);

		$changed = $this->jsonBody($this->edit('PATCH', 'walls', self::id($wall), ['leftFinishes' => [$band], 'rightFinishes' => []]));
		self::assertIsArray($changed['element']);
		self::assertSame([[$band], []], [$changed['element']['leftFinishes'], $changed['element']['rightFinishes']]);
		self::assertSame([$band], $this->sceneList('walls')[0]['leftFinishes']);

		foreach ([
			['leftFinishes' => [['materialId' => $tiles->id, 'bottom' => 500, 'top' => 100]]],
			['leftFinishes' => [['materialId' => 999999, 'bottom' => 0, 'top' => 100]]],
			['rightFinishes' => 'tiles'],
		] as $body) {
			self::assertSame(
				422,
				$this->edit('PATCH', 'walls', self::id($wall), $body)->getStatusCode(),
				json_encode($body, JSON_THROW_ON_ERROR),
			);
		}

		// Turning the wall round keeps the bands on the same side of the plan; undo turns it back.
		$turned = $this->jsonBody($this->edit('PATCH', 'walls', self::id($wall), [
			'startX' => $wall['endX'],
			'startY' => $wall['endY'],
			'endX' => $wall['startX'],
			'endY' => $wall['startY'],
		]));
		self::assertIsArray($turned['element']);
		self::assertSame([[], [$band]], [$turned['element']['leftFinishes'], $turned['element']['rightFinishes']]);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([[$band], []], [$this->sceneList('walls')[0]['leftFinishes'], $this->sceneList('walls')[0]['rightFinishes']]);

		// Null gives the face back to its room; undo brings the bands back.
		$this->edit('PATCH', 'walls', self::id($wall), ['leftFinishes' => null]);
		self::assertNull($this->sceneList('walls')[0]['leftFinishes']);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([[$band], []], [$this->sceneList('walls')[0]['leftFinishes'], $this->sceneList('walls')[0]['rightFinishes']]);
	}

	public function testCurvedWalls(): void
	{
		// Chord 4000, sagitta 1000: a radius of 2500 and 4636 mm along the arc.
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 4000, 'endY' => 0, 'sagitta' => 1000]);
		self::assertSame(1000, $wall['sagitta']);
		self::assertSame(1000, $this->sceneList('walls')[0]['sagitta']);

		// Openings run along the arc, past the chord's length; built straight, they must stay shallow (707 mm at most here).
		$window = $this->create('openings', ['wallId' => self::id($wall), 'type' => 'Window', 'offset' => 3800, 'width' => 700]);
		$wide = $this->edit('POST', 'openings', body: ['wallId' => self::id($wall), 'type' => 'Window', 'offset' => 500, 'width' => 900]);
		self::assertSame(422, $wide->getStatusCode());
		self::assertStringContainsString('707 mm', (string) $wide->getBody());
		self::assertSame(
			422,
			$this->edit(
				'POST',
				'openings',
				body: ['wallId' => self::id($wall), 'type' => 'Window', 'offset' => 4000, 'width' => 700],
			)->getStatusCode(),
		);
		// Niches follow the curve.
		$this->create(
			'openings',
			['wallId' => self::id($wall), 'type' => 'Niche', 'offset' => 500, 'width' => 1500, 'sillHeight' => 500, 'height' => 1000],
		);

		foreach ([
			['sagitta' => 2001],
			['sagitta' => 'round'],
			// A tighter curve would make the window too deep for it.
			['sagitta' => 1900],
			// Straight, the wall is too short for the window.
			['sagitta' => 0],
		] as $body) {
			self::assertSame(
				422,
				$this->edit('PATCH', 'walls', self::id($wall), $body)->getStatusCode(),
				json_encode($body, JSON_THROW_ON_ERROR),
			);
		}
		$tight = $this->edit(
			'POST',
			'walls',
			body: ['startX' => 0, 'startY' => 2000, 'endX' => 300, 'endY' => 2000, 'sagitta' => 150, 'thickness' => 200],
		);
		self::assertSame(422, $tight->getStatusCode());
		self::assertStringContainsString('100 mm round', (string) $tight->getBody());

		// Turned round, the wall keeps its curve on the same side of the plan; undo turns it back.
		$turned = $this->jsonBody($this->edit('PATCH', 'walls', self::id($wall), ['startX' => 4000, 'endX' => 0]));
		self::assertIsArray($turned['element']);
		self::assertSame(-1000, $turned['element']['sagitta']);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([1000, 3800], [$this->sceneList('walls')[0]['sagitta'], $this->openingOffset(self::id($window))]);
	}

	public function testRoomCeilingsVaultsAndSkirting(): void
	{
		$materials = Fixture::service(MaterialRepository::class);
		$brick = $materials->findByCode('kitchen-oak');
		self::assertNotNull($brick);
		$polygon = [[0, 0], [4000, 0], [4000, 3000], [0, 3000]];

		$room = $this->create('rooms', ['name' => 'Living', 'polygon' => $polygon]);
		self::assertSame([null, null, null], [$room['ceilingMaterialId'], $room['vaultSpacing'], $room['skirtingHeight']]);

		$vaulted = $this->jsonBody($this->edit('PATCH', 'rooms', self::id($room), [
			'ceilingHeight' => 2920,
			'ceilingMaterialId' => $brick->id,
			'vaultSpacing' => 550,
			'vaultAngle' => 270,
			'skirtingHeight' => 70,
		]));
		self::assertIsArray($vaulted['element']);
		$element = $vaulted['element'];
		self::assertSame(
			[$brick->id, 550, 90, 130, 80, 30, null, 70, null],
			[
				$element['ceilingMaterialId'],
				$element['vaultSpacing'],
				$element['vaultAngle'],
				$element['vaultRise'],
				$element['beamWidth'],
				$element['beamDrop'],
				$element['beamMaterialId'],
				$element['skirtingHeight'],
				$element['skirtingMaterialId'],
			],
		);

		// Beams must fit between each other, and leave head room under them.
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['beamWidth' => 600])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['vaultRise' => 600, 'beamDrop' => 600])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['vaultSpacing' => 100])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['skirtingHeight' => 5])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['ceilingMaterialId' => 999999])->getStatusCode());

		// An open-air room has no ceiling to vault; skirting stays.
		$terrace = $this->jsonBody($this->edit('PATCH', 'rooms', self::id($room), ['ceilingHeight' => null]));
		self::assertIsArray($terrace['element']);
		self::assertSame(
			[null, null, null, 70],
			[$terrace['element']['ceilingMaterialId'], $terrace['element']['vaultSpacing'], $terrace['element']['beamMaterialId'], $terrace['element']['skirtingHeight']],
		);
		$this->undo();
		$restored = array_values(
			array_filter($this->sceneList('rooms'), static fn (array $r): bool => $r['id'] === $room['id']),
		)[0] ?? null;
		self::assertIsArray($restored);
		self::assertSame([550, 90, $brick->id], [$restored['vaultSpacing'], $restored['vaultAngle'], $restored['ceilingMaterialId']]);
	}

	public function testRoomPolygonIsValidated(): void
	{
		self::assertSame(422, $this->edit('POST', 'rooms', body: ['polygon' => [[0, 0], [1000, 0]]])->getStatusCode());
		self::assertSame(
			422,
			$this->edit('POST', 'rooms', body: ['polygon' => [[0, 0], [1000, 1000], [1000, 0], [0, 1000]]])->getStatusCode(),
		);
		self::assertSame(422, $this->edit('POST', 'rooms', body: ['polygon' => [[0, 0], [1000, 0], [2000, 0]]])->getStatusCode());
		self::assertSame(
			422,
			$this->edit('POST', 'rooms', body: ['wallFinishes' => [['materialId' => 1, 'bottom' => 500, 'top' => 100]]])->getStatusCode(),
		);

		$room = $this->create(
			'rooms',
			['name' => 'L', 'code' => 'B_07', 'polygon' => [[0, 0], [3000, 0], [3000, 1000], [1000, 1000], [1000, 2000], [0, 2000]]],
		);
		self::assertEquals(4, $room['area']);
		self::assertSame([null, null], [$room['tileOriginX'], $room['tileOriginY']]);

		$changed = $this->jsonBody($this->edit('PATCH', 'rooms', self::id($room), ['tileOriginX' => 139, 'tileOriginY' => -251]));
		self::assertIsArray($changed['element']);
		self::assertSame([139, -251], [$changed['element']['tileOriginX'], $changed['element']['tileOriginY']]);
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['tileOriginX' => 1.5])->getStatusCode());
	}

	public function testRoomFloorAngleIsFoldedIntoHalfATurn(): void
	{
		$room = $this->create('rooms', ['name' => 'Living']);
		self::assertSame(0, $room['floorAngle']);

		foreach ([[105, 105], [195, 15], [-75, 105], [180, 0]] as [$given, $stored]) {
			$changed = $this->jsonBody($this->edit('PATCH', 'rooms', self::id($room), ['floorAngle' => $given]));
			self::assertIsArray($changed['element']);
			self::assertSame($stored, $changed['element']['floorAngle']);
		}
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['floorAngle' => 400])->getStatusCode());

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([105], array_column($this->sceneList('rooms'), 'floorAngle'));
	}

	public function testDeletingRoomUnlinksItsItemsInTheScene(): void
	{
		$room = $this->create('rooms', ['name' => 'Kitchen']);
		$this->create('items', ['name' => 'Base', 'roomId' => self::id($room)]);

		$this->edit('DELETE', 'rooms', self::id($room));

		self::assertSame([null], array_column($this->sceneList('items'), 'roomId'));
	}

	public function testDuplicateItem(): void
	{
		$item = $this->create('items', ['name' => 'Base 60', 'x' => 300, 'y' => 200]);

		$copy = $this->edit('POST', 'items', self::id($item), suffix: '/duplicate');
		$custom = $this->edit('POST', 'items', self::id($item), ['x' => 5000, 'name' => 'Far'], suffix: '/duplicate');

		self::assertSame(201, $copy->getStatusCode());
		$items = $this->sceneList('items');
		self::assertSame([300, 400, 5000], array_column($items, 'x'));
		self::assertSame(['Base 60', 'Base 60', 'Far'], array_column($items, 'name'));
		self::assertSame(201, $custom->getStatusCode());
	}

	public function testOtherUsersCannotEdit(): void
	{
		$item = $this->create('items', []);
		$other = Fixture::createUser();

		self::assertSame(404, $this->edit('PATCH', 'items', self::id($item), ['x' => 5], $other)->getStatusCode());
		self::assertSame(404, $this->edit('POST', 'items', body: [], as: $other)->getStatusCode());
	}

	public function testDeletedElementsAreNotInVersions(): void
	{
		$keep = $this->create('items', ['name' => 'Keep']);
		$gone = $this->create('items', ['name' => 'Gone']);
		$this->edit('DELETE', 'items', self::id($gone));

		$version = $this->jsonBody($this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user));
		self::assertIsInt($version['id']);
		$scene = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $version['id'], $this->floor->id),
			authenticatedAs: $this->user,
		));

		self::assertIsList($scene['items']);
		self::assertSame(['Keep'], array_column($scene['items'], 'name'));
		self::assertSame(self::id($keep), self::id($scene['items'][0]));
	}

	private function openingOffset(int $openingId): int
	{
		foreach ($this->sceneList('walls') as $wall) {
			self::assertIsArray($wall['openings']);
			foreach ($wall['openings'] as $opening) {
				self::assertIsArray($opening);
				if ($opening['id'] === $openingId) {
					self::assertIsInt($opening['offset']);

					return $opening['offset'];
				}
			}
		}
		self::fail(sprintf('Opening %d not in the scene.', $openingId));
	}
}

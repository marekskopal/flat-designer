<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ElementController;
use FlatDesigner\Service\Editor\CabinetParamsValidator;
use FlatDesigner\Service\Editor\Element\ItemHandler;
use FlatDesigner\Service\Editor\Element\OpeningHandler;
use FlatDesigner\Service\Editor\Element\RoomHandler;
use FlatDesigner\Service\Editor\Element\WallHandler;
use FlatDesigner\Service\Editor\PolygonValidator;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Editor\WardrobeParamsValidator;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ElementController::class)]
#[CoversClass(SceneEditor::class)]
#[CoversClass(ItemHandler::class)]
#[CoversClass(WallHandler::class)]
#[CoversClass(OpeningHandler::class)]
#[CoversClass(RoomHandler::class)]
#[CoversClass(PolygonValidator::class)]
#[CoversClass(CabinetParamsValidator::class)]
#[CoversClass(WardrobeParamsValidator::class)]
final class ElementControllerTest extends EditorTestCase
{
	public function testCreateItemFillsDefaultsLogsAndBumpsRevision(): void
	{
		$revision = $this->revision();

		$response = $this->edit('POST', 'items', body: ['kind' => 'BaseCabinet', 'name' => 'Base 60', 'x' => 300, 'rotation' => -90]);

		self::assertSame(201, $response->getStatusCode());
		$result = $this->jsonBody($response);
		self::assertSame($revision + 1, $result['revision']);
		self::assertIsArray($result['element']);
		self::assertSame('BaseCabinet', $result['element']['kind']);
		self::assertSame(270, $result['element']['rotation']);
		self::assertSame(600, $result['element']['width']);
		self::assertIsArray($result['change']);
		self::assertSame('Added item "Base 60"', $result['change']['summary']);
		self::assertSame('Jane', $result['change']['actorName']);
		self::assertSame(['Base 60'], array_column($this->sceneList('items'), 'name'));
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

	public function testCabinetParamsAreValidated(): void
	{
		$params = ['plinth' => 100, 'handle' => 'vertical', 'fronts' => [['type' => 'drawer', 'height' => 180], ['type' => 'door', 'count' => 2]]];
		$cabinet = $this->create('items', ['kind' => 'BaseCabinet', 'params' => $params + ['custom' => 'kept']]);
		self::assertSame($params + ['custom' => 'kept'], $cabinet['params']);

		foreach ([
			['plinth' => -1],
			['plinth' => 860],
			['handle' => 'knob'],
			['fronts' => ['type' => 'door']],
			['fronts' => [['type' => 'shelf']]],
			['fronts' => [['type' => 'door', 'height' => 0]]],
			['fronts' => [['type' => 'door', 'count' => 5]]],
			['blindRight' => 600],
		] as $invalid) {
			$response = $this->edit('PATCH', 'items', self::id($cabinet), ['params' => $invalid]);
			self::assertSame(422, $response->getStatusCode(), (string) json_encode($invalid));
		}

		// Other kinds keep free-form params.
		$box = $this->create('items', ['kind' => 'Box', 'params' => ['handle' => 'knob']]);
		self::assertSame(['handle' => 'knob'], $box['params']);
	}

	public function testWardrobeParamsAreValidated(): void
	{
		$wardrobe = $this->create('items', ['kind' => 'Wardrobe', 'name' => 'Wardrobe']);
		self::assertSame([2400, 600, 2400], [$wardrobe['width'], $wardrobe['depth'], $wardrobe['height']]);

		$params = ['doors' => 4, 'mirrorDoors' => [3], 'plinth' => 60, 'custom' => 'kept'];
		$changed = $this->jsonBody($this->edit('PATCH', 'items', self::id($wardrobe), ['params' => $params]));
		self::assertIsArray($changed['element']);
		self::assertSame($params, $changed['element']['params']);

		foreach ([
			['doors' => 1],
			['doors' => 7],
			['doors' => '4'],
			['plinth' => 2400],
			['mirrorDoors' => 3],
			['mirrorDoors' => [0]],
			['doors' => 3, 'mirrorDoors' => [4]],
			['mirrorDoors' => [2, 2]],
			['profileMaterialId' => 'aluminium'],
			['profileMaterialId' => 999999],
		] as $invalid) {
			$response = $this->edit('PATCH', 'items', self::id($wardrobe), ['params' => $invalid]);
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

	public function testDeletingWallHidesItsOpenings(): void
	{
		$wall = $this->create('walls', []);
		$this->create('openings', ['wallId' => self::id($wall)]);

		$this->edit('DELETE', 'walls', self::id($wall));

		self::assertSame([], $this->sceneList('walls'));
		self::assertSame(422, $this->edit('POST', 'openings', body: ['wallId' => self::id($wall)])->getStatusCode());
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

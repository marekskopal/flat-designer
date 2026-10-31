<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\RoofDetails;
use FlatDesigner\Service\Editor\ItemPlacement;
use FlatDesigner\Service\Editor\StructureParamsValidator;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Service\Project\SnapshotScenes;
use FlatDesigner\Service\Provider\SceneProvider;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

/** Chimneys, items on the roof and a roof's gutters, downpipes, snow guards and ridge (FD-91). */
#[CoversClass(RoofDetails::class)]
#[CoversClass(StructureParamsValidator::class)]
#[CoversClass(ItemPlacement::class)]
#[CoversClass(SceneProvider::class)]
#[CoversClass(SnapshotScenes::class)]
final class RoofDetailsTest extends EditorTestCase
{
	private const array Gable = ['shape' => 'Gable', 'x' => 4000, 'y' => 3000, 'width' => 8000, 'depth' => 6000, 'eaveHeight' => 2600];

	public function testARoofTakesGuttersDownpipesSnowGuardsAndARidge(): void
	{
		$zinc = Fixture::service(MaterialRepository::class)->findByCode('zinc-titanium');
		self::assertNotNull($zinc);
		$roof = $this->create('roofs', self::Gable);
		self::assertSame(['None', null, null, 0, 'Box'], self::details($roof));

		$downpipes = [['edge' => 'Front', 'along' => -3800], ['edge' => 'Back', 'along' => 0]];
		$this->edit('PATCH', 'roofs', self::id($roof), [
			'gutters' => 'HalfRound',
			'gutterMaterialId' => $zinc->id,
			'downpipes' => $downpipes,
			'snowGuards' => 2,
			'ridgeStyle' => 'Round',
		]);
		self::assertSame([['HalfRound', $zinc->id, $downpipes, 2, 'Round']], array_map(self::details(...), $this->sceneList('roofs')));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([['None', null, null, 0, 'Box']], array_map(self::details(...), $this->sceneList('roofs')));
	}

	public function testRejectsDetailsTheRoofCannotHave(): void
	{
		$roof = self::id($this->create('roofs', self::Gable));
		$rejected = [
			['gutters' => 'Ogee'],
			['snowGuards' => 6],
			// A gable has no eaves along its sides; the front is 8 m long.
			['downpipes' => [['edge' => 'Left', 'along' => 0]]],
			['downpipes' => [['edge' => 'Front', 'along' => 4100]]],
			['downpipes' => [['edge' => 'Front']]],
			['ridgeStyle' => 'Crested'],
		];
		foreach ($rejected as $fields) {
			self::assertSame(
				422,
				$this->edit('PATCH', 'roofs', $roof, $fields)->getStatusCode(),
				json_encode($fields, JSON_THROW_ON_ERROR),
			);
		}
		self::assertSame(422, $this->edit('POST', 'roofs', null, ['shape' => 'Flat', 'gutters' => 'Box'])->getStatusCode());

		// A pitched roof made flat leaves its gutters and snow guards behind.
		$this->edit('PATCH', 'roofs', $roof, ['gutters' => 'Box', 'snowGuards' => 1]);
		self::assertSame(200, $this->edit('PATCH', 'roofs', $roof, ['shape' => 'Flat'])->getStatusCode());
		self::assertSame([['Flat', 'None', 0]], array_map(
			static fn (array $r): array => [$r['shape'], $r['gutters'], $r['snowGuards']],
			$this->sceneList('roofs'),
		));
	}

	public function testAChimneyStandsOnItsRoomsFloorAndChecksItsParams(): void
	{
		$room = $this->create(
			'rooms',
			['polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'ceilingHeight' => 2600, 'floorElevation' => 150],
		);
		$chimney = $this->create('items', ['kind' => 'Chimney', 'x' => 2000, 'y' => 1500, 'roomId' => self::id($room)]);
		self::assertSame(['Chimney', 150, 450, 3000], [$chimney['name'], $chimney['z'], $chimney['width'], $chimney['height']]);

		$id = self::id($chimney);
		self::assertSame(
			200,
			$this->edit('PATCH', 'items', $id, ['params' => ['shape' => 'round', 'aboveRoof' => 800, 'cap' => false]])->getStatusCode(),
		);
		foreach ([['aboveRoof' => -1], ['aboveRoof' => 'high'], ['cap' => 'yes'], ['shape' => 'oval']] as $params) {
			self::assertSame(422, $this->edit('PATCH', 'items', $id, ['params' => $params])->getStatusCode());
		}
	}

	public function testAnItemLiesOnTheRoofOrStandsOnTheGround(): void
	{
		$panel = $this->create('items', ['kind' => 'Box', 'x' => 2000, 'y' => 1000, 'onRoof' => true]);
		self::assertTrue($panel['onRoof']);
		self::assertSame(422, $this->edit('PATCH', 'items', self::id($panel), ['onGround' => true])->getStatusCode());
		self::assertSame(200, $this->edit('PATCH', 'items', self::id($panel), ['onRoof' => false, 'onGround' => true])->getStatusCode());
		// A chimney stands on its floor.
		self::assertSame(
			422,
			$this->edit('POST', 'items', null, ['kind' => 'Chimney', 'x' => 1000, 'y' => 1000, 'onRoof' => true])->getStatusCode(),
		);
	}

	/** The scenes see the other floors' roofs and chimneys, levels against their own; a version's scenes alike. */
	public function testScenesCarryTheChimneyFloors(): void
	{
		self::assertSame([], $this->scene()['chimneyFloors']);
		$attic = Fixture::service(FloorProvider::class)->create($this->project, new FloorInputDto('Attic', 1, 2900));
		$this->request('POST', Fixture::elementsPath($this->project, $attic->id, 'roofs'), self::Gable, $this->user);
		// Without a chimney the project's scenes carry none.
		self::assertSame([], $this->scene()['chimneyFloors']);
		$this->create('items', ['kind' => 'Chimney', 'x' => 4000, 'y' => 3000, 'params' => ['aboveRoof' => 600]]);

		$floors = self::list($this->scene()['chimneyFloors']);
		self::assertSame([[$attic->id, 2900, 1, 0]], array_map(self::chimneyFloor(...), $floors));
		$above = $this->jsonBody(
			$this->request(
				'GET',
				sprintf('/api/projects/%d/floors/%d/scene', $this->project->id, $attic->id),
				authenticatedAs: $this->user,
			),
		);
		self::assertSame([[$this->floor->id, -2900, 0, 1]], array_map(self::chimneyFloor(...), self::list($above['chimneyFloors'])));

		$versionId = $this->jsonBody(
			$this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user),
		)['id'];
		self::assertIsInt($versionId);
		$version = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $versionId, $attic->id),
			authenticatedAs: $this->user,
		));
		$floors = self::list($version['chimneyFloors']);
		self::assertSame([[-2900, 0, 1]], array_map(static fn (array $f): array => array_slice(self::chimneyFloor($f), 1), $floors));
		self::assertIsList($floors[0]['chimneys']);
		self::assertIsArray($floors[0]['chimneys'][0]);
		self::assertSame(['aboveRoof' => 600], $floors[0]['chimneys'][0]['params']);
	}

	/** @return list<array<mixed>> */
	private static function list(mixed $value): array
	{
		self::assertIsList($value);
		$rows = [];
		foreach ($value as $row) {
			self::assertIsArray($row);
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @param array<mixed> $roof
	 * @return list<mixed>
	 */
	private static function details(array $roof): array
	{
		return [$roof['gutters'], $roof['gutterMaterialId'], $roof['downpipes'], $roof['snowGuards'], $roof['ridgeStyle']];
	}

	/**
	 * @param array<mixed> $floor
	 * @return list<mixed> floor id, offset, roofs, chimneys
	 */
	private static function chimneyFloor(array $floor): array
	{
		self::assertIsList($floor['roofs']);
		self::assertIsList($floor['chimneys']);

		return [$floor['floorId'], $floor['offset'], count($floor['roofs']), count($floor['chimneys'])];
	}
}

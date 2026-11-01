<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\PlotHandler;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PlotHandler::class)]
final class PlotTest extends EditorTestCase
{
	private const array Boundary = [[-2000, -3000], [12000, -3000], [12000, 9000], [-2000, 9000]];

	public function testAddChangeDeleteAndUndo(): void
	{
		$gravel = Fixture::service(MaterialRepository::class)->findAllOrdered()[0] ?? null;
		self::assertNotNull($gravel);

		$plot = $this->create('plots', ['polygon' => self::Boundary]);
		// JSON writes the whole 168 m² without a decimal point.
		self::assertEquals(
			['Plot', self::Boundary, 168.0, null],
			[$plot['name'], $plot['polygon'], $plot['area'], $plot['groundMaterialId']],
		);
		self::assertSame([self::id($plot)], array_column($this->sceneList('plots'), 'id'));

		$changed = $this->jsonBody(
			$this->edit('PATCH', 'plots', self::id($plot), ['name' => 'Parcel 12/3', 'groundMaterialId' => $gravel->id]),
		);
		self::assertIsArray($changed['element']);
		self::assertSame(['Parcel 12/3', $gravel->id], [$changed['element']['name'], $changed['element']['groundMaterialId']]);
		self::assertIsArray($changed['change']);
		self::assertSame('Changed plot "Parcel 12/3" (name, groundMaterialId)', $changed['change']['summary']);

		self::assertSame(200, $this->edit('DELETE', 'plots', self::id($plot))->getStatusCode());
		self::assertSame([], $this->sceneList('plots'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['Parcel 12/3'], array_column($this->sceneList('plots'), 'name'));
	}

	public function testANewPlotWithoutABoundaryStandsClearOfTheFloor(): void
	{
		$empty = $this->create('plots', []);
		self::assertSame([[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]], $empty['polygon']);

		$this->create('rooms', ['name' => 'Living', 'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]]]);
		$this->create('walls', ['startX' => -500, 'startY' => 1500, 'endX' => 4500, 'endY' => 1500, 'sagitta' => 1000]);
		$around = $this->create('plots', []);
		// 3 m round the room and the wall ends, plus the wall's bulge.
		self::assertSame([[-4500, -4000], [8500, -4000], [8500, 7000], [-4500, 7000]], $around['polygon']);
	}

	public function testTheGroundLevelIsKeptAndUndone(): void
	{
		$plot = $this->create('plots', ['polygon' => self::Boundary]);
		self::assertSame(0, $plot['groundLevel']);

		$this->edit('PATCH', 'plots', self::id($plot), ['groundLevel' => -450]);
		self::assertSame([-450], array_column($this->sceneList('plots'), 'groundLevel'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([0], array_column($this->sceneList('plots'), 'groundLevel'));
	}

	public function testSpotHeightsAreKeptAndUndone(): void
	{
		$plot = $this->create('plots', ['polygon' => self::Boundary]);
		self::assertSame([], $plot['terrain']);

		$terrain = [[-1000, 0, 2960], [11000, 0, 0], [11000, 8000, -300]];
		$this->edit('PATCH', 'plots', self::id($plot), ['terrain' => $terrain]);
		self::assertSame([$terrain], array_column($this->sceneList('plots'), 'terrain'));
		$this->edit('PATCH', 'plots', self::id($plot), ['terrain' => []]);
		self::assertSame([[]], array_column($this->sceneList('plots'), 'terrain'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([$terrain], array_column($this->sceneList('plots'), 'terrain'));
	}

	public function testRefusesBadSpotHeights(): void
	{
		$bad = [
			[[0, 0]],
			[[0, 0, 'high']],
			[[0, 0, 10001]],
			[[2000000, 0, 0]],
			// Closer than 10 mm.
			[[0, 0, 0], [5, 9, 100]],
		];
		foreach ($bad as $terrain) {
			self::assertSame(
				422,
				$this->edit('POST', 'plots', body: ['polygon' => self::Boundary, 'terrain' => $terrain])->getStatusCode(),
				json_encode($terrain, JSON_THROW_ON_ERROR),
			);
		}
	}

	public function testAnItemStandsOnTheGround(): void
	{
		$tree = $this->create('items', ['kind' => 'Box', 'x' => 3000, 'y' => 2000, 'onGround' => true]);
		self::assertTrue($tree['onGround']);
		self::assertFalse($this->create('items', ['kind' => 'Box', 'x' => 0, 'y' => 0])['onGround']);

		$this->edit('PATCH', 'items', self::id($tree), ['onGround' => false]);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([true, false], array_column($this->sceneList('items'), 'onGround'));
	}

	public function testARoomFloorLiesAboveOrBelowTheFloorsLevel(): void
	{
		$polygon = [[0, 0], [3000, 0], [3000, 2000], [0, 2000]];
		$terrace = $this->create('rooms', ['name' => 'Terrace', 'type' => 'Terrace', 'polygon' => $polygon, 'floorElevation' => -150]);
		self::assertSame(-150, $terrace['floorElevation']);
		self::assertSame(0, $this->create('rooms', ['name' => 'Hall', 'polygon' => $polygon])['floorElevation']);
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($terrace), ['floorElevation' => 3001])->getStatusCode());
	}

	public function testColumnsAndBeamsReachTheCeilingOfARaisedRoom(): void
	{
		$polygon = [[0, 0], [4000, 0], [4000, 3000], [0, 3000]];
		$deck = $this->create('rooms', ['name' => 'Deck', 'polygon' => $polygon, 'ceilingHeight' => 2700, 'floorElevation' => 450]);

		$column = $this->create('items', ['kind' => 'Column', 'roomId' => self::id($deck), 'x' => 500, 'y' => 500]);
		$beam = $this->create('items', ['kind' => 'Beam', 'roomId' => self::id($deck), 'x' => 2000, 'y' => 1500, 'height' => 200]);

		// From the deck's floor up to its ceiling, 450 + 2700 above the floor's level; the beam's soffit at the ceiling.
		self::assertSame([450, 2700], [$column['z'], $column['height']]);
		self::assertSame(2950, $beam['z']);
	}

	public function testPavingAndAsphaltAreSeeded(): void
	{
		$materials = Fixture::service(MaterialRepository::class);
		$paving = $materials->findByCode('paving-concrete-grey');
		$asphalt = $materials->findByCode('asphalt');

		self::assertNotNull($paving);
		self::assertNotNull($asphalt);
		self::assertSame([200, 100], [$paving->tileWidth, $paving->tileHeight]);
	}

	public function testRefusesABadBoundaryOrMaterial(): void
	{
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => [[0, 0], [1000, 0]]])->getStatusCode());
		self::assertSame(
			422,
			$this->edit('POST', 'plots', body: ['polygon' => [[0, 0], [1000, 1000], [1000, 0], [0, 1000]]])->getStatusCode(),
		);
		self::assertSame(
			422,
			$this->edit('POST', 'plots', body: ['polygon' => self::Boundary, 'groundMaterialId' => 999999])->getStatusCode(),
		);
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => self::Boundary, 'height' => 3])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => self::Boundary, 'groundLevel' => 10001])->getStatusCode());
		// Further than a room may reach, but a plot may: a kilometre out is fine, past it is not.
		$far = static fn (int $reach): array => [[0, 0], [$reach, 0], [$reach, $reach]];
		self::assertSame(201, $this->edit('POST', 'plots', body: ['polygon' => $far(900000)])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => $far(1100000)])->getStatusCode());
	}
}

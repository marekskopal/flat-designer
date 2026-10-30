<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\QuantitiesController;
use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Model\Entity\FloorVoid;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Repository\FloorVoidRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\Element\State;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Service\Quantities\FacadeSurfaces;
use FlatDesigner\Service\Quantities\MaterialPriceService;
use FlatDesigner\Service\Quantities\PriceList;
use FlatDesigner\Service\Quantities\QuantitiesService;
use FlatDesigner\Service\Quantities\RoofSurfaces;
use FlatDesigner\Service\Quantities\RoomFaceUnderRoof;
use FlatDesigner\Service\Quantities\RoomSurfaces;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use const JSON_THROW_ON_ERROR;

#[CoversClass(QuantitiesController::class)]
#[CoversClass(QuantitiesService::class)]
#[CoversClass(RoomSurfaces::class)]
#[CoversClass(RoomFaceUnderRoof::class)]
#[CoversClass(RoofSurfaces::class)]
#[CoversClass(FacadeSurfaces::class)]
#[CoversClass(PriceList::class)]
#[CoversClass(MaterialPriceService::class)]
final class QuantitiesControllerTest extends EditorTestCase
{
	/**
	 * A 4 × 3 m room inside 200 mm walls, 2.5 m to the ceiling: floor tiles 600 × 600, wall tiles up to
	 * 1.2 m, a door (900 × 2000) in the painted south wall, a window (1000 × 1200, sill 900) in the east wall, skirting.
	 */
	public function testCountsTheSurfacesOfARoomAndPricesThem(): void
	{
		$floorTiles = self::material('floor-tiles-taupe');
		$wallTiles = self::material('tile-wall-beige');
		$paint = self::material('wall-paint-white');
		$this->buildRoom($paint);
		$tile = ($wallTiles->tileWidth ?? 0) * ($wallTiles->tileHeight ?? 0) / 1000000;
		$tilesBand = 16.8 - 1.08 - 0.3;
		$wallPieces = (int) ceil($tilesBand * 1.1 / $tile - 1e-6);

		$quantities = $this->quantities();
		self::assertSame('EUR', $quantities['currency']);
		self::assertSame(10, $quantities['wastePercent']);
		self::assertFalse($quantities['complete']);
		self::assertEqualsWithDelta(0.0, $quantities['total'], 0.001);

		$rooms = self::rows($quantities['rooms']);
		self::assertCount(1, $rooms);
		$lines = [];
		foreach (self::rows($rooms[0]['lines']) as $line) {
			$materialId = $line['materialId'];
			$lines[self::stringField($line['surface']) . ':' . (is_int($materialId) ? $materialId : '-')] = $line;
		}
		self::assertSame(
			['Floor:' . $floorTiles->id, 'WallFinish:' . $wallTiles->id, 'Wall:-', 'Wall:' . $paint->id, 'Ceiling:-', 'Skirting:-'],
			array_keys($lines),
		);
		self::assertEqualsWithDelta(12.0, $lines['Floor:' . $floorTiles->id]['area'], 0.001);
		// 12 m² + 10 % in 0.36 m² tiles.
		self::assertSame(37, $lines['Floor:' . $floorTiles->id]['pieces']);
		// Up to 1.2 m around 14 m, without the door (0.9 × 1.2) and the window's bottom 0.3 m.
		self::assertEqualsWithDelta($tilesBand, $lines['WallFinish:' . $wallTiles->id]['area'], 0.001);
		self::assertSame($wallPieces, $lines['WallFinish:' . $wallTiles->id]['pieces']);
		// From 1.2 to 2.5 m: the south wall without the door's top 0.8 m; the rest without the window's top 0.9 m.
		self::assertEqualsWithDelta(4 * 1.3 - 0.9 * 0.8, $lines['Wall:' . $paint->id]['area'], 0.001);
		self::assertNull($lines['Wall:' . $paint->id]['pieces']);
		self::assertEqualsWithDelta(10 * 1.3 - 0.9, $lines['Wall:-']['area'], 0.001);
		self::assertSame('Wall paint (default)', $lines['Wall:-']['material']);
		self::assertEqualsWithDelta(12.0, $lines['Ceiling:-']['area'], 0.001);
		self::assertSame('White plaster', $lines['Ceiling:-']['material']);
		self::assertEqualsWithDelta(14 - 0.9, $lines['Skirting:-']['length'], 0.001);
		self::assertNull($lines['Skirting:-']['area']);

		$response = $this->putPrices([
			'currency' => 'czk',
			'wastePercent' => 10,
			'prices' => [
				['materialId' => $floorTiles->id, 'pricePerArea' => 30],
				['materialId' => $wallTiles->id, 'pricePerArea' => 40],
				['materialId' => $paint->id, 'pricePerArea' => 3],
				['surface' => 'Wall', 'pricePerArea' => 2],
				['surface' => 'Ceiling', 'pricePerArea' => 4],
				['surface' => 'Skirting', 'pricePerArea' => null, 'pricePerLength' => null],
			],
		]);
		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		$prices = $this->jsonBody($response);
		self::assertSame('CZK', $prices['currency']);
		// The empty one is dropped.
		self::assertCount(5, self::rows($prices['prices']));
		self::assertSame(
			[['type' => 'pricesChanged', 'projectId' => $this->project->id]],
			array_map(
				static fn (array $m): array => ['type' => $m['type'], 'projectId' => $m['projectId']],
				array_values(
					array_filter(AppHarness::mercureHub()->messages(), static fn (array $m): bool => $m['type'] === 'pricesChanged'),
				),
			),
		);

		// Tiles are bought in whole pieces. The painted south wall's outer face is paint too: a façade of 4.4 × 2.6 m
		// (mitred at the corners) less the door.
		$priced = 37 * 0.36 * 30 + $wallPieces * $tile * 40 + (4.48 + 4.4 * 2.6 - 1.8) * 3 + 12.1 * 2 + 12 * 4;
		$quantities = $this->quantities();
		self::assertFalse($quantities['complete']);
		self::assertEqualsWithDelta($priced, $quantities['total'], 0.01);

		$this->putPrices([
			'currency' => 'CZK',
			'wastePercent' => 10,
			'prices' => [
				...self::rows($prices['prices']),
				['surface' => 'Skirting', 'pricePerLength' => 5],
				['surface' => 'Facade', 'pricePerArea' => 1],
			],
		]);
		$quantities = $this->quantities();
		self::assertTrue($quantities['complete']);
		// The other three outer faces are the default façade, less the window.
		$facade = (4.4 + 3.4 + 3.4) * 2.6 - 1.2;
		self::assertEqualsWithDelta($priced + 13.1 * 5 + $facade, $quantities['total'], 0.01);
		// The room's own cost leaves out the façades (the south wall's painted outer face).
		self::assertEqualsWithDelta($priced - (4.4 * 2.6 - 1.8) * 3 + 13.1 * 5, self::rows($quantities['rooms'])[0]['cost'], 0.01);
		$materials = self::rows($quantities['materials']);
		$tiles = array_values(array_filter($materials, static fn (array $m): bool => $m['materialId'] === $wallTiles->id))[0];
		self::assertSame($wallPieces, $tiles['pieces']);
		self::assertEqualsWithDelta($wallPieces * $tile, $tiles['orderedArea'], 0.01);
		self::assertEquals(40, $tiles['pricePerArea']);

		// The floor's quantities are the same here; another user gets nothing.
		self::assertEquals(
			$quantities,
			$this->jsonBody($this->request('GET', $this->path(Routes::ProjectFloorQuantities), authenticatedAs: $this->user)),
		);
		$stranger = Fixture::createUser();
		self::assertSame(404, $this->request('GET', $this->path(Routes::ProjectQuantities), authenticatedAs: $stranger)->getStatusCode());
		self::assertSame(
			404,
			$this->request('GET', $this->path(Routes::ProjectMaterialPrices), authenticatedAs: $stranger)->getStatusCode(),
		);
	}

	public function testAVaultedCeilingCountsItsArchesAndBeams(): void
	{
		$this->buildRoom(null, ['vaultSpacing' => 1000, 'vaultRise' => 130, 'beamWidth' => 80, 'beamDrop' => 30, 'wallFinishes' => []]);

		$lines = [];
		foreach (self::rows(self::rows($this->quantities()['rooms'])[0]['lines']) as $line) {
			$lines[self::stringField($line['surface'])] = $line;
		}

		// The arch over 920 mm is longer than its span; the beams' underside and sides take 140 mm of every metre.
		$arch = RoomSurfaces::arcLength(920, 130);
		self::assertGreaterThan(920, $arch);
		self::assertEqualsWithDelta(12 * $arch / 1000, $lines['Ceiling']['area'], 0.01);
		self::assertEqualsWithDelta(12 * 0.14, $lines['Beams']['area'], 0.01);
	}

	/** A void through the floor (FD-90) takes its area off the flooring, one through the floor above off the ceiling. */
	public function testFloorVoidsAreLeftOutOfTheFloorAndTheCeiling(): void
	{
		$this->buildRoom(null);
		$this->create('voids', ['polygon' => [[1000, 1000], [2000, 1000], [2000, 2000], [1000, 2000]]]);
		$upper = Fixture::service(FloorProvider::class)->create($this->project, new FloorInputDto('First floor', 1, 3000));
		Fixture::service(FloorVoidRepository::class)->persist(State::stamp(
			new FloorVoid(floor: $upper, name: 'Well', polygon: '[[3000,0],[5000,0],[5000,2000],[3000,2000]]'),
		));

		$lines = $this->lines();
		self::assertEqualsWithDelta(11.0, $lines['Floor:' . self::material('floor-tiles-taupe')->id]['area'], 0.01);
		// Half the well lies over the room.
		self::assertEqualsWithDelta(10.0, $lines['Ceiling:-']['area'], 0.01);
	}

	/** The room of the first test, with terrazzo up to 2.1 m on the south wall's face in place of the wall tiles. */

	/** The room of the first test, with terrazzo up to 2.1 m on the south wall's face in place of the wall tiles. */

	/**
	 * An attic under a 45° gable from 1 m at its eaves (over the room's long edges): the ceiling slopes within 1.5 m of
	 * them, the walls are painted up to it, and the roof counts its covering and soffit along the slope.
	 */
	public function testAnAtticFollowsItsRoof(): void
	{
		foreach ([[[-100, -100], [10100, -100]], [[10100, -100], [10100, 8100]], [[10100, 8100], [-100, 8100]], [[-100, 8100], [-100, -100]]] as [$a, $b]) {
			$this->create(
				'walls',
				['startX' => $a[0], 'startY' => $a[1], 'endX' => $b[0], 'endY' => $b[1], 'thickness' => 200, 'height' => 2600],
			);
		}
		$this->create('rooms', ['name' => 'Attic', 'polygon' => [[0, 0], [10000, 0], [10000, 8000], [0, 8000]], 'ceilingHeight' => 2500]);
		$roof = $this->create('roofs', [
			'shape' => 'Gable',
			'x' => 5000,
			'y' => 4000,
			'width' => 10000,
			'depth' => 8000,
			'eaveHeight' => 1000,
			'pitch' => 45,
			'overhang' => 0,
			'vergeOverhang' => 0,
		]);
		$this->create('roof-windows', ['roofId' => self::id($roof), 'along' => 0, 'across' => -2000]);

		$lines = $this->lines();
		// 50 m² flat between the slopes, and 2 × 15 m² of plan under them, √2 times as large along them.
		self::assertEqualsWithDelta(50 + 30 * sqrt(2), $lines['Ceiling:-']['area'], 0.01);
		// The eaves walls up to 1 m; the gables up to 1 m at the eaves, 2.5 m between 1.5 m from them.
		self::assertEqualsWithDelta(2 * 10 + 2 * (2 * 1.5 * 1.75 + 5 * 2.5), $lines['Wall:-']['area'], 0.01);

		$quantities = $this->quantities();
		$roofs = self::rows($quantities['roofs']);
		self::assertCount(1, $roofs);
		self::assertSame(1, $roofs[0]['windows']);
		$roofLines = [];
		foreach (self::rows($roofs[0]['lines']) as $line) {
			$roofLines[self::stringField($line['surface'])] = $line;
		}
		// 10 × 8 m in plan, √2 times along the slope, less the 0.78 × 1.18 m window; clay tiles by default.
		$slope = 80 * sqrt(2) - 0.78 * 1.18;
		self::assertEqualsWithDelta($slope, $roofLines['RoofCovering']['area'], 0.01);
		self::assertSame(self::material('roof-tiles-clay')->id, $roofLines['RoofCovering']['materialId']);
		self::assertEqualsWithDelta($slope, $roofLines['Soffit']['area'], 0.01);
		self::assertNull($roofLines['Soffit']['materialId']);
		self::assertContains('Roof tiles, red clay (300 × 420)', array_column(self::rows($quantities['materials']), 'material'));
	}

	public function testAWallFaceTakesItsOwnFinish(): void
	{
		$terrazzo = self::material('terrazzo-white');
		$wallTiles = self::material('tile-wall-beige');
		$paint = self::material('wall-paint-white');
		$this->buildRoom($paint, [], ['leftFinishes' => [['materialId' => $terrazzo->id, 'bottom' => 0, 'top' => 2100]]]);

		$lines = $this->lines();
		// 4 m × 2.1 m without the door (0.9 × 2.0).
		self::assertEqualsWithDelta(8.4 - 1.8, $lines['WallFinish:' . $terrazzo->id]['area'], 0.001);
		// The other 10 m up to 1.2 m, without the window's bottom 0.3 m.
		self::assertEqualsWithDelta(12 - 0.3, $lines['WallFinish:' . $wallTiles->id]['area'], 0.001);
		// Paint above the terrazzo, which covers the door's head.
		self::assertEqualsWithDelta(4 * 0.4, $lines['Wall:' . $paint->id]['area'], 0.001);
	}

	public function testAWallFaceWithAnEmptyFinishIsPainted(): void
	{
		$paint = self::material('wall-paint-white');
		$this->buildRoom($paint, [], ['leftFinishes' => []]);

		$lines = $this->lines();
		self::assertEqualsWithDelta(12 - 0.3, $lines['WallFinish:' . self::material('tile-wall-beige')->id]['area'], 0.001);
		self::assertEqualsWithDelta(4 * 2.5 - 0.9 * 2.0, $lines['Wall:' . $paint->id]['area'], 0.001);
	}

	public function testCountsTheFacadesPerMaterialWithoutTheOpenings(): void
	{
		$render = self::material('wall-paint-white');
		$this->buildRoom(null, [], ['rightFinishes' => [['materialId' => $render->id, 'bottom' => 0, 'top' => 2600]]]);

		$facades = self::rows($this->quantities()['facades']);
		self::assertCount(1, $facades);
		$lines = [];
		foreach (self::rows($facades[0]['lines']) as $line) {
			$materialId = $line['materialId'];
			$lines[is_int($materialId) ? $materialId : '-'] = $line;
		}
		// The south wall's outer face, mitred at both corners (4.4 m), less the door (0.9 × 2.0).
		self::assertEqualsWithDelta(4.4 * 2.6 - 1.8, $lines[$render->id]['area'], 0.01);
		self::assertSame('Facade', $lines[$render->id]['surface']);
		// The other three outer faces in the walls' own (default) look, less the window (1.0 × 1.2).
		self::assertEqualsWithDelta((4.4 + 3.4 + 3.4) * 2.6 - 1.2, $lines['-']['area'], 0.01);
		self::assertSame('Wall finish (default)', $lines['-']['material']);
	}

	public function testABareFaceAndOverlappingBandsCountAsTheViewerDrawsThem(): void
	{
		$render = self::material('wall-paint-white');
		$terrazzo = self::material('terrazzo-white');
		$tiles = self::material('tile-wall-beige');
		// The south face is bare ([]); the east wall's outer face has two bands overlapping by 1 m, the first keeping it.
		$this->buildRoom(null, [], ['type' => 'Exterior', 'rightFinishes' => []]);
		$east = array_values(
			array_filter($this->sceneList('walls'), static fn (array $w): bool => $w['startX'] === 4100 && $w['endX'] === 4100),
		)[0];
		$this->edit('PATCH', 'walls', self::id($east), [
			'type' => 'Exterior',
			'rightFinishes' => [
				['materialId' => $terrazzo->id, 'bottom' => 0, 'top' => 2000],
				['materialId' => $tiles->id, 'bottom' => 1000, 'top' => 2600],
			],
		]);
		$this->project->exteriorMaterial = $render;
		Fixture::service(ProjectRepository::class)->persist($this->project);

		$lines = [];
		foreach (self::rows(self::rows($this->quantities()['facades'])[0]['lines']) as $line) {
			$materialId = $line['materialId'];
			$lines[is_int($materialId) ? $materialId : '-'] = $line;
		}
		// The east face is 3.4 m long with the window (1.0 × 1.2, sill 0.9): terrazzo 0–2000 less the window's 0.9–2.0 part,
		// tiles 2000–2600 less its 2.0–2.1 part.
		self::assertEqualsWithDelta(3.4 * 2.0 - 1.0 * 1.1, $lines[$terrazzo->id]['area'], 0.01);
		self::assertEqualsWithDelta(3.4 * 0.6 - 1.0 * 0.1, $lines[$tiles->id]['area'], 0.01);
		// The bare south face (less the door) stays the walls' default look, not the project's render, as do the north
		// and west faces, which are interior walls: no render anywhere.
		self::assertEqualsWithDelta(4.4 * 2.6 - 1.8 + (4.4 + 3.4) * 2.6, $lines['-']['area'], 0.01);
		self::assertArrayNotHasKey($render->id, $lines);
	}

	public function testAnExteriorWallTakesTheProjectsFacade(): void
	{
		$render = self::material('wall-paint-white');
		$this->buildRoom(null, [], ['type' => 'Exterior']);
		$this->project->exteriorMaterial = $render;
		Fixture::service(ProjectRepository::class)->persist($this->project);

		$lines = [];
		foreach (self::rows(self::rows($this->quantities()['facades'])[0]['lines']) as $line) {
			$materialId = $line['materialId'];
			$lines[is_int($materialId) ? $materialId : '-'] = $line;
		}
		self::assertEqualsWithDelta(4.4 * 2.6 - 1.8, $lines[$render->id]['area'], 0.01);
		self::assertEqualsWithDelta((4.4 + 3.4 + 3.4) * 2.6 - 1.2, $lines['-']['area'], 0.01);
	}

	public function testRejectsAWrongPriceList(): void
	{
		$paint = self::material('wall-paint-white');
		foreach ([
			['currency' => 'EURO', 'wastePercent' => 10, 'prices' => []],
			['currency' => 'EUR', 'wastePercent' => 150, 'prices' => []],
			['currency' => 'EUR', 'wastePercent' => 10, 'prices' => [['materialId' => $paint->id, 'pricePerArea' => -1]]],
			['currency' => 'EUR', 'wastePercent' => 10, 'prices' => [['materialId' => $paint->id, 'surface' => 'Wall', 'pricePerArea' => 1]]],
			['currency' => 'EUR', 'wastePercent' => 10, 'prices' => [['pricePerArea' => 1]]],
			['currency' => 'EUR', 'wastePercent' => 10, 'prices' => [['materialId' => 999999, 'pricePerArea' => 1]]],
			[
				'currency' => 'EUR',
				'wastePercent' => 10,
				'prices' => [
					['materialId' => $paint->id, 'pricePerArea' => 1],
					['materialId' => $paint->id, 'pricePerArea' => 2],
				]],
		] as $body) {
			self::assertSame(422, $this->putPrices($body)->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
		}
		self::assertSame(
			400,
			$this->putPrices(['currency' => 'EUR', 'wastePercent' => 10, 'prices' => [['surface' => 'Roof']]])->getStatusCode(),
		);

		$prices = $this->jsonBody($this->request('GET', $this->path(Routes::ProjectMaterialPrices), authenticatedAs: $this->user));
		self::assertSame(['currency' => 'EUR', 'wastePercent' => 10, 'prices' => []], $prices);
	}

	/**
	 * @param array<string, mixed> $room extra room fields
	 * @param array<string, mixed> $south extra fields of the south wall (its left face is the room's)
	 */
	private function buildRoom(?Material $southPaint, array $room = [], array $south = []): void
	{
		$south = $this->create('walls', $south + [
			'startX' => -100,
			'startY' => -100,
			'endX' => 4100,
			'endY' => -100,
			'thickness' => 200,
			'height' => 2600,
			'materialId' => $southPaint?->id,
		]);
		$east = $this->create(
			'walls',
			['startX' => 4100, 'startY' => -100, 'endX' => 4100, 'endY' => 3100, 'thickness' => 200, 'height' => 2600],
		);
		$this->create('walls', ['startX' => 4100, 'startY' => 3100, 'endX' => -100, 'endY' => 3100, 'thickness' => 200, 'height' => 2600]);
		$this->create('walls', ['startX' => -100, 'startY' => 3100, 'endX' => -100, 'endY' => -100, 'thickness' => 200, 'height' => 2600]);
		$this->create(
			'openings',
			['wallId' => self::id($south), 'type' => 'Door', 'offset' => 500, 'width' => 900, 'height' => 2000, 'sillHeight' => 0],
		);
		$this->create(
			'openings',
			['wallId' => self::id($east), 'type' => 'Window', 'offset' => 1100, 'width' => 1000, 'height' => 1200, 'sillHeight' => 900],
		);
		$this->create('rooms', $room + [
			'name' => 'Bathroom',
			'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]],
			'ceilingHeight' => 2500,
			'floorMaterialId' => self::material('floor-tiles-taupe')->id,
			'wallFinishes' => [['materialId' => self::material('tile-wall-beige')->id, 'bottom' => 0, 'top' => 1200]],
			'skirtingHeight' => 80,
		]);
	}

	private static function material(string $code): Material
	{
		return Fixture::service(MaterialRepository::class)->findByCode($code) ?? self::fail('No material ' . $code);
	}

	/** @return array<string, array<string, mixed>> the first room's lines by surface and material id */
	private function lines(): array
	{
		$lines = [];
		foreach (self::rows(self::rows($this->quantities()['rooms'])[0]['lines']) as $line) {
			$materialId = $line['materialId'];
			$lines[self::stringField($line['surface']) . ':' . (is_int($materialId) ? $materialId : '-')] = $line;
		}

		return $lines;
	}

	/** @return array<string, mixed> */
	private function quantities(): array
	{
		return $this->jsonBody($this->request('GET', $this->path(Routes::ProjectQuantities), authenticatedAs: $this->user));
	}

	/** @param array<string, mixed> $body */
	private function putPrices(array $body): ResponseInterface
	{
		return $this->request('PUT', $this->path(Routes::ProjectMaterialPrices), $body, $this->user);
	}

	private function path(Routes $route): string
	{
		return str_replace(
			['{projectId:number}', '{floorId:number}'],
			[(string) $this->project->id, (string) $this->floor->id],
			$route->value,
		);
	}

	/** @return list<array<string, mixed>> */
	private static function rows(mixed $value): array
	{
		self::assertIsList($value);
		$rows = [];
		foreach ($value as $row) {
			self::assertIsArray($row);
			$fields = [];
			foreach ($row as $key => $field) {
				$fields[(string) $key] = $field;
			}
			$rows[] = $fields;
		}

		return $rows;
	}
}

<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\QuantitiesController;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Quantities\MaterialPriceService;
use FlatDesigner\Service\Quantities\PriceList;
use FlatDesigner\Service\Quantities\QuantitiesService;
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

		// Tiles are bought in whole pieces.
		$priced = 37 * 0.36 * 30 + $wallPieces * $tile * 40 + 4.48 * 3 + 12.1 * 2 + 12 * 4;
		$quantities = $this->quantities();
		self::assertFalse($quantities['complete']);
		self::assertEqualsWithDelta($priced, $quantities['total'], 0.01);

		$this->putPrices([
			'currency' => 'CZK',
			'wastePercent' => 10,
			'prices' => [...self::rows($prices['prices']), ['surface' => 'Skirting', 'pricePerLength' => 5]],
		]);
		$quantities = $this->quantities();
		self::assertTrue($quantities['complete']);
		self::assertEqualsWithDelta($priced + 13.1 * 5, $quantities['total'], 0.01);
		self::assertEqualsWithDelta($priced + 13.1 * 5, self::rows($quantities['rooms'])[0]['cost'], 0.01);
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

	/** @param array<string, mixed> $room extra room fields */
	private function buildRoom(?Material $southPaint, array $room = []): void
	{
		$south = $this->create('walls', [
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

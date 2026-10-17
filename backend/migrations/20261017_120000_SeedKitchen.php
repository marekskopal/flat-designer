<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Kitchen in the living room (H1/B_04) after the studio render (`source_files/kitchen/Render 1.png`): L-shaped run with an
 * island, sage-green matt fronts, oak worktops, oak wall cabinets and backsplash, a green top row up to
 * the ceiling, built-in oven + microwave column and an integrated fridge.
 *
 * The render carries no dimensions, so the modules use standard sizes fitted to the real niche: the
 * sink run sits on the WC wall (2280 mm, sink on the left as in the floor plan) and the hob run plus the
 * tall column stand on the east party wall. Heights: plinth 100, base 860 + worktop 40 = 900, wall
 * cabinets 1450–2150 with a 450 mm top row to the 2600 mm ceiling.
 *
 * Item positions are the footprint centre in plan coordinates; rotation 0 = front facing south,
 * −90 = front facing west.
 */
final class SeedKitchenMigration extends Migration
{
	private const int FlatId = 1;

	private const int RoomId = 4;

	private const int Green = 9;

	private const int Oak = 10;

	private const int BlackGlass = 11;

	private const int Granite = 12;

	private const int Led = 13;

	/** Inner face of the WC wall (back of the sink run). */
	private const int BackWallY = 6665;

	/** Inner face of the east party wall. */
	private const int SideWallX = 6151;

	/** West end of the sink run (kitchen niche corner). */
	private const int RunStartX = 3871;

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$stamp = ['created_at' => $now, 'updated_at' => $now];

		$this->table('materials')->insert(array_map(
			fn (array $row): array => [...$row, ...$stamp],
			[
				['id' => self::Green, 'code' => 'kitchen-green-matt', 'name' => 'Kitchen front, sage green matt', 'pattern' => 'Plain', 'color' => '#4c5f51', 'roughness' => 0.6, 'metalness' => 0.0],
				['id' => self::Oak, 'code' => 'kitchen-oak', 'name' => 'Oak décor (worktop, wall cabinets, backsplash)', 'pattern' => 'Wood', 'color' => '#a8743f', 'roughness' => 0.55, 'metalness' => 0.0],
				['id' => self::BlackGlass, 'code' => 'appliance-black-glass', 'name' => 'Appliance black glass', 'pattern' => 'Glass', 'color' => '#0e0e10', 'roughness' => 0.12, 'metalness' => 0.2],
				['id' => self::Granite, 'code' => 'sink-granite-black', 'name' => 'Granite sink, black', 'pattern' => 'Plain', 'color' => '#1c1c1d', 'roughness' => 0.55, 'metalness' => 0.0],
				['id' => self::Led, 'code' => 'led-warm', 'name' => 'LED strip, warm white', 'pattern' => 'Plain', 'color' => '#ffc98a', 'roughness' => 1.0, 'metalness' => 0.0],
			],
		));

		$baseY = self::BackWallY - 290;
		$wallCabinetY = self::BackWallY - 175;
		$sideBaseX = self::SideWallX - 290;
		$sideTallX = self::SideWallX - 300;
		$sideWallCabinetX = self::SideWallX - 175;

		$items = [
			// Sink run on the WC wall, front facing south.
			$this->item('BaseCabinet', 'Sink base 800', self::RunStartX + 400, $baseY, 0, 800, 580, 860, 0, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'fronts' => [['type' => 'door', 'count' => 2]],
			]),
			$this->item('BaseCabinet', 'Dishwasher 600 (integrated)', self::RunStartX + 1100, $baseY, 0, 600, 580, 860, 0, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'fronts' => [['type' => 'door']],
			]),
			// Corner unit runs to the party wall; its right 580 mm hide behind the hob run.
			$this->item('BaseCabinet', 'Corner base 880', self::RunStartX + 1840, $baseY, 0, 880, 580, 860, 0, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'blindRight' => 580,
				'fronts' => [['type' => 'door']],
			]),
			$this->item('Worktop', 'Worktop – sink run', self::RunStartX + 1140, self::BackWallY - 300, 860, 2280, 600, 40, 0, self::Oak),
			$this->item(
				'Backsplash',
				'Backsplash – sink run',
				self::RunStartX + 1140,
				self::BackWallY - 8,
				900,
				2280,
				16,
				550,
				0,
				self::Oak,
			),
			$this->item('Sink', 'Granite sink 1.5 bowl', self::RunStartX + 400, $baseY - 20, 900, 780, 480, 320, 0, self::Granite, [
				'bowls' => 2,
			]),

			// Hob run and tall column on the east party wall, front facing west.
			$this->item('BaseCabinet', 'Hob drawers 900', $sideBaseX, 5635, 0, 900, 580, 860, -90, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'fronts' => [
					['type' => 'drawer', 'height' => 180],
					['type' => 'drawer', 'height' => 290],
					['type' => 'drawer', 'height' => 290],
				],
			]),
			$this->item('Worktop', 'Worktop – hob run', self::SideWallX - 300, 5625, 860, 880, 600, 40, -90, self::Oak),
			$this->item('Backsplash', 'Backsplash – hob run', self::SideWallX - 8, 5925, 900, 1480, 16, 550, -90, self::Oak),
			$this->item('Hob', 'Induction hob 600', $sideBaseX, 5635, 900, 590, 520, 6, -90, self::BlackGlass),
			$this->item('TallCabinet', 'Tall oven + microwave 600', $sideTallX, 4885, 0, 600, 600, 2600, -90, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'fronts' => [
					['type' => 'drawer', 'height' => 300],
					['type' => 'drawer', 'height' => 300],
					['type' => 'oven', 'height' => 600],
					['type' => 'microwave', 'height' => 450],
					['type' => 'door', 'height' => 450],
					['type' => 'door', 'height' => 400],
				],
			]),
			$this->item('TallCabinet', 'Tall integrated fridge 600', $sideTallX, 4285, 0, 600, 600, 2600, -90, self::Green, [
				'plinth' => 100,
				'handle' => 'vertical',
				'fronts' => [
					['type' => 'door', 'height' => 800],
					['type' => 'door', 'height' => 1250],
					['type' => 'door', 'height' => 450],
				],
			]),

			// Wall cabinets: oak row with LED strip below, green top row to the ceiling.
			...$this->wallCabinetRow(
				'back',
				[[self::RunStartX + 300, 600], [self::RunStartX + 900, 600], [self::RunStartX + 1440, 480], [self::RunStartX + 1980, 600]],
				$wallCabinetY,
				0,
			),
			...$this->wallCabinetRow('side', [[6033, 565], [5468, 565]], $sideWallCabinetX, -90),
			$this->item(
				'LedStrip',
				'LED strip – sink run',
				self::RunStartX + 1140,
				self::BackWallY - 320,
				1440,
				2280,
				20,
				8,
				0,
				self::Led,
				[
					'color' => '#ffc98a',
					'intensity' => 6,
				],
			),
			$this->item('LedStrip', 'LED strip – hob run', self::SideWallX - 320, 5750, 1440, 1130, 20, 8, -90, self::Led, [
				'color' => '#ffc98a',
				'intensity' => 6,
			]),

			// Island, fronts towards the kitchen (north), worktop overhanging south for stools.
			$this->item('BaseCabinet', 'Island drawers 600', 4150, 4390, 0, 600, 580, 860, 180, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'fronts' => [
					['type' => 'drawer', 'height' => 180],
					['type' => 'drawer', 'height' => 290],
					['type' => 'drawer', 'height' => 290],
				],
			]),
			$this->item('BaseCabinet', 'Island doors 800', 3450, 4390, 0, 800, 580, 860, 180, self::Green, [
				'plinth' => 100,
				'handle' => 'horizontal',
				'fronts' => [['type' => 'door', 'count' => 2]],
			]),
			$this->item('Worktop', 'Island worktop', 3750, 4290, 860, 1600, 820, 40, 0, self::Oak),
		];

		$this->table('items')->insert(array_map(
			fn (array $row): array => [...$row, 'flat_id' => self::FlatId, 'room_id' => self::RoomId, ...$stamp],
			$items,
		));
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('DELETE FROM items WHERE room_id = ' . self::RoomId);
		$pdo->exec('DELETE FROM materials WHERE id BETWEEN ' . self::Green . ' AND ' . self::Led);
	}

	/**
	 * Oak wall cabinets (1450–2150) with a green top row (2150–2600) above each module.
	 *
	 * @param list<array{int, int}> $modules [centre along the wall, width]
	 * @return list<array<string, string|int|null>>
	 */
	private function wallCabinetRow(string $label, array $modules, int $depthCentre, int $rotation): array
	{
		$rows = [];
		foreach ($modules as $i => [$centre, $width]) {
			[$x, $y] = $rotation === 0 ? [$centre, $depthCentre] : [$depthCentre, $centre];
			$name = sprintf('Wall cabinet %s %d', $label, $i + 1);
			$rows[] = $this->item('WallCabinet', $name, $x, $y, 1450, $width, 350, 700, $rotation, self::Oak, [
				'handle' => 'none',
				'fronts' => [['type' => 'door']],
			]);
			$rows[] = $this->item('WallCabinet', $name . ' – top', $x, $y, 2150, $width, 350, 450, $rotation, self::Green, [
				'handle' => 'none',
				'fronts' => [['type' => 'door']],
			]);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed>|null $params
	 * @return array<string, string|int|null>
	 */
	private function item(
		string $kind,
		string $name,
		int $x,
		int $y,
		int $z,
		int $width,
		int $depth,
		int $height,
		int $rotation,
		int $materialId,
		?array $params = null,
	): array {
		return [
			'kind' => $kind,
			'name' => $name,
			'x' => $x,
			'y' => $y,
			'z' => $z,
			'width' => $width,
			'depth' => $depth,
			'height' => $height,
			'rotation' => $rotation,
			'material_id' => $materialId,
			'params' => $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR),
		];
	}
}

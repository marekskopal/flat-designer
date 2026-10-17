<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Flat no. 50 (H1B), Example Residences, Example City, as drawn in the client-changes floor
 * plan (AutoCAD export, 1:100).
 *
 * Geometry was extracted from the vector paths of the PDF (resolution ≈ 2 mm) and cross-checked against
 * the dimension labels and the room schedule. Plan coordinates are millimetres with the origin at the
 * inner south-west corner of the flat (the corner of the living room by the south façade), x pointing
 * east along the façade and y pointing north towards the terrace.
 */
final class SeedFlat50Migration extends Migration
{
	private const int FlatId = 1;

	private const int WallHeight = 2600;

	private const int DoorHeight = 2020;

	private const int FrenchWindowHeight = 2350;

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$stamp = ['created_at' => $now, 'updated_at' => $now];

		$this->table('flats')->insert([
			[
				'id' => self::FlatId,
				'name' => 'Byt č. 50 (H1B)',
				'address' => 'Example Residences, Example City',
				'description' => 'Floor plan with client changes (Návrh KZ). 3+kk, 97.8 m² including terrace.',
				...$stamp,
			]]);

		$this->table('materials')->insert(array_map(
			fn (array $row): array => [...$row, ...$stamp],
			[
				$this->material(1, 'wall-paint-white', 'Wall paint, warm white', 'Plain', '#f1eee8', 0.92, 0.0),
				$this->material(2, 'floor-laminate-oak', 'Laminate floor, natural oak', 'Planks', '#c09566', 0.62, 0.0),
				$this->material(3, 'floor-tiles-grey', 'Ceramic floor tiles, light grey', 'Tiles', '#bdbab4', 0.45, 0.0),
				$this->material(4, 'terrace-wpc', 'Terrace decking, WPC grey-brown', 'Planks', '#7b6d60', 0.85, 0.0),
				$this->material(5, 'door-white', 'Interior door, white', 'Plain', '#f6f6f3', 0.45, 0.0),
				$this->material(6, 'window-frame-anthracite', 'Window frame, anthracite', 'Plain', '#3b3e42', 0.5, 0.1),
				$this->material(7, 'glass', 'Glazing', 'Glass', '#cfe3ea', 0.05, 0.0),
				$this->material(8, 'shaft-plaster', 'Plasterboard shaft, white', 'Plain', '#ecebe7', 0.9, 0.0),
			],
		));

		$this->table('walls')->insert(array_map(
			fn (array $row): array => [...$row, 'flat_id' => self::FlatId, 'material_id' => 1, ...$stamp],
			[
				// Load-bearing envelope, 300 mm. The centre lines run through the outer corners so the
				// façades and party walls overlap there and close the corners.
				$this->wall(1, 'South façade', 'Exterior', -300, -150, 6451, -150, 300),
				$this->wall(2, 'North façade (terrace)', 'Exterior', -300, 14272, 6451, 14272, 300),
				$this->wall(3, 'West wall (common corridor, stairs)', 'Exterior', -150, 0, -150, 14122, 300),
				$this->wall(4, 'East party wall', 'Exterior', 6301, 0, 6301, 14122, 300),
				// Partitions.
				$this->wall(5, 'Living room / hallway', 'Interior', 0, 5992, 3871, 5992, 114),
				$this->wall(6, 'Hallway / WC + bathroom', 'Interior', 3814, 5935, 3814, 9906, 114),
				$this->wall(7, 'Kitchen / WC', 'Interior', 3871, 6735, 6151, 6735, 140),
				$this->wall(8, 'WC / bathroom', 'Interior', 3871, 7976, 6151, 7976, 140),
				$this->wall(9, 'Hallway / bedroom', 'Interior', 2656, 9963, 6151, 9963, 114),
				$this->wall(10, 'Room / bedroom', 'Interior', 2598, 8600, 2598, 14122, 116),
				$this->wall(11, 'Hallway / room', 'Interior', 0, 8657, 2656, 8657, 114),
				// Installation shafts modelled as solid blocks.
				$this->wall(12, 'Installation shaft (WC)', 'Shaft', 5793, 6805, 5793, 7906, 716),
				$this->wall(13, 'Riser (hallway)', 'Shaft', 94, 7889, 94, 8155, 188),
			],
		));

		$this->table('openings')->insert(array_map(
			fn (array $row): array => [...$row, ...$stamp],
			[
				$this->window(1, 1, 'Living room – French window west', 901),
				$this->window(2, 1, 'Living room – French window east', 3701),
				$this->window(3, 2, 'Room – French window to terrace', 949),
				$this->window(4, 2, 'Bedroom – French window to terrace', 4050),
				$this->door(5, 3, 'Entrance door', 6661, 1000, 900, 'End', 'Right', 2100),
				$this->door(6, 5, 'Door hallway → living room', 2755, 900, 800, 'Start', 'Left'),
				$this->door(7, 6, 'Door hallway → WC', 971, 800, 700, 'Start', 'Left'),
				$this->door(8, 6, 'Door hallway → bathroom', 3020, 800, 700, 'End', 'Right'),
				$this->door(9, 9, 'Door hallway → bedroom', 99, 900, 800, 'Start', 'Left'),
				$this->door(10, 10, 'Door hallway → room', 307, 900, 800, 'Start', 'Left'),
			],
		));

		$this->table('rooms')->insert(array_map(
			fn (array $row): array => [...$row, 'flat_id' => self::FlatId, ...$stamp],
			[
				$this->room(
					1,
					'H1/B_01',
					'Chodba',
					'Hallway',
					[[0, 6049], [3757, 6049], [3757, 9906], [2656, 9906], [2656, 8600], [0, 8600]],
					2400,
					3,
				),
				$this->room(2, 'H1/B_02', 'WC', 'Toilet', [[3871, 6805], [5435, 6805], [5435, 7906], [3871, 7906]], 2400, 3),
				$this->room(3, 'H1/B_03', 'Koupelna', 'Bathroom', [[3871, 8045], [6151, 8045], [6151, 9906], [3871, 9906]], 2400, 3),
				$this->room(
					4,
					'H1/B_04',
					'Obývací prostor s kuchyňskou linkou',
					'Living',
					[[0, 0], [6151, 0], [6151, 6665], [3871, 6665], [3871, 5935], [0, 5935]],
					2600,
					2,
				),
				$this->room(5, 'H1/B_05', 'Ložnice', 'Bedroom', [[2656, 10020], [6151, 10020], [6151, 14122], [2656, 14122]], 2600, 2),
				$this->room(6, 'H1/B_06', 'Pokoj', 'Bedroom', [[0, 8714], [2540, 8714], [2540, 14122], [0, 14122]], 2600, 2),
				// Outline approximated from the 12.0 m² in the schedule and the 6000 mm paving dimension.
				$this->room(7, 'H1/B_10', 'Terasa', 'Terrace', [[0, 14422], [6151, 14422], [6151, 16372], [0, 16372]], null, 4),
			],
		));
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('DELETE FROM openings WHERE wall_id IN (SELECT id FROM walls WHERE flat_id = ' . self::FlatId . ')');
		$pdo->exec('DELETE FROM walls WHERE flat_id = ' . self::FlatId);
		$pdo->exec('DELETE FROM rooms WHERE flat_id = ' . self::FlatId);
		$pdo->exec('DELETE FROM materials WHERE id <= 8');
		$pdo->exec('DELETE FROM flats WHERE id = ' . self::FlatId);
	}

	/** @return array<string, string|int|float> */
	private function material(
		int $id,
		string $code,
		string $name,
		string $pattern,
		string $color,
		float $roughness,
		float $metalness,
	): array
	{
		return [
			'id' => $id,
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => $metalness,
		];
	}

	/** @return array<string, string|int> */
	private function wall(int $id, string $name, string $type, int $startX, int $startY, int $endX, int $endY, int $thickness): array
	{
		return [
			'id' => $id,
			'name' => $name,
			'type' => $type,
			'start_x' => $startX,
			'start_y' => $startY,
			'end_x' => $endX,
			'end_y' => $endY,
			'thickness' => $thickness,
			'height' => self::WallHeight,
		];
	}

	/** @return array<string, string|int|null> */
	private function window(int $id, int $wallId, string $name, int $offset): array
	{
		return [
			'id' => $id,
			'wall_id' => $wallId,
			'name' => $name,
			'type' => 'Window',
			'offset' => $offset,
			'width' => 1800,
			'height' => self::FrenchWindowHeight,
			'sill_height' => 0,
			'leaf_width' => null,
			'hinge' => null,
			'side' => null,
		];
	}

	/** @return array<string, string|int|null> */
	private function door(
		int $id,
		int $wallId,
		string $name,
		int $offset,
		int $width,
		int $leafWidth,
		string $hinge,
		string $side,
		int $height = self::DoorHeight,
	): array {
		return [
			'id' => $id,
			'wall_id' => $wallId,
			'name' => $name,
			'type' => 'Door',
			'offset' => $offset,
			'width' => $width,
			'height' => $height,
			'sill_height' => 0,
			'leaf_width' => $leafWidth,
			'hinge' => $hinge,
			'side' => $side,
		];
	}

	/**
	 * @param list<array{int, int}> $polygon
	 * @return array<string, string|int|null>
	 */
	private function room(
		int $id,
		string $code,
		string $name,
		string $type,
		array $polygon,
		?int $ceilingHeight,
		int $floorMaterialId,
	): array
	{
		return [
			'id' => $id,
			'code' => $code,
			'name' => $name,
			'type' => $type,
			'polygon' => json_encode($polygon, JSON_THROW_ON_ERROR),
			'ceiling_height' => $ceilingHeight,
			'floor_material_id' => $floorMaterialId,
		];
	}
}

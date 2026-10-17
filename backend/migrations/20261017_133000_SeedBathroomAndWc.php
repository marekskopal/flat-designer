<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Bathroom (H1/B_03) and WC (H1/B_02) after the studio renders in `source_files/bathroom` and
 * `source_files/wc` (top views give the layout, perspectives the heights). Sizes are standard
 * products: bath 1700 × 700, washbasin 600 × 460, washing machine 600 × 600 × 850, wall-hung WC on a
 * 200 mm tiled installation pre-wall with a shelf at 1200 mm, small hand basin 400 × 250.
 *
 * Bathroom: x 3871–6151, y 8045–9906; WC: x 3871–5435, y 6805–7906. Rotation: 0 = front facing south,
 * 180 = north, −90 = west.
 */
final class SeedBathroomAndWcMigration extends Migration
{
	private const int Bathroom = 3;

	private const int Wc = 2;

	private const int TileBrown = 15;

	private const int Ceramic = 16;

	private const int Chrome = 17;

	private const int Acrylic = 18;

	private const int Mirror = 19;

	private const int ApplianceWhite = 20;

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');

		$items = [
			// Bathroom: bath along the east wall, tiled ledge at its north end.
			$this->item(self::Bathroom, 'Bathtub', 'Bathtub 1700 × 700', 5801, 8895, 0, 1700, 700, 560, -90, self::Acrylic, [
				'panelMaterialId' => self::TileBrown,
			]),
			$this->item(self::Bathroom, 'Box', 'Tiled ledge by the bath', 5801, 9826, 0, 700, 161, 560, 0, self::TileBrown),
			$this->item(self::Bathroom, 'Fixture', 'Bath thermostatic mixer', 5801, 8085, 750, 300, 80, 60, 180, self::Chrome, [
				'type' => 'bathMixer',
			]),
			$this->item(self::Bathroom, 'Fixture', 'Hand shower on holder', 5560, 8090, 1050, 120, 90, 260, 180, self::Chrome, [
				'type' => 'showerHead',
			]),
			// South wall: washing machine in the corner by the door wall, washbasin with mirror next to it.
			$this->item(self::Bathroom, 'WashingMachine', 'Washing machine', 4181, 8345, 0, 600, 600, 850, 180, self::ApplianceWhite),
			$this->item(self::Bathroom, 'Washbasin', 'Washbasin 600', 4841, 8275, 690, 600, 460, 160, 180, self::Ceramic, [
				'bowl' => 'large',
			]),
			$this->item(self::Bathroom, 'Mirror', 'Mirror above the washbasin', 4841, 8049, 1150, 500, 8, 800, 180, self::Mirror),
			// North wall: towel ladder radiator between the door swing and the bath.
			$this->item(
				self::Bathroom,
				'TowelRadiator',
				'Towel radiator 500 × 1600',
				4960,
				9866,
				200,
				500,
				30,
				1600,
				0,
				self::ApplianceWhite,
			),

			// WC: installation pre-wall with shelf on the shaft side, wall-hung toilet, flush plate.
			$this->item(self::Wc, 'Box', 'Installation pre-wall with shelf', 5335, 7356, 0, 1101, 200, 1200, -90, self::TileBrown),
			$this->item(self::Wc, 'Toilet', 'Wall-hung toilet', 4970, 7356, 0, 360, 530, 420, -90, self::Ceramic),
			$this->item(self::Wc, 'FlushPlate', 'Flush plate', 5230, 7356, 900, 250, 10, 165, -90, self::Ceramic),
			// North wall by the door: hand basin and bidet spray.
			$this->item(self::Wc, 'Washbasin', 'Hand basin 400', 4121, 7781, 730, 400, 250, 120, 0, self::Ceramic, [
				'bowl' => 'small',
			]),
			$this->item(self::Wc, 'Fixture', 'Bidet spray', 4440, 7871, 600, 60, 70, 500, 0, self::Chrome, [
				'type' => 'bidetSpray',
			]),
		];

		$this->table('items')->insert(array_map(
			fn (array $row): array => [...$row, 'flat_id' => 1, 'created_at' => $now, 'updated_at' => $now],
			$items,
		));
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec(
			'DELETE FROM items WHERE room_id IN (' . self::Bathroom . ', ' . self::Wc . ')',
		);
	}

	/**
	 * @param array<string, mixed>|null $params
	 * @return array<string, string|int|null>
	 */
	private function item(
		int $roomId,
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
			'room_id' => $roomId,
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

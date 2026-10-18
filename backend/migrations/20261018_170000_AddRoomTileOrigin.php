<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * `rooms.tile_origin_x/y`: a plan point where tile joints cross, shared by the floor tiles, the wall bands and the
 * tiled items of the room. For flat 50 it is measured in the top views of the bathroom and WC renders
 * (`source_files/bathroom`, `source_files/wc`), where the wall joints continue the floor joints:
 *
 * - bathroom: joints 139 mm from the west wall and 251 mm from the north wall (600 mm grid);
 * - WC: a 300 mm cut at the installation pre-wall (x = 5235) and at the north wall.
 *
 * The bathroom renders also show the brown band three rows of 300 mm high, i.e. up to 900 mm, not 1200.
 */
final class AddRoomTileOriginMigration extends Migration
{
	private const int ProjectId = 1;

	private const int TileBeige = 14;

	private const int TileBrown = 15;

	private const array Origins = [
		'H1/B_03' => [4010, 9655],
		'H1/B_02' => [4935, 7006],
	];

	public function up(): void
	{
		$this->table('rooms')
			->addColumn('tile_origin_x', Type::Int, nullable: true)
			->addColumn('tile_origin_y', Type::Int, nullable: true)
			->alter();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$origin = $pdo->prepare(
			'UPDATE rooms r JOIN floors f ON f.id = r.floor_id SET r.tile_origin_x = ?, r.tile_origin_y = ?
			WHERE f.project_id = ? AND r.code = ?',
		);
		if ($origin === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		foreach (self::Origins as $code => [$x, $y]) {
			$origin->execute([$x, $y, self::ProjectId, $code]);
		}

		$this->bathroomBands(900);
	}

	public function down(): void
	{
		$this->bathroomBands(1200);
		$this->table('rooms')
			->dropColumn('tile_origin_x')
			->dropColumn('tile_origin_y')
			->alter();
	}

	private function bathroomBands(int $brownTop): void
	{
		$stmt = $this->databaseProvider->getDatabase()->getPdo()->prepare(
			"UPDATE rooms r JOIN floors f ON f.id = r.floor_id SET r.wall_finishes = ? WHERE f.project_id = ? AND r.code = 'H1/B_03'",
		);
		if ($stmt === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$stmt->execute([
			json_encode([
				['materialId' => self::TileBrown, 'bottom' => 0, 'top' => $brownTop],
				['materialId' => self::TileBeige, 'bottom' => $brownTop, 'top' => 2100],
			], JSON_THROW_ON_ERROR),
			self::ProjectId,
		]);
	}
}

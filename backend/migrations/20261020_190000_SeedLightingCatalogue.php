<?php

declare(strict_types=1);

namespace Migrations;

use FlatDesigner\Service\Furniture\FurnitureSeeds;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Lights and electrics (FD-31): a bare-bulb pendant with a cable path along the ceiling, a recessed downlight, a light
 * source for the lamps of the model library, a Schuko socket, a rocker switch, a data socket and a plumbing point, with
 * the plastics, fabric cable and copper they use.
 */
final class SeedLightingCatalogueMigration extends Migration
{
	public const array Files = [
		'pendant.json',
		'downlight.json',
		'light-source.json',
		'socket.json',
		'switch.json',
		'data-socket.json',
		'plumbing-point.json',
	];

	/** code => [name, colour, roughness, metalness] */
	private const array Materials = [
		'plastic-white-gloss' => ['Plastic, white gloss (sockets, switches)', '#f4f3ef', 0.35, 0.0],
		'plastic-grey' => ['Plastic, grey (waste pipes)', '#8d8f91', 0.6, 0.0],
		'plastic-blue' => ['Plastic, blue (cold water caps)', '#2f6db5', 0.5, 0.0],
		'plastic-red' => ['Plastic, red (hot water caps)', '#c4392f', 0.5, 0.0],
		'cable-fabric-black' => ['Fabric cable, black', '#1b1b1b', 0.9, 0.0],
		'copper' => ['Copper (pipes)', '#b8734a', 0.35, 0.9],
	];

	public function up(): void
	{
		$now = date('Y-m-d H:i:s');
		$rows = [];
		foreach (self::Materials as $code => [$name, $color, $roughness, $metalness]) {
			$rows[] = [
				'code' => $code,
				'name' => $name,
				'pattern' => 'Plain',
				'color' => $color,
				'roughness' => $roughness,
				'metalness' => $metalness,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}
		$this->table('materials')->insert($rows);
		FurnitureSeeds::insert($this->databaseProvider->getDatabase()->getPdo(), self::Files);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		FurnitureSeeds::delete($pdo, self::Files);
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", array_keys(self::Materials)));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
	}
}

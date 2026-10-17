<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Migrations\Migration\Migration;
use const JSON_THROW_ON_ERROR;

/**
 * Room H1/B_06 (Pokoj) is L-shaped: in its northern part it reaches 1050 mm west past the corridor
 * wall (dimension chain 300 | 1050 | 2540 on the plan), from y = 11457 to the north façade. With the
 * niche the room has 16.5 m², as in the schedule. The west wall now stops at the niche, the niche gets
 * its own south and west walls, and the north façade is extended west over it.
 */
final class FixRoomNicheMigration extends Migration
{
	private const int NicheWidth = 1050;

	private const int NicheSouthFace = 11457;

	public function up(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$now = date('Y-m-d H:i:s');

		$pdo->exec('UPDATE walls SET end_y = ' . self::NicheSouthFace . ' WHERE id = 3');
		// Façade starts 1050 mm further west: keep its windows where they are.
		$pdo->exec('UPDATE walls SET start_x = -1350 WHERE id = 2');
		$pdo->exec('UPDATE openings SET `offset` = `offset` + ' . self::NicheWidth . ' WHERE wall_id = 2');

		$this->table('walls')->insert([
			[
				'id' => 14,
				'flat_id' => 1,
				'name' => 'Room niche – south wall (corridor)',
				'type' => 'Exterior',
				'start_x' => -1350,
				'start_y' => 11307,
				'end_x' => 0,
				'end_y' => 11307,
				'thickness' => 300,
				'height' => 2600,
				'material_id' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'id' => 15,
				'flat_id' => 1,
				'name' => 'Room niche – west party wall',
				'type' => 'Exterior',
				'start_x' => -1200,
				'start_y' => 11157,
				'end_x' => -1200,
				'end_y' => 14422,
				'thickness' => 300,
				'height' => 2600,
				'material_id' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			],
		]);

		$polygon = json_encode([[0, 8714], [2540, 8714], [2540, 14122], [-1050, 14122], [-1050, 11457], [0, 11457]], JSON_THROW_ON_ERROR);
		$stmt = $pdo->prepare('UPDATE rooms SET polygon = :polygon WHERE id = 6');
		if ($stmt === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$stmt->execute(['polygon' => $polygon]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('DELETE FROM walls WHERE id IN (14, 15)');
		$pdo->exec('UPDATE walls SET end_y = 14122 WHERE id = 3');
		$pdo->exec('UPDATE walls SET start_x = -300 WHERE id = 2');
		$pdo->exec('UPDATE openings SET `offset` = `offset` - ' . self::NicheWidth . ' WHERE wall_id = 2');
		$stmt = $pdo->prepare('UPDATE rooms SET polygon = :polygon WHERE id = 6');
		if ($stmt === false) {
			throw new \RuntimeException('Could not prepare statement.');
		}
		$stmt->execute(['polygon' => json_encode([[0, 8714], [2540, 8714], [2540, 14122], [0, 14122]], JSON_THROW_ON_ERROR)]);
	}
}

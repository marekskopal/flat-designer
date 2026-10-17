<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Projects owned by users, each with 1..N floors; a floor replaces the former flat. Every existing flat
 * becomes a project of its own (name, address and description move to the project) with one floor
 * "1. NP". The projects go to the first admin when there is one; otherwise they stay without an owner
 * until `admin:create` assigns them.
 *
 * `project_versions` hold immutable JSON snapshots of a project's floors (see `ProjectVersionProvider`).
 */
final class AddProjectsAndFloorsMigration extends Migration
{
	private const array FloorChildTables = ['rooms', 'walls', 'items'];

	public function up(): void
	{
		$this->table('projects')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('owner_id', Type::Int, size: 11, nullable: true)
			->addColumn('name', Type::String)
			->addColumn('address', Type::String, nullable: true)
			->addColumn('description', Type::Text, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['owner_id'], 'projects_owner_id_index', false)
			// A user owning projects cannot be deleted by accident; the admin service checks first.
			->addForeignKey('owner_id', 'users', 'id', 'projects_owner_id_fk', ReferenceOptionEnum::Restrict)
			->create();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();

		$pdo->exec('RENAME TABLE flats TO floors');
		$pdo->exec('ALTER TABLE floors ADD COLUMN project_id INT(11) NULL AFTER id, ADD COLUMN level INT NOT NULL DEFAULT 0 AFTER name');

		$flats = $pdo->query('SELECT id, name, address, description FROM floors ORDER BY id');
		$insertProject = $pdo->prepare(
			'INSERT INTO projects (owner_id, name, address, description, created_at, updated_at) '
			. "VALUES ((SELECT MIN(id) FROM users WHERE role = 'Admin'), ?, ?, ?, NOW(), NOW())",
		);
		$updateFloor = $pdo->prepare('UPDATE floors SET project_id = ?, name = ?, level = 0 WHERE id = ?');
		if ($flats === false || $insertProject === false || $updateFloor === false) {
			throw new \RuntimeException('Could not prepare the flat to project conversion.');
		}
		/** @var array{id: int, name: string, address: string|null, description: string|null} $flat */
		foreach ($flats->fetchAll() as $flat) {
			$insertProject->execute([$flat['name'], $flat['address'], $flat['description']]);
			$updateFloor->execute([(int) $pdo->lastInsertId(), '1. NP', $flat['id']]);
		}

		$pdo->exec(
			'ALTER TABLE floors MODIFY project_id INT(11) NOT NULL, DROP COLUMN address, DROP COLUMN description, '
			. 'ADD KEY floors_project_id_index (project_id), '
			. 'ADD CONSTRAINT floors_project_id_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE ON UPDATE CASCADE',
		);

		foreach (self::FloorChildTables as $table) {
			$pdo->exec(sprintf(
				'ALTER TABLE %1$s DROP FOREIGN KEY %1$s_flat_id_fk, DROP KEY %1$s_flat_id_index, '
				. 'CHANGE flat_id floor_id INT(11) NOT NULL, ADD KEY %1$s_floor_id_index (floor_id), '
				. 'ADD CONSTRAINT %1$s_floor_id_fk FOREIGN KEY (floor_id) REFERENCES floors (id) ON DELETE CASCADE ON UPDATE CASCADE',
				$table,
			));
		}

		$this->table('project_versions')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('number', Type::Int)
			->addColumn('note', Type::String, nullable: true)
			->addColumn('snapshot', Type::LongText)
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['project_id', 'number'], 'project_versions_project_id_number_unique', true)
			->addForeignKey('project_id', 'projects', 'id', 'project_versions_project_id_fk')
			->addForeignKey('created_by_id', 'users', 'id', 'project_versions_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('project_versions')->drop();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();

		foreach (self::FloorChildTables as $table) {
			$pdo->exec(sprintf(
				'ALTER TABLE %1$s DROP FOREIGN KEY %1$s_floor_id_fk, DROP KEY %1$s_floor_id_index, '
				. 'CHANGE floor_id flat_id INT(11) NOT NULL, ADD KEY %1$s_flat_id_index (flat_id)',
				$table,
			));
		}

		$pdo->exec(
			'ALTER TABLE floors DROP FOREIGN KEY floors_project_id_fk, ADD COLUMN address VARCHAR(255) NULL, ADD COLUMN description TEXT NULL',
		);
		$pdo->exec(
			'UPDATE floors f JOIN projects p ON p.id = f.project_id SET f.name = p.name, f.address = p.address, f.description = p.description',
		);
		$pdo->exec('ALTER TABLE floors DROP KEY floors_project_id_index, DROP COLUMN project_id, DROP COLUMN level');
		$pdo->exec('RENAME TABLE floors TO flats');

		foreach (self::FloorChildTables as $table) {
			$pdo->exec(sprintf(
				'ALTER TABLE %1$s ADD CONSTRAINT %1$s_flat_id_fk FOREIGN KEY (flat_id) REFERENCES flats (id) ON DELETE CASCADE ON UPDATE CASCADE',
				$table,
			));
		}

		$this->table('projects')->drop();
	}
}

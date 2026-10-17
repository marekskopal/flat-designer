<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Dbal;

use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Migrator;
use MarekSkopal\ORM\ORM;
use MarekSkopal\ORM\Schema\Builder\SchemaBuilder;
use MarekSkopal\ORM\Schema\Schema;

final readonly class DbContext
{
	/**
	 * Schema dumped with generated hydrators by `orm:schema-dump` in the image build (see Dockerfile).
	 * Absent in dev, where the backend is bind-mounted and the schema is built from the entity
	 * attributes on every process start.
	 */
	public const string SchemaFile = __DIR__ . '/../../../var/orm-schema.php';

	private ReconnectableDatabase $database;

	private Schema $schema;

	private ORM $orm;

	public function __construct(string $host, string $name, string $user, string $password)
	{
		$this->database = new ReconnectableDatabase($host, $name, $user, $password);
		$this->schema = is_file(self::SchemaFile) ? Schema::fromFile(self::SchemaFile) : self::createSchemaBuilder()->build();
		$this->orm = new ORM($this->database, $this->schema);
	}

	public static function createSchemaBuilder(): SchemaBuilder
	{
		return new SchemaBuilder()
			->addEntityPath(__DIR__ . '/../../Model/Entity');
	}

	public function getOrm(): ORM
	{
		return $this->orm;
	}

	/** Forgets per-request ORM state in the long-running FrankenPHP worker. */
	public function resetOrmState(): void
	{
		$this->orm->getUnitOfWork()->clear();
		$this->orm->getIdentityMap()->clear();
	}

	public function getDatabase(): DatabaseInterface
	{
		return $this->database;
	}

	public function getMigrator(): Migrator
	{
		return new Migrator(__DIR__ . '/../../../migrations/', $this->database->getInnerDatabase());
	}
}

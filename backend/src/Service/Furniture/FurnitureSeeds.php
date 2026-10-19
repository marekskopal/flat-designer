<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Json\JsonDecoder;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * The global furniture definitions seeded from `migrations/furniture/*.json` (each `{name, description, definition}`),
 * for the seed migrations: each lists its own files, so a fresh database gets every file once.
 */
final readonly class FurnitureSeeds
{
	public const string Directory = __DIR__ . '/../../../migrations/furniture';

	/**
	 * @param list<string> $files names in `Directory`
	 * @return list<array{name: string, description: string, definition: string}> the definition as stored JSON
	 */
	public static function read(array $files): array
	{
		$seeds = [];
		foreach ($files as $file) {
			$seed = self::decode($file);
			$name = $seed['name'] ?? null;
			$description = $seed['description'] ?? null;
			$definition = $seed['definition'] ?? null;
			if (!is_string($name) || !is_string($description) || !is_array($definition)) {
				throw new RuntimeException(sprintf('%s must be {name, description, definition}.', $file));
			}
			$seeds[] = ['name' => $name, 'description' => $description, 'definition' => DefinitionValidator::encode($definition)];
		}

		return $seeds;
	}

	/** @param list<string> $files */
	public static function insert(PDO $pdo, array $files): void
	{
		$now = date('Y-m-d H:i:s');
		$statement = self::prepare(
			$pdo,
			'INSERT INTO furniture_definitions (user_id, project_id, name, description, definition, created_by_id, created_at, updated_at)
			VALUES (NULL, NULL, ?, ?, ?, NULL, ?, ?)',
		);
		foreach (self::read($files) as $seed) {
			$statement->execute([$seed['name'], $seed['description'], $seed['definition'], $now, $now]);
		}
	}

	/** @param list<string> $files */
	public static function delete(PDO $pdo, array $files): void
	{
		$statement = self::prepare($pdo, 'DELETE FROM furniture_definitions WHERE user_id IS NULL AND project_id IS NULL AND name = ?');
		foreach ($files as $file) {
			$name = self::decode($file)['name'] ?? null;
			if (is_string($name)) {
				$statement->execute([$name]);
			}
		}
	}

	/** @return list<string> the seed files in `Directory`, sorted */
	public static function files(): array
	{
		$files = glob(self::Directory . '/*.json');

		return $files === false ? [] : array_map(basename(...), $files);
	}

	/** @return array<string, mixed> */
	private static function decode(string $file): array
	{
		$path = self::Directory . '/' . $file;
		if (!is_file($path)) {
			throw new RuntimeException(sprintf('Furniture seed %s is missing.', $file));
		}

		return JsonDecoder::decodeObject((string) file_get_contents($path));
	}

	/** @return list<array<string, int|string|null>> the selected columns as PDO gives them */
	public static function rows(PDOStatement $statement): array
	{
		/** @var list<array<string, int|string|null>> $rows */
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

		return $rows;
	}

	/** A prepared statement of the furniture migrations, or the reason it could not be made. */
	public static function prepare(PDO $pdo, string $sql): PDOStatement
	{
		$statement = $pdo->prepare($sql);
		if ($statement === false) {
			throw new RuntimeException(sprintf('Cannot prepare a statement for the furniture definitions: %s', $sql));
		}

		return $statement;
	}
}

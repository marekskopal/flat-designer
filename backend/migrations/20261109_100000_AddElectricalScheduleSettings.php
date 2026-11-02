<?php

declare(strict_types=1);

namespace Migrations;

use Izbik\Service\Furniture\DefinitionEncoder;
use Izbik\Service\Furniture\DefinitionSchedule;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The tagged definitions get the `schedule` settings the electrical schedule now reads instead of counting a
 * parameter `gangs` by name. The global seeds get their files' settings, patched in place, so an admin's later edits
 * stay (a fresh database gets the files already); a parameter an admin has removed or retyped since is left out of
 * them, as `DefinitionSchedule::check()` would refuse every later save. Copies in libraries and projects tagged `socket` with an int `gangs`,
 * or `data` with an int `ports`, get only `count`, so their rows list the values they did; untagged ones stay as they
 * are.
 */
final class AddElectricalScheduleSettingsMigration extends Migration
{
	/** seed name => schedule settings, as in the seed files */
	private const array Seeds = [
		'Socket outlet (Schuko)' => ['count' => 'gangs', 'values' => ['gangs']],
		'Light switch (rocker)' => ['values' => ['rockers']],
		'Data socket (RJ45)' => ['count' => 'ports', 'values' => ['ports']],
		'Pendant, bare bulb' => ['values' => ['lumens', 'kelvin']],
		'Linear pendant, oak' => ['values' => ['lumens', 'kelvin']],
		'Linear wall light, oak' => ['values' => ['lumens', 'kelvin']],
		'Square ceiling light, oak frame' => ['values' => ['lumens', 'kelvin']],
		'Downlight, recessed round' => ['values' => ['lumens', 'kelvin', 'angle']],
		'Light source' => ['values' => ['kind', 'lumens', 'kelvin']],
		'Plumbing point' => ['values' => ['kind']],
	];

	/** category => the int parameter a copy counts by */
	private const array Counts = ['socket' => 'gangs', 'data' => 'ports'];

	public function up(): void
	{
		$this->patch(static function (array $definition, ?string $name, bool $global): ?array {
			if (isset($definition['schedule']) || !is_string($definition['category'] ?? null)) {
				return null;
			}
			$seed = $global && $name !== null ? self::Seeds[$name] ?? null : null;
			$schedule = $seed === null ? null : self::applicable($definition, $seed);
			if ($schedule === null) {
				$count = self::Counts[$definition['category']] ?? null;
				$counted = array_filter(
					DefinitionValidator::list($definition, 'parameters'),
					static fn (array $p): bool => ($p['key'] ?? null) === $count && ($p['type'] ?? null) === 'int',
				);
				if ($count === null || $counted === []) {
					return null;
				}
				$schedule = ['count' => $count];
			}

			return self::withSchedule($definition, $schedule);
		});
	}

	public function down(): void
	{
		$this->patch(
			static fn (array $definition): ?array => isset($definition['schedule'])
				? array_diff_key($definition, ['schedule' => true])
				: null,
		);
	}

	/**
	 * The seed's settings as far as the definition still has their parameters: `count` an int one, `values` int, bool
	 * or select ones; null when nothing is left.
	 *
	 * @param array<mixed> $definition
	 * @param array{count?: string, values?: list<string>} $seed
	 * @return array<string, mixed>|null
	 */
	private static function applicable(array $definition, array $seed): ?array
	{
		$types = [];
		foreach (DefinitionValidator::list($definition, 'parameters') as $parameter) {
			if (is_string($parameter['key'] ?? null) && is_string($parameter['type'] ?? null)) {
				$types[$parameter['key']] = $parameter['type'];
			}
		}
		$schedule = [];
		if (isset($seed['count']) && ($types[$seed['count']] ?? null) === 'int') {
			$schedule['count'] = $seed['count'];
		}
		if (isset($seed['values'])) {
			$values = array_values(
				array_filter(
					$seed['values'],
					static fn (string $key): bool => in_array($types[$key] ?? null, DefinitionSchedule::ValueTypes, true),
				),
			);
			if ($values !== []) {
				$schedule['values'] = $values;
			}
		}

		return $schedule === [] ? null : $schedule;
	}

	/**
	 * Right after `category`, as in the seed files.
	 *
	 * @param array<mixed> $definition
	 * @param array<string, mixed> $schedule
	 * @return array<mixed>
	 */
	private static function withSchedule(array $definition, array $schedule): array
	{
		$patched = [];
		foreach ($definition as $key => $value) {
			$patched[$key] = $value;
			if ($key === 'category') {
				$patched['schedule'] = $schedule;
			}
		}

		return $patched;
	}

	/** @param callable(array<mixed>, ?string, bool): ?array<mixed> $change null leaves the row as it is */
	private function patch(callable $change): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$select = FurnitureSeeds::prepare(
			$pdo,
			"SELECT id, name, user_id IS NULL AND project_id IS NULL AND created_by_id IS NULL AS global, definition FROM furniture_definitions
			WHERE definition LIKE '%\"category\"%'",
		);
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE furniture_definitions SET definition = ? WHERE id = ?');
		$select->execute();
		foreach (FurnitureSeeds::rows($select) as $row) {
			$definition = JsonDecoder::decodeObject((string) $row['definition']);
			$patched = $change($definition, is_string($row['name']) ? $row['name'] : null, (bool) $row['global']);
			if ($patched !== null) {
				$update->execute([DefinitionEncoder::encode($patched), $row['id']]);
			}
		}
	}
}

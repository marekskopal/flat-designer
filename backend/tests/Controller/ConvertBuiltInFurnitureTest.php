<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Dbal\ReconnectableDatabase;
use FlatDesigner\Service\Furniture\BuiltInItemConverter;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderFactory;
use MarekSkopal\ORM\Migrations\Database\Provider\DatabaseProviderInterface;
use Migrations\ConvertBuiltInFurnitureMigration;
use Migrations\RemoveBuiltInFurnitureKindsMigration;
use Migrations\SeedFlat50FurnitureDefinitionsMigration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * The last built-in furniture items, saved versions and change-log states become Custom items of their definitions
 * (or boxes), and the kinds leave the `items.kind` column. The built-in rows are made by writing the kinds over boxes
 * while the column still has them, as the PHP enum no longer does.
 */
#[CoversClass(ConvertBuiltInFurnitureMigration::class)]
#[CoversClass(RemoveBuiltInFurnitureKindsMigration::class)]
#[CoversClass(BuiltInItemConverter::class)]
final class ConvertBuiltInFurnitureTest extends EditorTestCase
{
	private const array CabinetParams = ['plinth' => 100, 'handle' => 'horizontal', 'fronts' => [['type' => 'drawer', 'height' => 180], ['type' => 'door']]];

	public function testConvertsItemsVersionsAndTheChangeLog(): void
	{
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		FurnitureSeeds::insert($pdo, SeedFlat50FurnitureDefinitionsMigration::Files);
		$aluminium = Fixture::service(MaterialRepository::class)->findByCode('aluminium-brushed') ?? self::fail('No aluminium.');

		$cabinet = self::id($this->create('items', ['name' => 'Base 600', 'width' => 600, 'depth' => 580, 'height' => 860]));
		$wardrobe = self::id($this->create('items', ['name' => 'Wardrobe', 'width' => 2468, 'depth' => 600, 'height' => 2400]));
		// Too narrow for the definition's parts: a box.
		$odd = self::id($this->create('items', ['name' => 'Odd', 'width' => 20, 'depth' => 580, 'height' => 860]));
		$chair = self::id($this->create('items', ['name' => 'Spare chair', 'width' => 400, 'depth' => 395, 'height' => 885]));
		$this->edit('DELETE', 'items', $chair);
		$version = $this->jsonBody($this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user));
		$versionId = self::id($version);
		$revision = $this->revision();

		$kinds = new RemoveBuiltInFurnitureKindsMigration($this->databaseProvider());
		$kinds->down();

		try {
			$legacy = [
				$cabinet => ['BaseCabinet', self::CabinetParams],
				$wardrobe => ['Wardrobe', ['doors' => 4, 'mirrorDoors' => [3], 'profileMaterialId' => $aluminium->id]],
				$odd => ['BaseCabinet', self::CabinetParams],
				$chair => ['Chair', []],
			];
			$this->makeBuiltIn($pdo, $legacy, $versionId);

			new ConvertBuiltInFurnitureMigration($this->databaseProvider())->up();
		} finally {
			$kinds->up();
			// That migration's list predates later kinds (FD-91's Chimney): the column takes every kind there is now again.
			$all = implode(', ', array_map(static fn (ItemKindEnum $kind): string => "'" . $kind->value . "'", ItemKindEnum::cases()));
			$pdo->exec('ALTER TABLE items MODIFY kind ENUM(' . $all . ') NOT NULL');
		}

		AppHarness::app()->dbContext->resetOrmState();

		self::assertSame($revision + 1, $this->revision());
		$items = array_column($this->sceneList('items'), null, 'id');
		self::assertSame('Custom', $items[$cabinet]['kind']);
		self::assertSame(
			['values' => ['plinth' => 100, 'fronts' => [['type' => 'drawer', 'height' => 180], ['type' => 'door']], 'handle' => 'horizontal', 'handleSide' => 'right', 'blindLeft' => 0, 'blindRight' => 0]],
			$items[$cabinet]['params'],
		);
		self::assertSame(
			['values' => ['doors' => [[], [], ['mirror' => true], []], 'plinth' => 60], 'materials' => ['profile' => $aluminium->id]],
			$items[$wardrobe]['params'],
		);
		// A box keeps the built-in params, as a record of what it was.
		self::assertSame(['Box', null, self::CabinetParams], [$items[$odd]['kind'], $items[$odd]['definitionId'], $items[$odd]['params']]);
		$names = array_column($this->sceneList('definitions'), 'name');
		sort($names);
		self::assertSame(['Kitchen base cabinet', 'Sliding wardrobe'], $names);

		// The saved version draws the same items, its floor listing the definitions they use now.
		$detail = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d', $this->project->id, $versionId),
			authenticatedAs: $this->user,
		));
		self::assertIsList($detail['floors']);
		$floorId = self::id($detail['floors'][0]);
		$stored = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $versionId, $floorId),
			authenticatedAs: $this->user,
		));
		self::assertIsList($stored['items']);
		self::assertSame(['Custom', 'Custom', 'Box'], array_column($stored['items'], 'kind'));
		self::assertIsList($stored['definitions']);
		self::assertEqualsCanonicalizing(['Kitchen base cabinet', 'Sliding wardrobe'], array_column($stored['definitions'], 'name'));

		// The logged states equal the converted items (the odd one's a box too): undo goes back over all of them, the chair
		// returning as a Custom one.
		self::assertSame(200, $this->undo()->getStatusCode());
		$restored = array_column($this->sceneList('items'), null, 'id');
		self::assertSame(['Custom', 'Dining chair'], [$restored[$chair]['kind'], $this->definitionName($restored[$chair]['definitionId'])]);
		for ($step = 2; $step <= 5; $step++) {
			$response = $this->undo();
			self::assertSame(200, $response->getStatusCode(), sprintf('undo %d: %s', $step, $response->getBody()));
		}
		self::assertSame([], $this->sceneList('items'));

		$restore = $this->request('POST', sprintf('/api/projects/%d/versions/%d/restore', $this->project->id, $versionId), [], $this->user);
		self::assertSame(200, $restore->getStatusCode(), (string) $restore->getBody());
	}

	public function testStopsWhenAReplacingDefinitionIsMissing(): void
	{
		$converter = new BuiltInItemConverter([]);
		$chair = new stdClass();
		$chair->kind = 'Chair';
		$chair->width = 400;
		$chair->depth = 395;
		$chair->height = 885;

		$this->expectExceptionObject(new RuntimeException('The global definition "Dining chair" is missing.'));
		$converter->convert($chair);
	}

	/**
	 * Writes the built-in kinds and params over the boxes, their logged states and the version's items.
	 *
	 * @param array<int, array{string, array<string, mixed>}> $legacy kind and params by item id
	 */
	private function makeBuiltIn(PDO $pdo, array $legacy, int $versionId): void
	{
		$item = FurnitureSeeds::prepare($pdo, 'UPDATE items SET kind = ?, params = ? WHERE id = ?');
		foreach ($legacy as $id => [$kind, $params]) {
			$item->execute([$kind, $params === [] ? null : json_encode($params, JSON_THROW_ON_ERROR), $id]);
		}

		$changes = FurnitureSeeds::prepare(
			$pdo,
			"SELECT id, element_id, `before`, `after` FROM project_changes WHERE element_type = 'Item'",
		);
		$changes->execute();
		$change = FurnitureSeeds::prepare($pdo, 'UPDATE project_changes SET `before` = ?, `after` = ? WHERE id = ?');
		foreach (FurnitureSeeds::rows($changes) as $row) {
			[$kind, $params] = $legacy[(int) $row['element_id']];
			$change->execute(
				[self::builtInState($row['before'], $kind, $params), self::builtInState($row['after'], $kind, $params), $row['id']],
			);
		}

		$select = FurnitureSeeds::prepare($pdo, 'SELECT snapshot FROM project_versions WHERE id = ?');
		$select->execute([$versionId]);
		$snapshot = JsonDecoder::decodeObject((string) $select->fetchColumn());
		self::assertIsList($snapshot['floors']);
		$floors = [];
		foreach ($snapshot['floors'] as $floor) {
			self::assertIsArray($floor);
			self::assertIsList($floor['items']);
			$items = [];
			foreach ($floor['items'] as $stored) {
				self::assertIsArray($stored);
				self::assertIsInt($stored['id']);
				[$kind, $params] = $legacy[$stored['id']];
				$items[] = ['kind' => $kind, 'params' => $params] + $stored;
			}
			$floors[] = ['items' => $items] + $floor;
		}
		$snapshot['floors'] = $floors;
		FurnitureSeeds::prepare($pdo, 'UPDATE project_versions SET snapshot = ? WHERE id = ?')->execute(
			[json_encode($snapshot, JSON_THROW_ON_ERROR), $versionId],
		);
	}

	/** @param array<string, mixed> $params */
	private static function builtInState(int|string|null $json, string $kind, array $params): ?string
	{
		if (!is_string($json)) {
			return null;
		}

		return json_encode(['kind' => $kind, 'params' => $params] + JsonDecoder::decodeObject($json), JSON_THROW_ON_ERROR);
	}

	private function definitionName(mixed $id): ?string
	{
		$select = FurnitureSeeds::prepare(
			Fixture::service(DatabaseInterface::class)->getPdo(),
			'SELECT name FROM furniture_definitions WHERE id = ?',
		);
		$select->execute([$id]);
		$name = $select->fetchColumn();

		return is_string($name) ? $name : null;
	}

	private function databaseProvider(): DatabaseProviderInterface
	{
		$database = Fixture::service(DatabaseInterface::class);
		self::assertInstanceOf(ReconnectableDatabase::class, $database);

		return new DatabaseProviderFactory()->create($database->getInnerDatabase());
	}
}

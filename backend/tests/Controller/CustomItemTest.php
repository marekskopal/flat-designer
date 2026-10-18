<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FurnitureDefinitionRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\CustomItemValidator;
use FlatDesigner\Service\Editor\Element\ItemHandler;
use FlatDesigner\Service\Project\ProjectSnapshotService;
use FlatDesigner\Service\Project\SnapshotDefinitionRestorer;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ItemHandler::class)]
#[CoversClass(CustomItemValidator::class)]
#[CoversClass(ProjectSnapshotService::class)]
#[CoversClass(SnapshotDefinitionRestorer::class)]
final class CustomItemTest extends EditorTestCase
{
	private const array Definition = [
		'format' => 1,
		'size' => ['width' => 800, 'depth' => 300, 'height' => 1800],
		'parameters' => [
			['key' => 't', 'type' => 'int', 'default' => 18, 'min' => 10, 'max' => 40],
			['key' => 'shelves', 'type' => 'int', 'default' => 4, 'min' => 2, 'max' => 12],
		],
		'derived' => ['pitch' => '(H - t) / (shelves - 1)'],
		'materialSlots' => [['key' => 'carcass', 'default' => 'kitchen-oak'], ['key' => 'back', 'default' => 'furniture-white-matt']],
		'parts' => [
			['type' => 'board', 'name' => 'Side', 'repeat' => ['count' => 2], 'x' => 'i * (W - t)', 'y' => 0, 'z' => 0, 'w' => 't', 'h' => 'H', 'd' => 'D', 'material' => 'carcass'],
			['type' => 'board', 'name' => 'Shelf', 'repeat' => ['count' => 'shelves'], 'x' => 't', 'y' => 'i * pitch', 'z' => 0, 'w' => 'W - 2*t', 'h' => 't', 'd' => 'D', 'material' => 'carcass'],
		],
	];

	public function testCustomItemStartsWithItsDefinitionAndTheSceneCarriesIt(): void
	{
		$definitionId = $this->defineFurniture();

		$item = $this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId, 'x' => 1000, 'y' => 500]);

		self::assertSame($definitionId, $item['definitionId']);
		self::assertSame('Open shelf', $item['name']);
		self::assertSame([800, 300, 1800], [$item['width'], $item['depth'], $item['height']]);
		$definitions = $this->sceneList('definitions');
		self::assertSame([$definitionId], array_column($definitions, 'id'));
		self::assertSame('Open shelf', $definitions[0]['name']);
		self::assertIsArray($definitions[0]['definition']);
		self::assertSame(self::Definition['parts'], $definitions[0]['definition']['parts']);
		self::assertIsString($definitions[0]['updatedAt']);

		$read = $this->jsonBody($this->request('GET', self::definitionPath($definitionId), authenticatedAs: $this->user));
		self::assertSame(1, $read['itemCount']);

		// Other kinds have no definition and the scene lists only what its items use.
		self::assertNull($this->create('items', ['kind' => 'Box'])['definitionId']);
		self::assertCount(1, $this->sceneList('definitions'));
	}

	/** @return iterable<string, array{array<string, mixed>, string}> */
	public static function invalidItems(): iterable
	{
		yield 'value out of range' => [['params' => ['values' => ['shelves' => 20]]], 'values.shelves: Must be a whole number from 2 up to 12.'];
		yield 'unknown parameter' => [['params' => ['values' => ['drawers' => 2]]], 'values.drawers: Unknown parameter "drawers".'];
		yield 'unknown material slot' => [['params' => ['materials' => ['fronts' => 1]]], 'The furniture has no material slot "fronts".'];
		yield 'unknown material' => [['params' => ['materials' => ['back' => 99999]]], 'Material 99999 does not exist.'];
		yield 'other params' => [['params' => ['handle' => 'none']], 'Custom item params take only "values" and "materials", not "handle".'];
		yield 'too narrow for its parts' => [['width' => 30], 'parts[1].w: "Shelf" gets a width of -6 mm.'];
		yield 'no definition' => [['definitionId' => null], 'A Custom item needs a definitionId.'];
		yield 'definition on another kind' => [['kind' => 'Box'], 'Only a Custom item has a definitionId.'];
	}

	/** @param array<string, mixed> $fields */
	#[DataProvider('invalidItems')]
	public function testCustomItemIsValidated(array $fields, string $message): void
	{
		$response = $this->edit('POST', 'items', body: $fields + ['kind' => 'Custom', 'definitionId' => $this->defineFurniture()]);

		self::assertSame(422, $response->getStatusCode());
		self::assertStringContainsString($message, self::stringField($this->jsonBody($response)['message']));
	}

	public function testItemsUseGlobalOwnersAndTheProjectsDefinitionsOnly(): void
	{
		$global = $this->defineFurniture(Fixture::createAdmin(), ['scope' => 'global']);
		$own = $this->defineFurniture();
		$projectOwn = $this->defineFurniture(fields: ['scope' => 'project', 'projectId' => $this->project->id]);
		$stranger = Fixture::createUser();
		$strangers = $this->defineFurniture($stranger);
		$otherProject = $this->defineFurniture($stranger, ['scope' => 'project', 'projectId' => Fixture::createProject($stranger)->id]);

		foreach ([$global, $own, $projectOwn] as $definitionId) {
			$this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);
		}
		foreach ([$strangers, $otherProject, 99999] as $definitionId) {
			$response = $this->edit('POST', 'items', body: ['kind' => 'Custom', 'definitionId' => $definitionId]);
			self::assertSame(422, $response->getStatusCode());
			self::assertStringContainsString('is not available in this project', self::stringField($this->jsonBody($response)['message']));
		}
	}

	public function testChangingTheDefinitionDoesNotBlockUnrelatedEdits(): void
	{
		$definitionId = $this->defineFurniture();
		$item = $this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);

		$this->edit('PATCH', 'items', self::id($item), ['params' => ['materials' => ['back' => 1]]]);

		// The shelves now need 1000 mm of width (the item has 800) and the "back" slot is gone.
		$definition = self::Definition;
		$definition['size']['width'] = 1200;
		$definition['parts'][1]['w'] = 'W - 1000';
		unset($definition['materialSlots'][1]);
		$this->updateFurniture($definitionId, $definition);

		self::assertSame(200, $this->edit('PATCH', 'items', self::id($item), ['x' => 2000, 'name' => 'Moved shelf'])->getStatusCode());
		$resized = $this->edit('PATCH', 'items', self::id($item), ['width' => 900]);
		self::assertSame(422, $resized->getStatusCode());
		$refit = $this->edit('PATCH', 'items', self::id($item), ['width' => 1100, 'params' => ['materials' => ['carcass' => 1]]]);
		self::assertSame(200, $refit->getStatusCode());
	}

	public function testAnOptionWrittenAsAnExpressionIsCheckedAtTheItemsSize(): void
	{
		$definition = [
			'format' => 1,
			'size' => ['width' => 700, 'depth' => 300, 'height' => 700],
			'parts' => [['type' => 'taperedLeg', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 50, 'h' => 'H', 'd' => 50, 'bottomSize' => ['expr' => 'W / 7']]],
		];
		$response = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			['name' => 'Leg', 'definition' => $definition],
			$this->user,
		);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$definitionId = self::id($this->jsonBody($response));

		$this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);
		$odd = $this->edit('POST', 'items', body: ['kind' => 'Custom', 'definitionId' => $definitionId, 'width' => 600]);
		self::assertSame(422, $odd->getStatusCode());
		self::assertStringContainsString(
			'parts[0].bottomSize: Must be a whole number of mm above 0.',
			self::stringField($this->jsonBody($odd)['message']),
		);
	}

	public function testAnItemWhoseDefinitionIsGoneCanStillBeMoved(): void
	{
		$definitionId = $this->defineFurniture();
		$item = $this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);
		$repository = Fixture::service(FurnitureDefinitionRepository::class);
		// Gone for good (with its owner, say): the key is set to null.
		$repository->delete($repository->findById($definitionId) ?? self::fail('Definition missing.'));

		[$orphan] = $this->sceneList('items');
		self::assertNull($orphan['definitionId']);
		self::assertSame(200, $this->edit('PATCH', 'items', self::id($item), ['x' => 2000, 'name' => 'Orphan'])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'items', self::id($item), ['width' => 900])->getStatusCode());
	}

	public function testUndoAndCopiesKeepTheDefinition(): void
	{
		$definitionId = $this->defineFurniture();
		$item = $this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);
		$this->edit('PATCH', 'items', self::id($item), ['params' => ['values' => ['shelves' => 6]]]);

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([[]], array_column($this->sceneList('items'), 'params'));

		// A deleted definition is not offered any more, but the items using it still work.
		self::assertSame(200, $this->request('DELETE', self::definitionPath($definitionId), authenticatedAs: $this->user)->getStatusCode());
		$copy = $this->jsonBody($this->edit('POST', 'items', self::id($item), suffix: '/duplicate'));
		self::assertIsArray($copy['element']);
		self::assertSame($definitionId, $copy['element']['definitionId']);
		self::assertSame([$definitionId], array_column($this->sceneList('definitions'), 'id'));
	}

	public function testVersionsKeepTheDefinitionAsItWas(): void
	{
		$definitionId = $this->defineFurniture();
		$this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);
		$versionId = $this->saveVersion();

		$changed = self::Definition;
		$changed['parts'][1]['name'] = 'Board';
		$this->updateFurniture($definitionId, $changed);

		$versionScene = $this->jsonBody($this->request('GET', $this->versionScenePath($versionId), authenticatedAs: $this->user));
		self::assertIsList($versionScene['definitions']);
		self::assertIsArray($versionScene['definitions'][0]);
		self::assertIsArray($versionScene['definitions'][0]['definition']);
		self::assertSame(self::Definition['parts'], $versionScene['definitions'][0]['definition']['parts']);

		// Restored while the definition is there: the item links the live one, as it is now.
		$this->restore($versionId);
		$definitions = $this->sceneList('definitions');
		self::assertSame([$definitionId], array_column($definitions, 'id'));
		self::assertIsArray($definitions[0]['definition']);
		self::assertSame($changed['parts'], $definitions[0]['definition']['parts']);

		// Restored after it was (soft) deleted: still the live one, as the items used it until now.
		$this->request('DELETE', self::definitionPath($definitionId), authenticatedAs: $this->user);
		$this->restore($versionId);
		self::assertSame([$definitionId], array_column($this->sceneList('definitions'), 'id'));

		// Restored after it is gone for good: a project copy of the stored one — one per restore, not per floor.
		$repository = Fixture::service(FurnitureDefinitionRepository::class);
		$repository->delete($repository->findById($definitionId) ?? self::fail('Definition missing.'));
		$this->restore($versionId);
		[$item] = $this->sceneList('items');
		self::assertIsInt($item['definitionId']);
		self::assertNotSame($definitionId, $item['definitionId']);
		// Restoring again reuses that copy.
		$this->restore($versionId);
		self::assertSame([$item['definitionId']], array_column($this->sceneList('definitions'), 'id'));
		$copy = $this->jsonBody($this->request('GET', self::definitionPath($item['definitionId']), authenticatedAs: $this->user));
		self::assertSame('project', $copy['scope']);
		self::assertSame($this->project->id, $copy['projectId']);
		self::assertSame('Open shelf', $copy['name']);
		self::assertIsArray($copy['definition']);
		self::assertSame(self::Definition['parts'], $copy['definition']['parts']);
	}

	/** @param array<string, mixed> $fields */
	private function defineFurniture(?User $as = null, array $fields = []): int
	{
		$response = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			$fields + ['name' => 'Open shelf', 'definition' => self::Definition],
			$as ?? $this->user,
		);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		return self::id($this->jsonBody($response));
	}

	/** @param array<string, mixed> $definition */
	private function updateFurniture(int $definitionId, array $definition): void
	{
		$response = $this->request(
			'PUT',
			self::definitionPath($definitionId),
			['name' => 'Open shelf', 'definition' => $definition],
			$this->user,
		);
		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
	}

	private function saveVersion(): int
	{
		return self::id($this->jsonBody($this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user)));
	}

	/** Restores the version and points `$this->floor` at the restored floor (restoring makes new floors). */
	private function restore(int $versionId): void
	{
		$path = sprintf('/api/projects/%d/versions/%d/restore', $this->project->id, $versionId);
		self::assertSame(200, $this->request('POST', $path, [], $this->user)->getStatusCode());
		$this->floor = Fixture::firstFloor($this->project);
	}

	private function versionScenePath(int $versionId): string
	{
		return sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $versionId, $this->floor->id);
	}

	private static function definitionPath(int $definitionId): string
	{
		return str_replace('{definitionId:number}', (string) $definitionId, Routes::FurnitureDefinition->value);
	}
}

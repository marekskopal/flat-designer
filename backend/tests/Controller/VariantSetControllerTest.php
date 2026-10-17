<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\VariantSetController;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\Element\VariantSetHandler;
use FlatDesigner\Service\Editor\VariantEditor;
use FlatDesigner\Service\Project\ProjectSnapshotService;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(VariantSetController::class)]
#[CoversClass(VariantSetHandler::class)]
#[CoversClass(VariantEditor::class)]
#[CoversClass(SceneDto::class)]
#[CoversClass(ProjectSnapshotService::class)]
final class VariantSetControllerTest extends EditorTestCase
{
	public function testMakeSetOfGroupsShowsTheFirst(): void
	{
		[$island, $table] = $this->twoGroups();
		$revision = $this->revision();

		$result = $this->createSet('Kitchen layout', [$island, $table]);

		self::assertSame($revision + 1, $result['revision']);
		self::assertIsArray($result['change']);
		self::assertSame('Made variant set "Kitchen layout" of 2 group(s)', $result['change']['summary']);
		$setId = self::id($result['element']);
		self::assertSame([['id' => $setId, 'name' => 'Kitchen layout', 'activeGroupId' => $island]], $this->sceneList('variantSets'));
		self::assertSame([$setId, $setId], array_column($this->sceneList('groups'), 'variantSetId'));

		// A group can be a variant of one set only.
		self::assertSame(422, $this->edit('POST', 'variant-sets', body: ['name' => 'Again', 'groupIds' => [$table]])->getStatusCode());
	}

	public function testSwitchIsOneChangeThatUndoes(): void
	{
		[$island, $table] = $this->twoGroups();
		$setId = self::id($this->createSet('Kitchen layout', [$island, $table])['element']);

		$switched = $this->setAction($setId, 'switch', ['groupId' => $table], 200);
		self::assertIsArray($switched['change']);
		self::assertSame('Switched "Kitchen layout" to "Table"', $switched['change']['summary']);
		self::assertSame($table, $this->sceneList('variantSets')[0]['activeGroupId']);

		$noop = $this->setAction($setId, 'switch', ['groupId' => $table], 200);
		self::assertNull($noop['change']);

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame($island, $this->sceneList('variantSets')[0]['activeGroupId']);

		// Only variants of the set can be shown.
		$other = self::id($this->jsonBody($this->edit('POST', 'groups', body: ['name' => 'Sofa']))['element']);
		self::assertSame(422, $this->edit('POST', 'variant-sets', $setId, ['groupId' => $other], suffix: '/switch')->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'variant-sets', $setId, ['activeGroupId' => $other])->getStatusCode());
	}

	public function testAddVariantCopiesInPlaceOrStartsEmpty(): void
	{
		[$island] = $this->twoGroups();
		$setId = self::id($this->createSet('Kitchen layout', [$island])['element']);

		$copy = $this->setAction($setId, 'variants', ['fromGroupId' => $island], 201);
		self::assertIsArray($copy['change']);
		self::assertSame('Added variant "Island (copy)" to "Kitchen layout"', $copy['change']['summary']);
		$copyId = self::id($copy['element']);
		$items = $this->sceneList('items');
		self::assertSame([1000, 1000], array_column(array_filter($items, static fn (array $i): bool => $i['name'] === 'Island'), 'x'));
		self::assertSame($copyId, $this->sceneList('variantSets')[0]['activeGroupId']);

		$empty = $this->setAction($setId, 'variants', ['name' => 'Without island'], 201);
		self::assertSame(self::id($empty['element']), $this->sceneList('variantSets')[0]['activeGroupId']);
		self::assertCount(4, $this->sceneList('groups'));
	}

	public function testDeletingTheActiveVariantShowsAnother(): void
	{
		[$island, $table] = $this->twoGroups();
		$setId = self::id($this->createSet('Kitchen layout', [$island, $table])['element']);

		// The active variant cannot simply leave its set.
		self::assertSame(422, $this->edit('PATCH', 'groups', $island, ['variantSetId' => null])->getStatusCode());

		self::assertSame(200, $this->edit('DELETE', 'groups', $island)->getStatusCode());
		self::assertSame($table, $this->sceneList('variantSets')[0]['activeGroupId']);
		self::assertSame(['Table'], array_column($this->sceneList('items'), 'name'));

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame($island, $this->sceneList('variantSets')[0]['activeGroupId']);
		self::assertCount(2, $this->sceneList('items'));
		self::assertSame($setId, $this->sceneList('groups')[0]['variantSetId']);
	}

	public function testDecidingTheSetKeepsTheActiveVariant(): void
	{
		[$island, $table] = $this->twoGroups();
		$setId = self::id($this->createSet('Kitchen layout', [$island, $table])['element']);

		$decided = $this->jsonBody($this->edit('DELETE', 'variant-sets', $setId));
		self::assertIsArray($decided['change']);
		self::assertSame('Kept "Island" of "Kitchen layout"', $decided['change']['summary']);
		self::assertSame([], $this->sceneList('variantSets'));
		self::assertSame([['id' => $island, 'name' => 'Island', 'variantSetId' => null]], $this->sceneList('groups'));
		self::assertSame(['Island'], array_column($this->sceneList('items'), 'name'));

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([['id' => $setId, 'name' => 'Kitchen layout', 'activeGroupId' => $island]], $this->sceneList('variantSets'));
		self::assertCount(2, $this->sceneList('items'));
	}

	public function testVersionsKeepVariantSets(): void
	{
		[$island, $table] = $this->twoGroups();
		$this->createSet('Kitchen layout', [$island, $table]);

		$path = str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectVersions->value);
		$version = $this->jsonBody($this->request('POST', $path, ['note' => 'variants'], $this->user));
		self::assertIsInt($version['id']);
		$restore = str_replace(
			['{projectId:number}', '{versionId:number}'],
			[(string) $this->project->id, (string) $version['id']],
			Routes::ProjectVersionRestore->value,
		);
		self::assertSame(200, $this->request('POST', $restore, [], $this->user)->getStatusCode());

		$this->floor = Fixture::firstFloor($this->project);
		$sets = $this->sceneList('variantSets');
		$groups = $this->sceneList('groups');
		self::assertSame(['Kitchen layout'], array_column($sets, 'name'));
		self::assertSame(['Island', 'Table'], array_column($groups, 'name'));
		self::assertSame($groups[0]['id'], $sets[0]['activeGroupId']);
		self::assertSame([$sets[0]['id'], $sets[0]['id']], array_column($groups, 'variantSetId'));
	}

	/** @return array{int, int} groups "Island" (an item at x 1000) and "Table" (an item at x 3000) */
	private function twoGroups(): array
	{
		$ids = [];
		foreach (['Island' => 1000, 'Table' => 3000] as $name => $x) {
			$item = self::id($this->create('items', ['name' => $name, 'x' => $x]));
			$group = $this->edit('POST', 'groups', body: ['name' => $name, 'itemIds' => [$item]]);
			$ids[] = self::id($this->jsonBody($group)['element']);
		}

		return [$ids[0], $ids[1]];
	}

	/**
	 * @param list<int> $groupIds
	 * @return array<string, mixed>
	 */
	private function createSet(string $name, array $groupIds): array
	{
		$response = $this->edit('POST', 'variant-sets', body: ['name' => $name, 'groupIds' => $groupIds]);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		return $this->jsonBody($response);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function setAction(int $setId, string $action, array $body, int $status): array
	{
		$response = $this->edit('POST', 'variant-sets', $setId, $body, suffix: '/' . $action);
		self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());

		return $this->jsonBody($response);
	}
}

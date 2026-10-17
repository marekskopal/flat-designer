<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\GroupController;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\BatchWriter;
use FlatDesigner\Service\Editor\ChangeBatch;
use FlatDesigner\Service\Editor\Element\GroupHandler;
use FlatDesigner\Service\Editor\GroupEditor;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Editor\UndoService;
use FlatDesigner\Service\Project\ProjectSnapshotService;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(GroupController::class)]
#[CoversClass(GroupHandler::class)]
#[CoversClass(SceneEditor::class)]
#[CoversClass(GroupEditor::class)]
#[CoversClass(BatchWriter::class)]
#[CoversClass(ChangeBatch::class)]
#[CoversClass(UndoService::class)]
#[CoversClass(ProjectSnapshotService::class)]
final class GroupControllerTest extends EditorTestCase
{
	public function testCreateGroupIsOneChangeListingTheBatch(): void
	{
		[$a, $b] = $this->twoItems();
		$revision = $this->revision();

		$result = $this->createGroup('Hall stand', [$a, $b]);

		self::assertSame($revision + 1, $result['revision']);
		self::assertIsArray($result['change']);
		self::assertSame('Grouped 2 item(s) as group "Hall stand"', $result['change']['summary']);
		self::assertIsList($result['batch']);
		self::assertCount(3, $result['batch']);
		$groupId = self::id($result['element']);
		self::assertSame([['id' => $groupId, 'name' => 'Hall stand', 'variantSetId' => null]], $this->sceneList('groups'));
		self::assertSame([$groupId, $groupId], array_column($this->sceneList('items'), 'groupId'));
		// One revision for the whole batch.
		self::assertSame($revision + 1, $this->revision());
	}

	public function testMoveAndRotateGroupMovesAllItemsAndUndoesAsOne(): void
	{
		[$a, $b] = $this->twoItems();
		$groupId = self::id($this->createGroup('Pair', [$a, $b])['element']);

		$moved = $this->groupAction($groupId, 'move', ['dx' => 100, 'dy' => -50]);
		self::assertIsArray($moved['change']);
		self::assertSame('Moved group "Pair"', $moved['change']['summary']);
		self::assertSame([[1100, 950], [2100, 950]], $this->positions());

		// Footprints 600 × 600 at x 1100 and 2100: centre (1600, 950). A quarter turn swaps them across it.
		$this->groupAction($groupId, 'move', ['rotation' => 90]);
		self::assertSame([[1600, 450, 90], [1600, 1450, 90]], $this->positions(withRotation: true));

		$undo = $this->jsonBody($this->undo());
		self::assertIsArray($undo['change']);
		self::assertSame('Rotated group "Pair"', $undo['change']['summary']);
		self::assertIsList($undo['batch']);
		self::assertCount(2, $undo['batch']);
		self::assertSame([[1100, 950], [2100, 950]], $this->positions());

		self::assertSame(200, $this->redo()->getStatusCode());
		self::assertSame([[1600, 450], [1600, 1450]], $this->positions());

		$noop = $this->groupAction($groupId, 'move', ['dx' => 0]);
		self::assertNull($noop['change']);
	}

	public function testUndoOfAGroupMoveFailsCleanlyWhenOneItemWasChangedSince(): void
	{
		[$a, $b] = $this->twoItems();
		$groupId = self::id($this->createGroup('Pair', [$a, $b])['element']);
		$this->groupAction($groupId, 'move', ['dx' => 500]);

		self::assertSame(200, $this->edit('PATCH', 'items', $b, ['z' => 100], as: Fixture::createAdmin())->getStatusCode());

		$response = $this->undo();
		self::assertSame(409, $response->getStatusCode());
		self::assertSame(
			'Cannot undo "Moved group "Pair"": item "B" was changed by someone else since.',
			$this->jsonBody($response)['message'],
		);
		// Nothing of the batch was reverted.
		self::assertSame([[1500, 1000], [2500, 1000]], $this->positions());

		// The whole batch is closed; the next undo is the grouping, which touched the changed item too.
		$next = $this->undo();
		self::assertSame(409, $next->getStatusCode());
		self::assertIsString($this->jsonBody($next)['message']);
		self::assertStringStartsWith('Cannot undo "Grouped 2 item(s)', $this->jsonBody($next)['message']);
		self::assertCount(1, $this->sceneList('groups'));
	}

	public function testDuplicateCopiesGroupWithItems(): void
	{
		[$a, $b] = $this->twoItems();
		$groupId = self::id($this->createGroup('Pair', [$a, $b])['element']);

		$response = $this->edit('POST', 'groups', $groupId, [], suffix: '/duplicate');
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$copy = $this->jsonBody($response);
		$copyId = self::id($copy['element']);
		self::assertIsArray($copy['change']);
		self::assertSame('Duplicated group "Pair"', $copy['change']['summary']);

		$items = $this->sceneList('items');
		self::assertSame([$groupId, $groupId, $copyId, $copyId], array_column($items, 'groupId'));
		self::assertSame([1000, 2000, 1100, 2100], array_column($items, 'x'));

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertCount(2, $this->sceneList('items'));
		self::assertCount(1, $this->sceneList('groups'));
	}

	public function testDeleteRemovesItemsAndUngroupKeepsThem(): void
	{
		[$a, $b] = $this->twoItems();
		$c = self::id($this->create('items', ['name' => 'Loose']));
		$groupId = self::id($this->createGroup('Pair', [$a, $b])['element']);

		self::assertSame(200, $this->edit('DELETE', 'groups', $groupId)->getStatusCode());
		self::assertSame([$c], array_column($this->sceneList('items'), 'id'));
		self::assertSame([], $this->sceneList('groups'));

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([$a, $b, $c], array_column($this->sceneList('items'), 'id'));
		self::assertSame([$groupId, $groupId, null], array_column($this->sceneList('items'), 'groupId'));

		$ungrouped = $this->groupAction($groupId, 'ungroup', []);
		self::assertIsArray($ungrouped['change']);
		self::assertSame('Ungrouped "Pair"', $ungrouped['change']['summary']);
		self::assertSame([], $this->sceneList('groups'));
		self::assertSame([null, null, null], array_column($this->sceneList('items'), 'groupId'));
	}

	public function testItemsJoinAndLeaveGroupsAndGroupsAreRenamed(): void
	{
		[$a] = $this->twoItems();
		$groupId = self::id($this->createGroup('Empty', [])['element']);
		self::assertSame([null, null], array_column($this->sceneList('items'), 'groupId'));

		self::assertSame(200, $this->edit('PATCH', 'items', $a, ['groupId' => $groupId])->getStatusCode());
		$renamed = $this->jsonBody($this->edit('PATCH', 'groups', $groupId, ['name' => 'Bench']));
		self::assertIsArray($renamed['change']);
		self::assertSame('Changed group "Bench" (name)', $renamed['change']['summary']);

		$otherFloor = Fixture::firstFloor(Fixture::createProject($this->user, 'Other'));
		$foreign = $this->jsonBody($this->request(
			'POST',
			sprintf('/api/projects/%d/floors/%d/groups', $otherFloor->project->id, $otherFloor->id),
			['name' => 'Elsewhere'],
			$this->user,
		))['element'];
		self::assertSame(422, $this->edit('PATCH', 'items', $a, ['groupId' => self::id($foreign)])->getStatusCode());
		self::assertSame(404, $this->edit('POST', 'groups', body: ['name' => 'X', 'itemIds' => [999999]])->getStatusCode());
		self::assertSame(400, $this->edit('POST', 'groups', body: ['name' => 'X', 'itemIds' => ['a']])->getStatusCode());
	}

	public function testOthersCannotEditTheGroups(): void
	{
		[$a] = $this->twoItems();
		$groupId = self::id($this->createGroup('Pair', [$a])['element']);
		$stranger = Fixture::createUser();

		self::assertSame(404, $this->edit('POST', 'groups', $groupId, ['dx' => 1], as: $stranger, suffix: '/move')->getStatusCode());
		self::assertSame(404, $this->edit('DELETE', 'groups', $groupId, as: $stranger)->getStatusCode());
	}

	public function testVersionsKeepGroups(): void
	{
		[$a, $b] = $this->twoItems();
		$this->createGroup('Pair', [$a, $b]);

		$path = str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectVersions->value);
		$version = $this->jsonBody($this->request('POST', $path, ['note' => 'grouped'], $this->user));
		self::assertIsInt($version['id']);
		$restore = str_replace(
			['{projectId:number}', '{versionId:number}'],
			[(string) $this->project->id, (string) $version['id']],
			Routes::ProjectVersionRestore->value,
		);
		self::assertSame(200, $this->request('POST', $restore, [], $this->user)->getStatusCode());

		$this->floor = Fixture::firstFloor($this->project);
		$groups = $this->sceneList('groups');
		self::assertSame(['Pair'], array_column($groups, 'name'));
		self::assertSame([$groups[0]['id'], $groups[0]['id']], array_column($this->sceneList('items'), 'groupId'));
	}

	/** @return array{int, int} two 600 × 600 cabinets at (1000, 1000) and (2000, 1000) */
	private function twoItems(): array
	{
		return [
			self::id($this->create('items', ['name' => 'A', 'x' => 1000, 'y' => 1000, 'width' => 600, 'depth' => 600])),
			self::id($this->create('items', ['name' => 'B', 'x' => 2000, 'y' => 1000, 'width' => 600, 'depth' => 600])),
		];
	}

	/**
	 * @param list<int> $itemIds
	 * @return array<string, mixed>
	 */
	private function createGroup(string $name, array $itemIds): array
	{
		$response = $this->edit('POST', 'groups', body: ['name' => $name, 'itemIds' => $itemIds]);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		return $this->jsonBody($response);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function groupAction(int $groupId, string $action, array $body): array
	{
		$response = $this->edit('POST', 'groups', $groupId, $body, suffix: '/' . $action);
		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

		return $this->jsonBody($response);
	}

	/** @return list<list<mixed>> [x, y] (and rotation) of the floor's items */
	private function positions(bool $withRotation = false): array
	{
		return array_map(
			static fn (array $item): array => $withRotation ? [$item['x'], $item['y'], $item['rotation']] : [$item['x'], $item['y']],
			$this->sceneList('items'),
		);
	}
}

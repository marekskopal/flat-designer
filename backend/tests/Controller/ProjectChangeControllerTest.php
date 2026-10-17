<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ProjectChangeController;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Service\Actor\ActorContext;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Editor\UndoService;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ProjectChangeController::class)]
#[CoversClass(UndoService::class)]
final class ProjectChangeControllerTest extends EditorTestCase
{
	public function testUndoAndRedoCreateUpdateDelete(): void
	{
		$item = $this->create('items', ['name' => 'Base', 'x' => 0]);
		$this->edit('PATCH', 'items', self::id($item), ['x' => 500]);
		$this->edit('DELETE', 'items', self::id($item));

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([500], array_column($this->sceneList('items'), 'x'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([0], array_column($this->sceneList('items'), 'x'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([], $this->sceneList('items'));
		self::assertSame(409, $this->undo()->getStatusCode());

		self::assertSame(200, $this->redo()->getStatusCode());
		self::assertSame([0], array_column($this->sceneList('items'), 'x'));
		self::assertSame(self::id($item), self::id($this->sceneList('items')[0]));
		self::assertSame(200, $this->redo()->getStatusCode());
		self::assertSame([500], array_column($this->sceneList('items'), 'x'));
		self::assertSame(200, $this->redo()->getStatusCode());
		self::assertSame([], $this->sceneList('items'));
		self::assertSame(409, $this->redo()->getStatusCode());
	}

	public function testUndoBumpsRevisionAndReportsTheChange(): void
	{
		$this->create('items', ['name' => 'Base']);
		$revision = $this->revision();

		$result = $this->jsonBody($this->undo());

		self::assertSame($revision + 1, $result['revision']);
		self::assertIsArray($result['change']);
		self::assertTrue($result['change']['undone']);
		self::assertNull($result['element']);
	}

	public function testNewChangeDropsRedo(): void
	{
		$this->create('items', ['name' => 'A']);
		$this->undo();
		$this->create('items', ['name' => 'B']);

		self::assertSame(409, $this->redo()->getStatusCode());
		self::assertSame(['B'], array_column($this->sceneList('items'), 'name'));
	}

	public function testUndoOnlyTouchesOwnChanges(): void
	{
		$admin = Fixture::createUser(role: UserRoleEnum::Admin, name: 'Admin');
		$this->create('items', ['name' => 'Mine']);
		$this->create('items', ['name' => 'Admins'], $admin);

		self::assertSame(200, $this->undo()->getStatusCode());

		self::assertSame(['Admins'], array_column($this->sceneList('items'), 'name'));
	}

	public function testAgentChangesAreSeparateFromTheUsersOwn(): void
	{
		$this->create('items', ['name' => 'By hand']);
		// What McpController does for a request carrying the client's token.
		Fixture::service(ActorContext::class)->setAgent('client-1', 'Claude');
		Fixture::service(SceneEditor::class)->create($this->user, $this->floor, ElementTypeEnum::Item, ['name' => 'By agent']);

		$changes = $this->jsonList(
			$this->request('GET', sprintf('/api/projects/%d/changes', $this->project->id), authenticatedAs: $this->user),
		);
		self::assertSame(['Claude', 'Jane'], array_column($changes, 'actorName'));
		self::assertSame(['Agent', 'Human'], array_column($changes, 'actorType'));

		// The person's undo skips the agent's newer change.
		$this->undo();
		self::assertSame(['By agent'], array_column($this->sceneList('items'), 'name'));
	}

	public function testUndoRefusesWhenSomeoneElseChangedTheElementAndSkipsIt(): void
	{
		$admin = Fixture::createUser(role: UserRoleEnum::Admin);
		$first = $this->create('items', ['name' => 'First', 'x' => 0]);
		$second = $this->create('items', ['name' => 'Second', 'x' => 0]);
		$this->edit('PATCH', 'items', self::id($second), ['x' => 100]);
		$this->edit('PATCH', 'items', self::id($second), ['x' => 999], $admin);

		$conflict = $this->undo();
		self::assertSame(409, $conflict->getStatusCode());
		self::assertStringContainsString('changed by someone else', (string) $conflict->getBody());

		// The blocked change is skipped; the next undo removes the second item... which the admin changed too.
		self::assertSame(409, $this->undo()->getStatusCode());
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['Second'], array_column($this->sceneList('items'), 'name'));
		self::assertSame(999, $this->sceneList('items')[0]['x']);
		self::assertNotSame(self::id($first), self::id($this->sceneList('items')[0]));
	}

	public function testUndoBringsBackWallWithOpeningsAndRoomWithItsItems(): void
	{
		$wall = $this->create('walls', []);
		$this->create('openings', ['wallId' => self::id($wall)]);
		$room = $this->create('rooms', ['name' => 'Kitchen']);
		$this->create('items', ['name' => 'Base', 'roomId' => self::id($room)]);
		$this->edit('DELETE', 'walls', self::id($wall));
		$this->edit('DELETE', 'rooms', self::id($room));

		$this->undo();
		$this->undo();

		$walls = $this->sceneList('walls');
		self::assertCount(1, $walls);
		self::assertIsList($walls[0]['openings']);
		self::assertCount(1, $walls[0]['openings']);
		self::assertSame([self::id($room)], array_column($this->sceneList('items'), 'roomId'));
	}

	public function testRestoringAVersionEndsUndo(): void
	{
		$version = $this->jsonBody($this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user));
		self::assertIsInt($version['id']);
		$this->create('items', ['name' => 'After the version']);

		$this->request('POST', sprintf('/api/projects/%d/versions/%d/restore', $this->project->id, $version['id']), [], $this->user);

		self::assertSame(409, $this->undo()->getStatusCode());
	}

	public function testOthersCannotSeeChangesOrUndo(): void
	{
		$this->create('items', []);
		$other = Fixture::createUser();

		self::assertSame(
			404,
			$this->request('GET', sprintf('/api/projects/%d/changes', $this->project->id), authenticatedAs: $other)->getStatusCode(),
		);
		self::assertSame(404, $this->undo($other)->getStatusCode());
	}
}

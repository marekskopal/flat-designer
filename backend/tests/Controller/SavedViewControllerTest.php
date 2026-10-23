<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\SavedViewController;
use FlatDesigner\Dto\SavedViewSettingsDto;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\SavedViewProvider;
use FlatDesigner\Service\Project\SnapshotViewRestorer;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SavedViewController::class)]
#[CoversClass(SavedViewProvider::class)]
#[CoversClass(SavedViewSettingsDto::class)]
#[CoversClass(SnapshotViewRestorer::class)]
final class SavedViewControllerTest extends EditorTestCase
{
	private const array Camera = ['position' => ['x' => 1200, 'y' => 900, 'z' => 1630], 'target' => ['x' => 4200, 'y' => 3500, 'z' => 1300]];

	public function testSaveListChangeAndDelete(): void
	{
		$created = $this->save(
			['name' => 'Living at night', 'settings' => ['view' => 'overview', 'camera' => self::Camera, 'night' => true]],
		);
		self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
		$view = $this->jsonBody($created);
		self::assertSame(['Living at night', $this->floor->id], [$view['name'], $view['floorId']]);
		self::assertIsArray($view['settings']);
		// Unset options take their defaults; the variants are an object even when empty.
		self::assertSame(
			['view' => 'overview', 'ceilings' => false, 'labels' => true, 'night' => true, 'lightsOn' => null, 'presentation' => false],
			array_intersect_key($view['settings'], array_flip(['view', 'ceilings', 'labels', 'night', 'lightsOn', 'presentation'])),
		);
		self::assertStringContainsString('"variants":{}', (string) $created->getBody());
		self::assertIsInt($view['id']);

		$updated = $this->request('PUT', $this->viewPath($view['id']), [
			'floorId' => $this->floor->id,
			'name' => 'Plan',
			'settings' => ['view' => 'top', 'dimensions' => true],
		], $this->user);
		self::assertSame(200, $updated->getStatusCode());
		$listed = $this->jsonList($this->request('GET', $this->viewsPath(), authenticatedAs: $this->user));
		self::assertCount(1, $listed);
		self::assertIsArray($listed[0]['settings']);
		self::assertSame(['Plan', 'top'], [$listed[0]['name'], $listed[0]['settings']['view']]);

		// Another user's project is not found; deleting removes it.
		self::assertSame(404, $this->request('GET', $this->viewsPath(), authenticatedAs: Fixture::createUser())->getStatusCode());
		self::assertSame(200, $this->request('DELETE', $this->viewPath($view['id']), authenticatedAs: $this->user)->getStatusCode());
		self::assertSame([], $this->jsonList($this->request('GET', $this->viewsPath(), authenticatedAs: $this->user)));
	}

	public function testRefusesWhatTheFloorDoesNotHave(): void
	{
		$other = Fixture::firstFloor(Fixture::createProject($this->user, 'Other'));
		self::assertSame(422, $this->save(['floorId' => $other->id, 'settings' => ['view' => 'overview']])->getStatusCode());
		self::assertSame(422, $this->save(['settings' => ['view' => 'walkthrough']])->getStatusCode());
		self::assertSame(422, $this->save(['settings' => ['view' => 'overview', 'sectionHeight' => 200]])->getStatusCode());
		self::assertSame(422, $this->save(['settings' => ['view' => 'overview', 'variants' => ['999' => 5]]])->getStatusCode());
		self::assertSame(422, $this->save(['name' => '  ', 'settings' => ['view' => 'overview']])->getStatusCode());
		self::assertSame(422, $this->save(['settings' => ['view' => 'overview', 'sun' => ['month' => 13, 'hour' => 12]]])->getStatusCode());
		self::assertSame(422, $this->save(['settings' => ['view' => 'overview', 'sun' => ['month' => 6, 'hour' => 25]]])->getStatusCode());
	}

	public function testKeepsTheSunTime(): void
	{
		$created = $this->save(['settings' => ['view' => 'overview', 'sun' => ['month' => 12, 'hour' => 15.5]]]);
		self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
		$settings = $this->jsonBody($created)['settings'];
		self::assertIsArray($settings);
		self::assertEquals(['month' => 12, 'hour' => 15.5], $settings['sun']);

		// Views saved before it have none: the default afternoon sun.
		$plain = $this->jsonBody($this->save(['name' => 'Plain', 'settings' => ['view' => 'overview']]))['settings'];
		self::assertIsArray($plain);
		self::assertNull($plain['sun']);
	}

	public function testAVersionRestoreBringsTheViewsBackOnTheNewFloor(): void
	{
		[$setId, $island, $table] = $this->variantSet();
		self::assertSame(
			201,
			$this->save(
				['name' => 'With table', 'settings' => ['view' => 'overview', 'variants' => [(string) $setId => $table]]],
			)->getStatusCode(),
		);
		$versionId = $this->jsonBody(
			$this->request(
				'POST',
				str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectVersions->value),
				[],
				$this->user,
			),
		)['id'];
		self::assertIsInt($versionId);
		self::assertNotSame($island, $table);

		$restore = str_replace(
			['{projectId:number}', '{versionId:number}'],
			[(string) $this->project->id, (string) $versionId],
			Routes::ProjectVersionRestore->value,
		);
		self::assertSame(200, $this->request('POST', $restore, [], $this->user)->getStatusCode());

		$this->floor = Fixture::firstFloor($this->project);
		$views = $this->jsonList($this->request('GET', $this->viewsPath(), authenticatedAs: $this->user));
		self::assertCount(1, $views);
		self::assertSame($this->floor->id, $views[0]['floorId']);
		// The variant points at the restored set and its table group (new ids).
		$set = $this->sceneList('variantSets')[0];
		$restoredTable = array_values(array_filter($this->sceneList('groups'), static fn (array $g): bool => $g['name'] === 'Table'))[0];
		self::assertIsArray($views[0]['settings']);
		self::assertIsInt($set['id']);
		self::assertSame([(string) $set['id'] => $restoredTable['id']], $views[0]['settings']['variants']);
	}

	/** @return array{int, int, int} a variant set and its two groups */
	private function variantSet(): array
	{
		$groups = [];
		foreach (['Island' => 1000, 'Table' => 3000] as $name => $x) {
			$item = self::id($this->create('items', ['name' => $name, 'x' => $x]));
			$groups[] = self::id($this->jsonBody($this->edit('POST', 'groups', body: ['name' => $name, 'itemIds' => [$item]]))['element']);
		}
		$set = $this->edit('POST', 'variant-sets', body: ['name' => 'Layout', 'groupIds' => $groups]);
		self::assertSame(201, $set->getStatusCode(), (string) $set->getBody());

		return [self::id($this->jsonBody($set)['element']), $groups[0], $groups[1]];
	}

	/** @param array<string, mixed> $body */
	private function save(array $body): ResponseInterface
	{
		return $this->request('POST', $this->viewsPath(), $body + ['floorId' => $this->floor->id, 'name' => 'View'], $this->user);
	}

	private function viewsPath(): string
	{
		return str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectViews->value);
	}

	private function viewPath(int $viewId): string
	{
		return str_replace(
			['{projectId:number}', '{viewId:number}'],
			[(string) $this->project->id, (string) $viewId],
			Routes::ProjectView->value,
		);
	}
}

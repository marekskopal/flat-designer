<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ModelAssetController;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\Element\ItemModelLink;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\Glb;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ModelAssetController::class)]
#[CoversClass(ModelAssetProvider::class)]
#[CoversClass(ItemModelLink::class)]
final class ModelAssetControllerTest extends IntegrationTestCase
{
	public function testUploadListAndContent(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser();
		$chair = Glb::box([-0.38, 0.0, -0.4], [0.38, 0.86, 0.4]);

		$global = $this->upload(Routes::Models->value, 'butterfly.glb', $chair, $admin, [
			'name' => 'Butterfly chair',
			'scope' => 'global',
			'license' => 'CC0',
			'author' => 'Someone',
			'sourceUrl' => 'https://example.com/butterfly',
		]);
		self::assertSame(201, $global->getStatusCode());
		$model = $this->jsonBody($global);
		self::assertSame(['Butterfly chair', 'global', 760, 800, 860, 'CC0'], [
			$model['name'],
			$model['scope'],
			$model['width'],
			$model['depth'],
			$model['height'],
			$model['license'],
		]);
		self::assertIsInt($model['id']);

		// A user reads global models but may not add or change them; their own go to their library.
		self::assertSame(
			403,
			$this->upload(Routes::Models->value, 'x.glb', $chair, $user, ['name' => 'X', 'scope' => 'global'])->getStatusCode(),
		);
		$own = $this->jsonBody(
			$this->upload(Routes::Models->value, 'lamp.glb', Glb::box([-0.2, 0.0, -0.2], [0.2, 1.6, 0.2]), $user, ['name' => 'Floor lamp']),
		);
		self::assertSame('user', $own['scope']);
		$list = $this->jsonList($this->request('GET', Routes::Models->value, authenticatedAs: $user));
		self::assertSame([['Butterfly chair', false], ['Floor lamp', true]], array_map(
			static fn (array $m): array => [$m['name'], $m['writable']],
			$list,
		));
		self::assertSame(
			403,
			$this->request('PUT', self::modelPath(Routes::Model, $model['id']), ['name' => 'Mine'], $user)->getStatusCode(),
		);

		$content = $this->request('GET', self::modelPath(Routes::ModelContent, $model['id']), authenticatedAs: $user);
		self::assertSame(200, $content->getStatusCode());
		self::assertSame($chair, (string) $content->getBody());
		self::assertSame('model/gltf-binary', $content->getHeaderLine('Content-Type'));

		// Not a model, or one with compression the viewer cannot decode.
		$notModel = $this->upload(Routes::Models->value, 'plan.png', 'not a model', $user, ['name' => 'Plan']);
		self::assertSame(422, $notModel->getStatusCode());
		self::assertCount(2, AppHarness::fileStorage()->objects);
	}

	public function testModelItemsDrawModelsTheirProjectMayUse(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$tap = $this->projectModel($user, $project, 'Black tap', Glb::box([-0.03, 0.0, -0.1], [0.03, 0.3, 0.1]));
		$items = str_replace(
			['{projectId:number}', '{floorId:number}', '{elementType:items|walls|openings|rooms}'],
			[(string) $project->id, (string) $floor->id, 'items'],
			Routes::ProjectFloorElements->value,
		);

		$created = $this->request('POST', $items, ['kind' => 'Model', 'modelId' => $tap, 'x' => 1000, 'y' => 1000], $user);
		self::assertSame(201, $created->getStatusCode());
		$item = $this->jsonBody($created)['element'];
		self::assertIsArray($item);
		// It starts at the model's size and name.
		self::assertSame(
			['Black tap', $tap, 60, 200, 300],
			[$item['name'], $item['modelId'], $item['width'], $item['depth'], $item['height']],
		);
		$scene = $this->scene($user, $project, $floor->id);
		self::assertIsList($scene['models']);
		self::assertSame([$tap], array_column($scene['models'], 'id'));

		// A Model item needs a model the project may use; other kinds drop one.
		self::assertSame(422, $this->request('POST', $items, ['kind' => 'Model', 'x' => 0, 'y' => 0], $user)->getStatusCode());
		$stranger = Fixture::createUser();
		$theirs = $this->jsonBody(
			$this->upload(Routes::Models->value, 'wc.glb', Glb::box([0.0, 0.0, 0.0], [0.4, 0.8, 0.7]), $stranger, ['name' => 'WC']),
		)['id'];
		self::assertSame(
			422,
			$this->request('POST', $items, ['kind' => 'Model', 'modelId' => $theirs, 'x' => 0, 'y' => 0], $user)->getStatusCode(),
		);
		$box = $this->jsonBody($this->request('POST', $items, ['kind' => 'Box', 'modelId' => $tap, 'x' => 0, 'y' => 0], $user))['element'];
		self::assertIsArray($box);
		self::assertNull($box['modelId']);

		// A deleted model leaves the lists but its items keep drawing it, also after a version restore.
		self::assertSame(200, $this->request('DELETE', self::modelPath(Routes::Model, $tap), authenticatedAs: $user)->getStatusCode());
		self::assertSame(
			[],
			$this->jsonList($this->request('GET', Routes::Models->value . '?projectId=' . $project->id, authenticatedAs: $user)),
		);
		$versions = str_replace('{projectId:number}', (string) $project->id, Routes::ProjectVersions->value);
		$versionId = $this->jsonBody($this->request('POST', $versions, [], $user))['id'];
		self::assertIsInt($versionId);
		$restore = str_replace(
			['{projectId:number}', '{versionId:number}'],
			[(string) $project->id, (string) $versionId],
			Routes::ProjectVersionRestore->value,
		);
		$this->request('POST', $restore, [], $user);
		$floors = $this->jsonBody(
			$this->request('GET', str_replace('{projectId:number}', (string) $project->id, Routes::Project->value), authenticatedAs: $user),
		)['floors'];
		self::assertIsList($floors);
		self::assertIsArray($floors[0]);
		self::assertIsInt($floors[0]['id']);
		$restored = $this->scene($user, $project, $floors[0]['id']);
		self::assertIsList($restored['items']);
		self::assertContains($tap, array_column($restored['items'], 'modelId'));
	}

	public function testAnItemThatLostItsModelCanStillBeMoved(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$lamp = $this->projectModel($user, $project, 'Lamp', Glb::box([-0.2, 0.0, -0.2], [0.2, 1.6, 0.2]));
		$items = str_replace(
			['{projectId:number}', '{floorId:number}', '{elementType:items|walls|openings|rooms}'],
			[(string) $project->id, (string) $floor->id, 'items'],
			Routes::ProjectFloorElements->value,
		);
		$created = $this->jsonBody($this->request('POST', $items, ['kind' => 'Model', 'modelId' => $lamp, 'x' => 500, 'y' => 500], $user));
		self::assertIsArray($created['element']);
		$itemId = $created['element']['id'];
		self::assertIsInt($itemId);
		self::assertIsInt($created['revision']);

		// The row went (with its owner, say): the item draws a box but is not stuck.
		$repository = Fixture::service(ItemRepository::class);
		$item = $repository->findOnFloor($floor->id, $itemId);
		self::assertNotNull($item);
		$item->model = null;
		$repository->persist($item);

		$moved = $this->request(
			'PATCH',
			$items . '/' . $itemId,
			['x' => 1500],
			$user,
			headers: ['If-Match' => (string) $created['revision']],
		);
		self::assertSame(200, $moved->getStatusCode());
		$element = $this->jsonBody($moved)['element'];
		self::assertIsArray($element);
		self::assertSame([1500, null], [$element['x'], $element['modelId']]);
	}

	public function testShareGuestsLoadTheModelsOfTheirScene(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$plant = $this->projectModel($user, $project, 'Plant', Glb::box([-0.2, 0.0, -0.2], [0.2, 0.9, 0.2]));
		$unused = $this->projectModel($user, $project, 'Lamp', Glb::box([-0.2, 0.0, -0.2], [0.2, 1.6, 0.2]));
		$items = str_replace(
			['{projectId:number}', '{floorId:number}', '{elementType:items|walls|openings|rooms}'],
			[(string) $project->id, (string) $floor->id, 'items'],
			Routes::ProjectFloorElements->value,
		);
		$this->request('POST', $items, ['kind' => 'Model', 'modelId' => $plant, 'x' => 500, 'y' => 500], $user);

		$link = $this->jsonBody($this->request(
			'POST',
			str_replace('{projectId:number}', (string) $project->id, Routes::ProjectShareLinks->value),
			[
				'floorId' => $floor->id,
				'name' => 'Look',
				'settings' => ['views' => ['overview'], 'initialView' => 'overview', 'ceilings' => false, 'labels' => true, 'sectionHeight' => null, 'dimensions' => false],
			],
			$user,
		));
		self::assertIsString($link['token']);
		$path = static fn (int $modelId): string => str_replace(
			['{token:[a-f0-9]+}', '{modelId:number}'],
			[$link['token'], (string) $modelId],
			Routes::ShareModelContent->value,
		);

		// Without sign-in: the drawn model loads, another of the project does not.
		self::assertSame(200, $this->request('GET', $path($plant))->getStatusCode());
		self::assertSame(404, $this->request('GET', $path($unused))->getStatusCode());
	}

	private function projectModel(User $user, Project $project, string $name, string $bytes): int
	{
		$response = $this->upload(Routes::Models->value, 'model.glb', $bytes, $user, [
			'name' => $name,
			'scope' => 'project',
			'projectId' => (string) $project->id,
		]);
		self::assertSame(201, $response->getStatusCode());
		$id = $this->jsonBody($response)['id'];
		self::assertIsInt($id);

		return $id;
	}

	/** @return array<string, mixed> */
	private function scene(User $user, Project $project, int $floorId): array
	{
		return $this->jsonBody($this->request(
			'GET',
			str_replace(
				['{projectId:number}', '{floorId:number}'],
				[(string) $project->id, (string) $floorId],
				Routes::ProjectFloorScene->value,
			),
			authenticatedAs: $user,
		));
	}

	private static function modelPath(Routes $route, int $modelId): string
	{
		return str_replace('{modelId:number}', (string) $modelId, $route->value);
	}
}

<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Command\ExamplesExportCommand;
use Izbik\Controller\ExampleController;
use Izbik\Model\Entity\Project;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Route\Routes;
use Izbik\Service\Example\ExampleCatalogue;
use Izbik\Service\Example\ExampleProjectService;
use Izbik\Service\Project\SnapshotFiles;
use Izbik\Service\Project\SnapshotLocalizer;
use Izbik\Service\Project\SnapshotMaterials;
use Izbik\Service\Project\SnapshotModels;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\Glb;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExampleController::class)]
#[CoversClass(ExampleCatalogue::class)]
#[CoversClass(ExampleProjectService::class)]
#[CoversClass(ExamplesExportCommand::class)]
#[CoversClass(SnapshotLocalizer::class)]
#[CoversClass(SnapshotFiles::class)]
#[CoversClass(SnapshotMaterials::class)]
#[CoversClass(SnapshotModels::class)]
final class ExampleControllerTest extends IntegrationTestCase
{
	private const string ModelSource = 'https://polyhaven.com/a/test_chair';

	public function testAUserStartsFromAnExportedExample(): void
	{
		$author = Fixture::createUser('author@example.com');
		$source = Fixture::createProject($author, 'Model flat');
		$floor = Fixture::firstFloor($source);
		Fixture::furnishFloor($floor);
		$model = $this->globalModel();
		$items = Fixture::elementsPath($source, $floor->id);
		self::assertSame(
			201,
			$this->request('POST', $items, ['kind' => 'Model', 'modelId' => $model, 'x' => 1000, 'y' => 1000], $author)->getStatusCode(),
		);
		$view = ['floorId' => $floor->id, 'name' => 'Tour · Kitchen', 'settings' => ['view' => 'overview']];
		self::assertSame(201, $this->request('POST', self::projectPath(Routes::ProjectViews, $source), $view, $author)->getStatusCode());

		$this->export($source, ['--name' => 'Demo flat', '--description' => 'A small flat.', '--start-view' => 'Tour · Kitchen']);

		$user = Fixture::createUser('new@example.com');
		$examples = $this->jsonList($this->request('GET', Routes::Examples->value, authenticatedAs: $user));
		self::assertSame([['slug' => 'demo-flat', 'name' => 'Demo flat', 'description' => 'A small flat.', 'credit' => '']], $examples);

		$created = $this->request('POST', str_replace('{slug:[a-z0-9-]+}', 'demo-flat', Routes::ExampleProjects->value), [], $user);
		self::assertSame(201, $created->getStatusCode());
		$body = $this->jsonBody($created);
		self::assertIsArray($body['project']);
		self::assertSame(['Demo flat', $user->id], [$body['project']['name'], $body['project']['ownerId']]);
		self::assertIsInt($body['floorId']);
		self::assertIsInt($body['viewId']);
		self::assertNotSame($floor->id, $body['floorId']);
		self::assertIsInt($body['project']['id']);

		$scene = $this->jsonBody($this->request(
			'GET',
			str_replace(
				['{projectId:number}', '{floorId:number}'],
				[(string) $body['project']['id'], (string) $body['floorId']],
				Routes::ProjectFloorScene->value,
			),
			authenticatedAs: $user,
		));
		self::assertIsList($scene['rooms']);
		self::assertIsList($scene['walls']);
		self::assertIsList($scene['items']);
		self::assertCount(1, $scene['rooms']);
		self::assertCount(1, $scene['walls']);
		$modelIds = array_values(array_filter(array_column($scene['items'], 'modelId'), is_int(...)));
		self::assertSame([$model], $modelIds);
		// The author's project is untouched.
		self::assertSame(404, $this->request('GET', self::projectPath(Routes::Project, $source), authenticatedAs: $user)->getStatusCode());
	}

	public function testAnUnknownExampleIsNotFound(): void
	{
		$user = Fixture::createUser();

		$response = $this->request('POST', str_replace('{slug:[a-z0-9-]+}', 'nothing', Routes::ExampleProjects->value), [], $user);

		self::assertSame(404, $response->getStatusCode());
	}

	public function testTheIdsOfAnotherInstanceAreMatchedByCodeAndSource(): void
	{
		$owner = Fixture::createUser();
		$project = Fixture::createProject($owner);
		$material = Fixture::service(MaterialRepository::class)->findAllOrdered()[0];
		$model = $this->globalModel();
		$snapshot = [
			'materials' => [['id' => 901, 'code' => $material->code], ['id' => 902, 'code' => 'not-on-this-instance']],
			'exteriorMaterialId' => 902,
			'floors' => [
				[
					'underlay' => ['fileId' => 5],
					'pictures' => [5],
					'models' => [['id' => 77, 'sourceUrl' => self::ModelSource], ['id' => 78, 'sourceUrl' => 'https://example.com/gone']],
					'definitions' => [
						[
							'id' => 3,
							'name' => 'Framed picture',
							'definition' => ['parameters' => [['key' => 'picture', 'type' => 'image']], 'materialId' => 902],
						]],
					'rooms' => [
						[
							'floorMaterialId' => 901,
							'wallFinishes' => [['materialId' => 901, 'bottom' => 0, 'top' => 900], ['materialId' => 902, 'bottom' => 900, 'top' => 1200]],
						]],
					'items' => [
						['kind' => 'Model', 'modelId' => 77],
						['kind' => 'Model', 'modelId' => 78],
						['kind' => 'Bathtub', 'params' => ['panelMaterialId' => 901]],
						[
							'kind' => 'Custom',
							'definitionId' => 3,
							'params' => ['values' => ['picture' => 5, 'frame' => 30], 'materials' => ['frame' => 901, 'mount' => 902]],
						],
					],
				]],
		];

		$localized = Fixture::service(SnapshotLocalizer::class)->localize($snapshot, $project);

		self::assertNull($localized['exteriorMaterialId']);
		self::assertIsList($localized['floors']);
		$floor = $localized['floors'][0];
		self::assertIsArray($floor);
		self::assertSame([null, []], [$floor['underlay'], $floor['pictures']]);
		self::assertSame($snapshot['floors'][0]['definitions'], $floor['definitions']);
		self::assertSame(
			[['floorMaterialId' => $material->id, 'wallFinishes' => [['materialId' => $material->id, 'bottom' => 0, 'top' => 900]]]],
			$floor['rooms'],
		);
		self::assertSame([
			['kind' => 'Model', 'modelId' => $model],
			['kind' => 'Model', 'modelId' => null],
			['kind' => 'Bathtub', 'params' => ['panelMaterialId' => $material->id]],
			[
				'kind' => 'Custom',
				'definitionId' => 3,
				'params' => ['values' => ['frame' => 30], 'materials' => ['frame' => $material->id]],
			],
		], $floor['items']);
	}

	private function globalModel(): int
	{
		$response = $this->upload(
			Routes::Models->value,
			'chair.glb',
			Glb::box([-0.3, 0.0, -0.3], [0.3, 0.9, 0.3]),
			Fixture::createAdmin(),
			[
				'name' => 'Chair',
				'scope' => 'global',
				'sourceUrl' => self::ModelSource,
			],
		);
		self::assertSame(201, $response->getStatusCode());
		$id = $this->jsonBody($response)['id'];
		self::assertIsInt($id);

		return $id;
	}

	/** @param array<string, string> $options */
	private function export(Project $project, array $options): void
	{
		$tester = new CommandTester(new ExamplesExportCommand());
		$tester->execute(
			['project' => (string) $project->id, 'slug' => 'demo-flat', '--directory' => AppHarness::examplesDirectory()] + $options,
		);
		self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
	}

	private static function projectPath(Routes $route, Project $project): string
	{
		return str_replace('{projectId:number}', (string) $project->id, $route->value);
	}
}

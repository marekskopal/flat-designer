<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Project;

use Izbik\Dto\FurnitureDefinitionInputDto;
use Izbik\Dto\MaterialPriceDto;
use Izbik\Dto\ModelAssetInputDto;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Model\Repository\ModelAssetRepository;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Route\Routes;
use Izbik\Service\Furniture\FurnitureDefinitionProvider;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\ModelAsset\ModelAssetProvider;
use Izbik\Service\Project\ProjectFileProvider;
use Izbik\Service\Project\SnapshotFiles;
use Izbik\Service\Project\SnapshotLimits;
use Izbik\Service\Project\Transfer\BundleAssetImporter;
use Izbik\Service\Project\Transfer\BundleLibraryImporter;
use Izbik\Service\Project\Transfer\OpenedBundle;
use Izbik\Service\Project\Transfer\ProjectBundle;
use Izbik\Service\Project\Transfer\ProjectBundleReader;
use Izbik\Service\Project\Transfer\ProjectExporter;
use Izbik\Service\Project\Transfer\ProjectImportConfig;
use Izbik\Service\Project\Transfer\ProjectImporter;
use Izbik\Service\Project\Transfer\ProjectImportException;
use Izbik\Service\Quantities\MaterialPriceService;
use Izbik\Service\Storage\S3Config;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\Glb;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ZipArchive;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ProjectImporter::class)]
#[CoversClass(BundleAssetImporter::class)]
#[CoversClass(BundleLibraryImporter::class)]
#[CoversClass(ProjectBundleReader::class)]
#[CoversClass(OpenedBundle::class)]
#[CoversClass(SnapshotFiles::class)]
#[CoversClass(SnapshotLimits::class)]
final class ProjectImporterTest extends IntegrationTestCase
{
	/** @var list<string> */
	private array $paths = [];

	protected function tearDown(): void
	{
		foreach ($this->paths as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}

		parent::tearDown();
	}

	public function testAnImportedProjectExportsAsTheOriginalDid(): void
	{
		$author = Fixture::createUser('author@example.com');
		$source = $this->richProject($author);
		$path = $this->path();
		Fixture::service(ProjectExporter::class)->zip($source, $path);

		$user = Fixture::createUser('new@example.com');
		$imported = Fixture::service(ProjectImporter::class)->import($user, $path);

		self::assertSame([], $imported->warnings);
		self::assertSame([$user->id, 'Flat 12'], [$imported->project->ownerId, $imported->project->name]);
		self::assertNotSame($source->id, $imported->project->id);
		$project = Fixture::service(ProjectRepository::class)->findById($imported->project->id);
		self::assertInstanceOf(Project::class, $project);
		$again = $this->path();
		Fixture::service(ProjectExporter::class)->zip($project, $again);
		self::assertSame(self::comparable($this->manifest($path)), self::comparable($this->manifest($again)));

		$scene = $this->scene($user, $project, $imported->floorId);
		self::assertIsArray($scene['underlay']);
		self::assertSame('plan.png', $scene['underlay']['filename']);
		$models = Fixture::service(ModelAssetRepository::class)->findByProject($project->id);
		self::assertSame(['Lamp'], array_map(static fn (ModelAsset $model): string => $model->name, $models));
		self::assertSame(
			['Open shelf', 'Spare shelf'],
			array_map(
				static fn (FurnitureDefinition $definition): string => $definition->name,
				Fixture::service(FurnitureDefinitionRepository::class)->findByProject($project->id),
			),
		);
	}

	public function testAProjectWithoutFilesLeavesThemOut(): void
	{
		$author = Fixture::createUser('author@example.com');
		$source = $this->richProject($author);
		$path = $this->path();
		file_put_contents($path, Fixture::service(ProjectExporter::class)->json($source));

		$user = Fixture::createUser('new@example.com');
		$imported = Fixture::service(ProjectImporter::class)->import($user, $path);

		$project = Fixture::service(ProjectRepository::class)->findById($imported->project->id);
		self::assertInstanceOf(Project::class, $project);
		self::assertNull($this->scene($user, $project, $imported->floorId)['underlay']);
		self::assertSame([], Fixture::service(ProjectFileProvider::class)->list($project));
		self::assertSame([], Fixture::service(ModelAssetRepository::class)->findByProject($project->id));
	}

	public function testAModelThisInstanceHasIsNotUploadedAgain(): void
	{
		$author = Fixture::createUser('author@example.com');
		$source = Fixture::createProject($author, 'Flat 12');
		$floor = Fixture::firstFloor($source);
		$global = Fixture::service(ModelAssetProvider::class)->upload(
			Fixture::createAdmin(),
			new ModelAssetInputDto(name: 'Chair', sourceUrl: 'https://polyhaven.com/a/test_chair', scope: FurnitureScopeEnum::Global),
			'chair.glb',
			Glb::box([-0.3, 0.0, -0.3], [0.3, 0.9, 0.3]),
		);
		$this->postItem($author, $source, $floor->id, ['kind' => 'Model', 'modelId' => $global->id, 'x' => 1000, 'y' => 1000]);
		$path = $this->path();
		Fixture::service(ProjectExporter::class)->zip($source, $path);
		$models = $this->manifest($path)['models'] ?? null;
		self::assertIsList($models);
		self::assertCount(1, $models);

		$user = Fixture::createUser('new@example.com');
		$imported = Fixture::service(ProjectImporter::class)->import($user, $path);

		self::assertSame([], Fixture::service(ModelAssetRepository::class)->findByProject($imported->project->id));
		$project = Fixture::service(ProjectRepository::class)->findById($imported->project->id);
		self::assertInstanceOf(Project::class, $project);
		$items = $this->scene($user, $project, $imported->floorId)['items'];
		self::assertIsList($items);
		self::assertIsArray($items[0]);
		self::assertSame($global->id, $items[0]['modelId']);
	}

	public function testWhatThisInstanceCannotTakeIsLeftOutWithAWarning(): void
	{
		$manifest = $this->emptyManifest();
		$manifest['files'] = [['id' => 1, 'filename' => 'gone.txt', 'mimeType' => 'text/plain', 'path' => 'files/1.txt']];
		$manifest['models'] = [['id' => 5, 'name' => 'Broken', 'sourceUrl' => '', 'filename' => 'broken.glb', 'path' => 'models/5.glb']];
		$manifest['definitions'] = [['id' => 9, 'name' => 'Nothing', 'description' => '', 'definition' => ['format' => 1]]];
		$manifest['pricing'] = [
			'currency' => 'EUR',
			'wastePercent' => 10,
			'prices' => [['materialCode' => 'no-such-material', 'surface' => null, 'pricePerArea' => 10, 'pricePerLength' => null]],
		];
		$path = $this->zip($manifest, ['models/5.glb' => 'not a glb', '../escape.txt' => 'x']);

		$imported = Fixture::service(ProjectImporter::class)->import(Fixture::createUser(), $path);

		self::assertCount(4, $imported->warnings);
		self::assertStringContainsString('File "gone.txt"', $imported->warnings[0]);
		self::assertStringContainsString('Model "Broken"', $imported->warnings[1]);
		self::assertStringContainsString('Furniture definition "Nothing"', $imported->warnings[2]);
		self::assertStringContainsString('no material "no-such-material"', $imported->warnings[3]);
		self::assertFileDoesNotExist(dirname($path) . '/escape.txt');
	}

	public function testAnEntryNamedTwiceIsReadOnce(): void
	{
		$manifest = $this->emptyManifest();
		$manifest['files'] = [
			['id' => 1, 'filename' => 'one.txt', 'mimeType' => 'text/plain', 'path' => 'files/1.txt'],
			['id' => 2, 'filename' => 'two.txt', 'mimeType' => 'text/plain', 'path' => 'files/1.txt'],
		];
		$path = $this->zip($manifest, ['files/1.txt' => 'Measured on site.']);

		$imported = Fixture::service(ProjectImporter::class)->import(Fixture::createUser(), $path);

		self::assertCount(1, $imported->warnings);
		self::assertStringContainsString('File "two.txt"', $imported->warnings[0]);
		self::assertCount(1, AppHarness::fileStorage()->objects);
	}

	public function testAPriceThisInstanceCannotTakeIsLeftOutAlone(): void
	{
		$material = Fixture::service(MaterialRepository::class)->findAllOrdered()[0];
		$manifest = $this->emptyManifest();
		$manifest['pricing'] = [
			'currency' => 'euros',
			'wastePercent' => 12,
			'prices' => [
				['materialCode' => $material->code, 'surface' => null, 'pricePerArea' => 42.5, 'pricePerLength' => null],
				['materialCode' => null, 'surface' => 'Rooftop garden', 'pricePerArea' => 10, 'pricePerLength' => null],
				['materialCode' => null, 'surface' => null, 'pricePerArea' => 10, 'pricePerLength' => null],
			],
		];

		$imported = Fixture::service(ProjectImporter::class)->import(Fixture::createUser(), $this->zip($manifest, []));

		self::assertCount(3, $imported->warnings);
		self::assertStringContainsString('no surface "Rooftop garden"', $imported->warnings[0]);
		self::assertStringContainsString('neither one material nor one surface', $imported->warnings[1]);
		self::assertStringContainsString('currency and waste were left at the defaults', $imported->warnings[2]);
		$project = Fixture::service(ProjectRepository::class)->findById($imported->project->id);
		self::assertInstanceOf(Project::class, $project);
		$prices = Fixture::service(MaterialPriceService::class)->get($project);
		self::assertSame('EUR', $prices->currency);
		self::assertCount(1, $prices->prices);
		self::assertSame($material->id, $prices->prices[0]->materialId);
	}

	public function testASnapshotThisInstanceCannotTakeFailsTheImportAndKeepsNothing(): void
	{
		$manifest = $this->emptyManifest();
		$snapshot = $manifest['snapshot'];
		self::assertIsArray($snapshot);
		$snapshot['floors'] = [
			[
				'floor' => ['id' => 1, 'name' => 'Ground floor', 'level' => 0],
				'rooms' => [['id' => 1, 'code' => 'R1', 'name' => 'Hall', 'type' => 'Ballroom', 'polygon' => [[0, 0], [1000, 0], [1000, 1000]]]],
				'walls' => [],
				'items' => [],
			]];
		$manifest['snapshot'] = $snapshot;
		$manifest['files'] = [['id' => 1, 'filename' => 'notes.txt', 'mimeType' => 'text/plain', 'path' => 'files/1.txt']];
		$path = $this->zip($manifest, ['files/1.txt' => 'Measured on site.']);
		$user = Fixture::createUser();

		try {
			Fixture::service(ProjectImporter::class)->import($user, $path);
			self::fail('A room of an unknown type should fail the import.');
		} catch (ProjectImportException $e) {
			self::assertSame(422, $e->getCode());
			self::assertSame('The project file holds a value this version of Izbik cannot take.', $e->getMessage());
		}

		self::assertSame([], Fixture::service(ProjectRepository::class)->findByOwner($user->id));
		self::assertSame([], AppHarness::fileStorage()->objects);
	}

	public function testRefusesWhatIsNotAProjectFile(): void
	{
		$user = Fixture::createUser();
		$cases = [
			'not JSON' => $this->file('{"format":'),
			'another kind' => $this->file((string) json_encode(['format' => 1, 'kind' => 'something-else', 'snapshot' => []])),
			'a newer format' => $this->file((string) json_encode(['format' => 2, 'kind' => ProjectBundle::Kind, 'snapshot' => []])),
			'a zip without project.json' => $this->zip(null, ['readme.txt' => 'Hello']),
			'too many files' => $this->file((string) json_encode([
				'kind' => ProjectBundle::Kind,
				'format' => 1,
				'snapshot' => ['format' => 1],
				'files' => array_fill(0, SnapshotLimits::MaxAssets + 1, []),
			])),
			'too many floors' => $this->file((string) json_encode([
				'kind' => ProjectBundle::Kind,
				'format' => 1,
				'snapshot' => ['format' => 1, 'floors' => array_fill(0, SnapshotLimits::MaxFloors + 1, ['floor' => []])],
			])),
		];
		foreach ($cases as $case => $path) {
			try {
				Fixture::service(ProjectImporter::class)->import($user, $path);
				self::fail(sprintf('%s should be refused.', $case));
			} catch (ProjectImportException $e) {
				self::assertSame(422, $e->getCode(), $case);
			}
		}
		self::assertSame([], Fixture::service(ProjectRepository::class)->findByOwner($user->id));
	}

	public function testRefusesAFileLargerThanTheLimit(): void
	{
		$reader = new ProjectBundleReader(new ProjectImportConfig(5000), Fixture::service(S3Config::class));
		$small = $this->zip($this->emptyManifest(), ['files/1.txt' => str_repeat('x', 1000)]);
		$reader->open($small)->close();
		$bomb = $this->zip($this->emptyManifest(), ['files/1.txt' => str_repeat('x', 100000)]);
		self::assertLessThan(5000, filesize($bomb));

		$this->expectExceptionObject(new ProjectImportException('The project file unpacks to more than 0 MB.', 413));

		$reader->open($bomb);
	}

	private function richProject(User $author): Project
	{
		$project = Fixture::createProject($author, 'Flat 12');
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$file = $this->uploadImage($author, $project);
		self::assertSame(200, $this->request('PUT', self::floorPath(Routes::ProjectFloorUnderlay, $project, $floor->id), [
			'fileId' => $file,
			'points' => [['px' => 0, 'py' => 0, 'x' => 0, 'y' => 0], ['px' => 4, 'py' => 0, 'x' => 4000, 'y' => 0]],
		], $author)->getStatusCode());
		$model = Fixture::service(ModelAssetProvider::class)->upload(
			$author,
			new ModelAssetInputDto(name: 'Lamp', scope: FurnitureScopeEnum::Project, projectId: $project->id),
			'lamp.glb',
			Glb::box([-0.2, 0.0, -0.2], [0.2, 1.5, 0.2]),
		);
		$this->postItem($author, $project, $floor->id, ['kind' => 'Model', 'modelId' => $model->id, 'x' => 1000, 'y' => 1000]);
		$shelf = $this->definition($author, $project, 'Open shelf');
		$this->postItem($author, $project, $floor->id, ['kind' => 'Custom', 'definitionId' => $shelf, 'x' => 2000, 'y' => 1000]);
		$this->definition($author, $project, 'Spare shelf');
		$material = Fixture::service(MaterialRepository::class)->findAllOrdered()[0];
		Fixture::service(MaterialPriceService::class)->merge(
			$project,
			[
				new MaterialPriceDto(materialId: $material->id, surface: null, pricePerArea: 42.5, pricePerLength: null),
				new MaterialPriceDto(materialId: null, surface: QuantitySurfaceEnum::Ceiling, pricePerArea: 12.5, pricePerLength: null),
			],
			'CZK',
			7,
		);

		return $project;
	}

	private function definition(User $author, Project $project, string $name): int
	{
		return Fixture::service(FurnitureDefinitionProvider::class)->create($author, new FurnitureDefinitionInputDto(
			name: $name,
			description: 'Oak, no back',
			scope: FurnitureScopeEnum::Project,
			projectId: $project->id,
			definition: [
				'format' => 1,
				'size' => ['width' => 800, 'depth' => 300, 'height' => 1800],
				'parameters' => [],
				'derived' => [],
				'materialSlots' => [['key' => 'carcass', 'label' => 'Carcass', 'default' => 'kitchen-oak']],
				'parts' => [['type' => 'board', 'name' => 'Top', 'x' => 0, 'y' => 'H - 18', 'z' => 0, 'w' => 'W', 'h' => 18, 'd' => 'D', 'material' => 'carcass']],
			],
		))->id;
	}

	/** @param array<string, mixed> $item */
	private function postItem(User $user, Project $project, int $floorId, array $item): void
	{
		self::assertSame(201, $this->request('POST', Fixture::elementsPath($project, $floorId), $item, $user)->getStatusCode());
	}

	private function uploadImage(User $user, Project $project): int
	{
		$image = imagecreatetruecolor(4, 2);
		self::assertNotFalse($image);
		ob_start();
		imagepng($image);
		$files = str_replace('{projectId:number}', (string) $project->id, Routes::ProjectFiles->value);
		$id = $this->jsonBody($this->upload($files, 'plan.png', (string) ob_get_clean(), $user))['id'];
		self::assertIsInt($id);

		return $id;
	}

	/** @return array<string, mixed> */
	private function scene(User $user, Project $project, ?int $floorId): array
	{
		self::assertIsInt($floorId);

		return $this->jsonBody(
			$this->request('GET', self::floorPath(Routes::ProjectFloorScene, $project, $floorId), authenticatedAs: $user),
		);
	}

	private static function floorPath(Routes $route, Project $project, int $floorId): string
	{
		return str_replace(['{projectId:number}', '{floorId:number}'], [(string) $project->id, (string) $floorId], $route->value);
	}

	/** @return array<mixed> */
	private function manifest(string $path): array
	{
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path));
		$json = $zip->getFromName(ProjectBundle::Manifest);
		$zip->close();
		self::assertIsString($json);

		return JsonDecoder::decodeObject($json);
	}

	/** The bundle without what differs between instances: ids (they only link entries), times and entry paths. */
	private static function comparable(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		$result = [];
		foreach ($value as $key => $entry) {
			if (
				is_string($key)
				&& (
					$key === 'id'
					|| str_ends_with($key, 'Id')
					|| in_array(
						$key,
						['updatedAt', 'exportedAt', 'path'],
						true,
					)
				)
			) {
				continue;
			}
			$result[$key] = self::comparable($entry);
		}

		return $result;
	}

	/** @return array<string, mixed> */
	private function emptyManifest(): array
	{
		return [
			'format' => ProjectBundle::Format,
			'kind' => ProjectBundle::Kind,
			'snapshot' => ['format' => 1, 'project' => ['name' => 'Imported', 'address' => null, 'description' => null], 'floors' => []],
			'files' => [],
			'models' => [],
			'definitions' => [],
		];
	}

	/**
	 * @param array<string, mixed>|null $manifest none: a zip without `project.json`
	 * @param array<string, string> $entries
	 */
	private function zip(?array $manifest, array $entries): string
	{
		$path = $this->path();
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
		if ($manifest !== null) {
			$zip->addFromString(ProjectBundle::Manifest, json_encode($manifest, JSON_THROW_ON_ERROR));
		}
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();

		return $path;
	}

	private function file(string $content): string
	{
		$path = $this->path();
		file_put_contents($path, $content);

		return $path;
	}

	private function path(): string
	{
		$path = sys_get_temp_dir() . '/izbik-test-' . bin2hex(random_bytes(6));
		$this->paths[] = $path;

		return $path;
	}
}

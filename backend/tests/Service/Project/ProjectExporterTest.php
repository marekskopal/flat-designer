<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Project;

use Izbik\Dto\FurnitureDefinitionInputDto;
use Izbik\Dto\MaterialPriceDto;
use Izbik\Dto\ModelAssetInputDto;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Service\Furniture\FurnitureDefinitionProvider;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\ModelAsset\ModelAssetProvider;
use Izbik\Service\Project\ProjectFileProvider;
use Izbik\Service\Project\Transfer\ProjectBundle;
use Izbik\Service\Project\Transfer\ProjectExporter;
use Izbik\Service\Quantities\MaterialPriceService;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\Glb;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use ZipArchive;

#[CoversClass(ProjectExporter::class)]
#[CoversClass(ProjectBundle::class)]
final class ProjectExporterTest extends IntegrationTestCase
{
	private User $user;

	private Project $project;

	protected function setUp(): void
	{
		parent::setUp();

		$this->user = Fixture::createUser();
		$this->project = Fixture::createProject($this->user, 'Flat 12');
		Fixture::furnishFloor(Fixture::firstFloor($this->project));
	}

	public function testJsonHoldsTheSnapshotPricingAndOwnDefinitionsWithoutFiles(): void
	{
		$definition = $this->ownDefinition();
		$material = Fixture::service(MaterialRepository::class)->findAllOrdered()[0];
		Fixture::service(MaterialPriceService::class)->merge(
			$this->project,
			[
				new MaterialPriceDto(materialId: $material->id, surface: null, pricePerArea: 42.5, pricePerLength: null),
				new MaterialPriceDto(materialId: null, surface: QuantitySurfaceEnum::Ceiling, pricePerArea: 12.5, pricePerLength: null),
			],
			'CZK',
			7,
		);
		$this->uploadFile('plan.txt', 'Measured on site.');

		$bundle = JsonDecoder::decodeObject(Fixture::service(ProjectExporter::class)->json($this->project));

		self::assertSame(ProjectBundle::Format, $bundle['format']);
		self::assertSame(ProjectBundle::Kind, $bundle['kind']);
		self::assertIsArray($bundle['snapshot']);
		self::assertSame('Flat 12', JsonDecoder::decodeObject((string) json_encode($bundle['snapshot']['project']))['name']);
		self::assertSame(
			[
				'currency' => 'CZK',
				'wastePercent' => 7,
				'prices' => [
					['materialCode' => $material->code, 'surface' => null, 'pricePerArea' => 42.5, 'pricePerLength' => null],
					['materialCode' => null, 'surface' => 'Ceiling', 'pricePerArea' => 12.5, 'pricePerLength' => null],
				],
			],
			$bundle['pricing'],
		);
		self::assertIsList($bundle['definitions']);
		self::assertSame([$definition], array_column($bundle['definitions'], 'id'));
		self::assertSame([], $bundle['files']);
		self::assertSame([], $bundle['models']);
	}

	public function testJsonKeepsEmptyObjectsOfTheDefinitions(): void
	{
		$this->ownDefinition();

		$json = Fixture::service(ProjectExporter::class)->json($this->project);

		$stored = Fixture::service(FurnitureDefinitionRepository::class)->findByProject($this->project->id)[0]->definition;
		self::assertStringContainsString($stored, $json);
	}

	public function testZipHoldsTheFilesAndModels(): void
	{
		$file = $this->uploadFile('Návrh kuchyně.txt', 'Measured on site.');
		$glb = Glb::box([-0.5, 0.0, -0.5], [0.5, 1.0, 0.5]);
		$model = Fixture::service(ModelAssetProvider::class)->upload(
			$this->user,
			new ModelAssetInputDto(
				name: 'Lamp',
				sourceUrl: 'https://example.com/lamp',
				scope: FurnitureScopeEnum::Project,
				projectId: $this->project->id,
			),
			'lamp.glb',
			$glb,
		);
		$path = tempnam(sys_get_temp_dir(), 'izbik-test');
		self::assertIsString($path);

		try {
			Fixture::service(ProjectExporter::class)->zip($this->project, $path);

			$zip = new ZipArchive();
			self::assertTrue($zip->open($path));
			$bundle = JsonDecoder::decodeObject((string) $zip->getFromName(ProjectBundle::Manifest));
			self::assertSame(
				[
					[
						'id' => $file,
						'filename' => 'Návrh kuchyně.txt',
						'mimeType' => 'text/plain',
						'path' => ProjectBundle::filePath(
							$file,
							'Návrh kuchyně.txt',
						)]],
				$bundle['files'],
			);
			self::assertSame('Measured on site.', $zip->getFromName(ProjectBundle::filePath($file, 'Návrh kuchyně.txt')));
			self::assertIsList($bundle['models']);
			self::assertIsArray($bundle['models'][0]);
			self::assertSame(['Lamp', 'https://example.com/lamp', ProjectBundle::modelPath($model->id)], [
				$bundle['models'][0]['name'],
				$bundle['models'][0]['sourceUrl'],
				$bundle['models'][0]['path'],
			]);
			self::assertSame($glb, $zip->getFromName(ProjectBundle::modelPath($model->id)));
			self::assertSame(3, $zip->numFiles);
			$zip->close();
		} finally {
			unlink($path);
		}
	}

	public function testFailedZipLeavesNothingBehind(): void
	{
		$this->uploadFile('plan.txt', 'Measured on site.');
		AppHarness::fileStorage()->reset();
		$path = sys_get_temp_dir() . '/izbik-test-' . bin2hex(random_bytes(4)) . '.zip';

		try {
			Fixture::service(ProjectExporter::class)->zip($this->project, $path);
			self::fail('The export of a project whose stored file is gone should fail.');
		} catch (RuntimeException) {
			self::assertFileDoesNotExist($path);
		}
	}

	public function testFileEntriesAreNamedByIdAndExtension(): void
	{
		self::assertSame('files/7.png', ProjectBundle::filePath(7, 'Plan 1:50?.PNG'));
		self::assertSame('files/7', ProjectBundle::filePath(7, 'README'));
		self::assertSame('files/7', ProjectBundle::filePath(7, 'notes.tar gz'));
	}

	private function ownDefinition(): int
	{
		return Fixture::service(FurnitureDefinitionProvider::class)->create($this->user, new FurnitureDefinitionInputDto(
			name: 'Open shelf',
			description: 'Oak, no back',
			scope: FurnitureScopeEnum::Project,
			projectId: $this->project->id,
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

	private function uploadFile(string $filename, string $body): int
	{
		return Fixture::service(ProjectFileProvider::class)->upload($this->user, $this->project, $filename, $body)->id;
	}
}

<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\DxfController;
use Izbik\Dto\DxfImportInputDto;
use Izbik\Dto\DxfSummaryDto;
use Izbik\Service\Dxf\DxfImporter;
use Izbik\Service\Dxf\DxfPlanOptions;
use Izbik\Tests\Support\Dxf;
use Izbik\Tests\Support\EditorTestCase;
use Izbik\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use function array_column;
use function sprintf;

#[CoversClass(DxfController::class)]
#[CoversClass(DxfImporter::class)]
#[CoversClass(DxfImportInputDto::class)]
#[CoversClass(DxfSummaryDto::class)]
#[CoversClass(DxfPlanOptions::class)]
final class DxfControllerTest extends EditorTestCase
{
	/**
	 * Two rooms in 300 mm outer walls (rings) split by a 100 mm wall with a door (gap and swing), a window over the south
	 * wall, and a furniture layer.
	 */
	private static function plan(): string
	{
		return Dxf::document([
			Dxf::rectangle('A-WALL', -300, -300, 4300, 3300),
			Dxf::rectangle('A-WALL', 0, 0, 4000, 3000),
			Dxf::rectangle('A-WALL', 1950, 0, 2050, 1000),
			Dxf::rectangle('A-WALL', 1950, 1800, 2050, 3000),
			Dxf::line('A-DOOR', 2050, 1000, 2850, 1000),
			Dxf::arc('A-DOOR', 2050, 1000, 800, 0, 90),
			Dxf::line('A-GLAZ', 500, -300, 1700, -300),
			Dxf::line('A-GLAZ', 500, -150, 1700, -150),
			Dxf::line('A-GLAZ', 500, 0, 1700, 0),
			Dxf::line('A-FURN', 100, 100, 900, 100),
			Dxf::line('A-WALL-OLD', 0, 5000, 3000, 5000),
		], units: 4, layers: ['A-WALL-OLD' => 1]);
	}

	public function testTheLayersWithTheirSuggestedRoles(): void
	{
		$fileId = $this->uploadDxf(self::plan());

		$summary = $this->jsonBody($this->request('GET', $this->dxfPath($fileId), authenticatedAs: $this->user));

		self::assertSame(4, $summary['units']);
		self::assertIsList($summary['layers']);
		// A frozen layer is suggested for nothing: it holds what the drawing hides.
		self::assertSame(['A-DOOR', 'A-FURN', 'A-GLAZ', 'A-WALL', 'A-WALL-OLD'], array_column($summary['layers'], 'name'));
		self::assertSame(['opening', null, 'opening', 'wall', null], array_column($summary['layers'], 'suggestedRole'));
		self::assertSame([1, 1, 3, 16, 1], array_column($summary['layers'], 'lines'));
		self::assertSame([false, false, false, false, true], array_column($summary['layers'], 'hidden'));
	}

	public function testThePreviewWritesNothing(): void
	{
		$fileId = $this->uploadDxf(self::plan());
		$revision = $this->revision();

		$plan = $this->jsonBody($this->edit('POST', 'dxf-import', body: self::body($fileId), suffix: '/preview'));

		self::assertIsList($plan['walls']);
		self::assertCount(5, $plan['walls']);
		self::assertIsList($plan['openings']);
		self::assertSame(['Door', 'Window'], self::sorted(array_column($plan['openings'], 'type')));
		self::assertSame($revision, $this->revision());
		self::assertSame([], $this->sceneList('walls'));
	}

	public function testTheImportIsOneChangeAndUndoesAsOne(): void
	{
		$fileId = $this->uploadDxf(self::plan());
		$revision = $this->revision();

		$response = $this->edit(
			'POST',
			'dxf-import',
			body: self::body($fileId) + ['wallHeight' => 2700, 'windowSill' => 600],
			baseRevision: $revision,
		);

		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$result = $this->jsonBody($response);
		self::assertSame([5, 2, []], [$result['walls'], $result['openings'], $result['warnings']]);
		self::assertIsArray($result['result']);
		self::assertSame($revision + 1, $result['result']['revision']);
		self::assertIsList($result['result']['batch']);
		self::assertCount(7, $result['result']['batch']);
		self::assertIsArray($result['result']['change']);
		self::assertSame('Imported plan.dxf: 5 wall(s) and their openings', $result['result']['change']['summary']);

		$walls = $this->sceneList('walls');
		self::assertSame([100, 300, 300, 300, 300], self::sorted(array_column($walls, 'thickness')));
		self::assertSame(['Interior', 'Interior', 'Interior', 'Interior', 'Interior'], array_column($walls, 'type'));
		self::assertSame([2700, 2700, 2700, 2700, 2700], array_column($walls, 'height'));
		$openings = [];
		foreach ($walls as $wall) {
			self::assertIsList($wall['openings']);
			foreach ($wall['openings'] as $opening) {
				self::assertIsArray($opening);
				self::assertIsString($opening['type']);
				$openings[$opening['type']] = $opening;
			}
		}
		self::assertSame([800, 'Start', 'Right'], [$openings['Door']['width'], $openings['Door']['hinge'], $openings['Door']['side']]);
		self::assertSame([1200, 600], [$openings['Window']['width'], $openings['Window']['sillHeight']]);

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([], $this->sceneList('walls'));
	}

	public function testReplacingTheFloorsWallsIsPartOfTheChange(): void
	{
		$old = $this->create('walls', ['startX' => 0, 'startY' => 5000, 'endX' => 3000, 'endY' => 5000]);
		$fileId = $this->uploadDxf(self::plan());

		$response = $this->edit('POST', 'dxf-import', body: self::body($fileId) + ['replaceExisting' => true]);

		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		self::assertNotContains(self::id($old), array_column($this->sceneList('walls'), 'id'));
		self::assertCount(5, $this->sceneList('walls'));

		$this->undo();
		self::assertSame([self::id($old)], array_column($this->sceneList('walls'), 'id'));
	}

	public function testAnOpeningTheEditorRefusesIsLeftOutWithAWarning(): void
	{
		$fileId = $this->uploadDxf(self::plan());

		// A window 2500 high over a 600 mm sill does not fit under a 2600 mm wall.
		$result = $this->jsonBody(
			$this->edit('POST', 'dxf-import', body: self::body($fileId) + ['windowSill' => 600, 'windowHeight' => 2500]),
		);

		self::assertSame(1, $result['openings']);
		self::assertIsList($result['warnings']);
		self::assertCount(1, $result['warnings']);
		self::assertIsString($result['warnings'][0]);
		self::assertStringContainsString('window 1200 mm wide', $result['warnings'][0]);
	}

	public function testNoWallsOnTheLayersImportsNothing(): void
	{
		$fileId = $this->uploadDxf(self::plan());
		$revision = $this->revision();

		$response = $this->edit('POST', 'dxf-import', body: ['fileId' => $fileId, 'wallLayers' => ['A-FURN']]);

		self::assertSame(422, $response->getStatusCode());
		self::assertSame($revision, $this->revision());
	}

	public function testAFileThatIsNoDxfAndAnotherProjectsFile(): void
	{
		$text = $this->jsonBody($this->upload($this->filesPath(), 'notes.txt', "Kitchen 3.6 m\n", $this->user))['id'];
		self::assertIsInt($text);
		$response = $this->request('GET', $this->dxfPath($text), authenticatedAs: $this->user);
		self::assertSame(422, $response->getStatusCode());
		self::assertStringContainsString('not an ASCII DXF', (string) $response->getBody());

		$other = Fixture::createUser(name: 'Mallory');
		self::assertSame(
			404,
			$this->request('GET', $this->dxfPath($this->uploadDxf(self::plan())), authenticatedAs: $other)->getStatusCode(),
		);
		$foreignProject = Fixture::createProject($other);
		$foreign = $this->jsonBody(
			$this->upload(sprintf('/api/projects/%d/files', $foreignProject->id), 'plan.dxf', self::plan(), $other),
		)['id'];
		self::assertIsInt($foreign);
		self::assertSame(404, $this->edit('POST', 'dxf-import', body: self::body($foreign), suffix: '/preview')->getStatusCode());
	}

	/** @return array<string, mixed> */
	private static function body(int $fileId): array
	{
		return ['fileId' => $fileId, 'wallLayers' => ['A-WALL'], 'openingLayers' => ['A-DOOR', 'A-GLAZ'], 'anchor' => 'Drawing'];
	}

	private function uploadDxf(string $body): int
	{
		$id = $this->jsonBody($this->upload($this->filesPath(), 'plan.dxf', $body, $this->user))['id'];
		self::assertIsInt($id);

		return $id;
	}

	private function filesPath(): string
	{
		return sprintf('/api/projects/%d/files', $this->project->id);
	}

	private function dxfPath(int $fileId): string
	{
		return sprintf('/api/projects/%d/files/%d/dxf', $this->project->id, $fileId);
	}

	/**
	 * @param array<mixed> $values
	 * @return list<mixed>
	 */
	private static function sorted(array $values): array
	{
		sort($values);

		return $values;
	}
}

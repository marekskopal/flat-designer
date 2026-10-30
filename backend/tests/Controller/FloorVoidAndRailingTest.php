<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\FloorVoidHandler;
use FlatDesigner\Service\Editor\Element\RailingHandler;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FloorVoidHandler::class)]
#[CoversClass(RailingHandler::class)]
final class FloorVoidAndRailingTest extends EditorTestCase
{
	private const array Well = [[1000, 1000], [3000, 1000], [3000, 2500], [1000, 2500]];

	public function testAddChangeDeleteAndUndoAVoid(): void
	{
		$oak = Fixture::service(MaterialRepository::class)->findAllOrdered()[0] ?? null;
		self::assertNotNull($oak);

		$void = $this->create('voids', ['polygon' => self::Well]);
		// JSON writes the whole 3 m² without a decimal point.
		self::assertEquals(['Void', self::Well, 3.0, null, null], [
			$void['name'],
			$void['polygon'],
			$void['area'],
			$void['liningMaterialId'],
			$void['liningDepth'],
		]);
		$changed = $this->jsonBody(
			$this->edit(
				'PATCH',
				'voids',
				self::id($void),
				['name' => 'Gallery void', 'liningMaterialId' => $oak->id, 'liningDepth' => 300],
			),
		);
		self::assertIsArray($changed['change']);
		self::assertSame('Changed void "Gallery void" (name, liningMaterialId, liningDepth)', $changed['change']['summary']);
		self::assertSame([[$oak->id, 300]], array_map(
			static fn (array $v): array => [$v['liningMaterialId'], $v['liningDepth']],
			$this->sceneList('voids'),
		));

		self::assertSame(200, $this->edit('DELETE', 'voids', self::id($void))->getStatusCode());
		self::assertSame([], $this->sceneList('voids'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['Gallery void'], array_column($this->sceneList('voids'), 'name'));
		self::assertSame(
			422,
			$this->edit('PATCH', 'voids', self::id($void), ['polygon' => [[0, 0], [1000, 1000], [1000, 0], [0, 1000]]])->getStatusCode(),
		);
	}

	public function testTheFloorBelowGetsTheVoidsAboveIt(): void
	{
		$upper = Fixture::service(FloorProvider::class)->create($this->project, new FloorInputDto('First floor', 1, 3000));
		$this->request(
			'POST',
			Fixture::elementsPath($this->project, $upper->id, 'voids'),
			['polygon' => self::Well, 'name' => 'Hall void'],
			$this->user,
		);

		$above = $this->scene()['voidsAbove'];
		self::assertIsArray($above);
		self::assertSame([$upper->id, 3000, $upper->slabThickness], [$above['floorId'], $above['rise'], $above['slabThickness']]);
		self::assertSame(['Hall void'], array_column(self::list($above['voids']), 'name'));

		// A version serves them alike.
		$versionId = $this->jsonBody(
			$this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user),
		)['id'];
		self::assertIsInt($versionId);
		$version = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $versionId, $this->floor->id),
			authenticatedAs: $this->user,
		));
		self::assertIsArray($version['voidsAbove']);
		self::assertSame([$upper->id, 3000], [$version['voidsAbove']['floorId'], $version['voidsAbove']['rise']]);
		self::assertSame(['Hall void'], array_column(self::list($version['voidsAbove']['voids']), 'name'));
	}

	public function testAddChangeAndUndoARailing(): void
	{
		$railing = $this->create('railings', ['points' => [[0, 0], [2000, 0], [2000, 1500]]]);
		self::assertSame(
			['Railing', 1000, 0, 'Balusters', null, 1200, 'Round', null, null],
			[
				$railing['name'],
				$railing['height'],
				$railing['baseZ'],
				$railing['style'],
				$railing['spacing'],
				$railing['postSpacing'],
				$railing['handrail'],
				$railing['materialId'],
				$railing['infillMaterialId'],
			],
		);

		$this->edit('PATCH', 'railings', self::id($railing), ['style' => 'Glass', 'handrail' => 'None', 'height' => 1100, 'baseZ' => 450]);
		self::assertSame([['Glass', 'None', 1100, 450]], array_map(
			static fn (array $r): array => [$r['style'], $r['handrail'], $r['height'], $r['baseZ']],
			$this->sceneList('railings'),
		));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['Balusters'], array_column($this->sceneList('railings'), 'style'));

		// Closed round a void: the last point repeats the first.
		$round = [[0, 0], [1000, 0], [1000, 1000], [0, 1000], [0, 0]];
		self::assertSame($round, $this->create('railings', ['points' => $round])['points']);
	}

	public function testRejectsARailingWithoutALine(): void
	{
		foreach ([[[0, 0]], [[0, 0], [5, 5]], [[0, 0], [1000, 'x']]] as $points) {
			self::assertSame(422, $this->edit('POST', 'railings', null, ['points' => $points])->getStatusCode());
		}
		self::assertSame(422, $this->edit('POST', 'railings', null, ['points' => [[0, 0], [1000, 0]], 'height' => 200])->getStatusCode());
	}

	public function testAStairTakesARailingStyleAWallHandrailAndALinedVoid(): void
	{
		$oak = Fixture::service(MaterialRepository::class)->findAllOrdered()[0] ?? null;
		self::assertNotNull($oak);
		$stair = $this->create('stairs', ['x' => 2000, 'y' => 500]);
		self::assertSame([null, null, 'None', null], [
			$stair['railingStyle'],
			$stair['railingMaterialId'],
			$stair['wallHandrail'],
			$stair['voidLiningMaterialId'],
		]);

		$fields = ['railingStyle' => 'Balusters', 'railingMaterialId' => $oak->id, 'wallHandrail' => 'Left', 'voidLiningMaterialId' => $oak->id];
		$changed = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($stair), $fields));
		self::assertIsArray($changed['element']);
		self::assertSame($fields, array_intersect_key($changed['element'], $fields));
		self::assertSame(422, $this->edit('PATCH', 'stairs', self::id($stair), ['wallHandrail' => 'Up'])->getStatusCode());
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['None'], array_column($this->sceneList('stairs'), 'wallHandrail'));
	}

	/** @return list<array<mixed>> */
	private static function list(mixed $value): array
	{
		self::assertIsList($value);
		$rows = [];
		foreach ($value as $row) {
			self::assertIsArray($row);
			$rows[] = $row;
		}

		return $rows;
	}
}

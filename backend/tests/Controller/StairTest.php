<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Service\Editor\Element\StairHandler;
use FlatDesigner\Service\Editor\StairRise;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StairHandler::class)]
#[CoversClass(StairRise::class)]
final class StairTest extends EditorTestCase
{
	public function testAddChangeDeleteAndUndo(): void
	{
		$stair = $this->create('stairs', ['x' => 2000, 'y' => 500]);
		self::assertSame(
			['Stairs', 'Straight', 2000, 500, 0, 900, 270, null, 'Left', 'Solid', 'Right'],
			[
				$stair['name'],
				$stair['shape'],
				$stair['x'],
				$stair['y'],
				$stair['rotation'],
				$stair['width'],
				$stair['going'],
				$stair['risers'],
				$stair['turn'],
				$stair['construction'],
				$stair['railing'],
			],
		);
		self::assertSame([self::id($stair)], array_column($this->sceneList('stairs'), 'id'));

		$changed = $this->jsonBody(
			$this->edit('PATCH', 'stairs', self::id($stair), ['shape' => 'LShaped', 'firstFlight' => 6, 'rotation' => -90]),
		);
		self::assertIsArray($changed['element']);
		self::assertSame(
			['LShaped', 6, 270],
			[$changed['element']['shape'], $changed['element']['firstFlight'], $changed['element']['rotation']],
		);
		self::assertIsArray($changed['change']);
		self::assertSame('Changed stairs "Stairs" (shape, rotation, firstFlight)', $changed['change']['summary']);

		self::assertSame(200, $this->edit('DELETE', 'stairs', self::id($stair))->getStatusCode());
		self::assertSame([], $this->sceneList('stairs'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['LShaped'], array_column($this->sceneList('stairs'), 'shape'));
	}

	public function testASpiralIsAsWideAsItsDiameter(): void
	{
		self::assertSame(1600, $this->create('stairs', ['shape' => 'Spiral'])['width']);
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['shape' => 'Spiral', 'width' => 900])->getStatusCode());
	}

	public function testTheRiseComesFromTheFloorAbove(): void
	{
		// The top floor: its tallest wall (2600 by default, none drawn) and a slab.
		$this->create('stairs', []);
		self::assertSame(2850, $this->scene()['stairRise']);

		$floors = Fixture::service(FloorProvider::class);
		$upper = $floors->create($this->project, new FloorInputDto('First floor', 1, 3100));
		self::assertSame(3100, $this->scene()['stairRise']);
		$floors->update($upper, new FloorInputDto('First floor', 1, 3300));
		self::assertSame(3300, $this->scene()['stairRise']);

		// The floor above gets the stairs coming up through it, with their rise.
		$above = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/floors/%d/scene', $this->project->id, $upper->id),
			authenticatedAs: $this->user,
		));
		self::assertIsArray($above['stairsBelow']);
		self::assertSame([$this->floor->id, 3300], [$above['stairsBelow']['floorId'], $above['stairsBelow']['rise']]);
		self::assertIsList($above['stairsBelow']['stairs']);
		self::assertCount(1, $above['stairsBelow']['stairs']);
		self::assertNull($this->scene()['stairsBelow']);
	}

	public function testRiserCountsAndTheLandingAreChecked(): void
	{
		// Over the 2850 mm rise: 10 risers would be 285 mm high, 16 are 178 mm.
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['risers' => 10])->getStatusCode());
		self::assertSame(16, $this->create('stairs', ['risers' => 16])['risers']);
		self::assertSame(
			422,
			$this->edit('POST', 'stairs', body: ['shape' => 'UShaped', 'risers' => 16, 'firstFlight' => 16])->getStatusCode(),
		);
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['going' => 400])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['railing' => 'Middle'])->getStatusCode());
	}

	public function testAVersionKeepsTheStairsAndTheirRise(): void
	{
		$this->create('stairs', ['shape' => 'UShaped', 'gap' => 200]);
		$upper = Fixture::service(FloorProvider::class)->create($this->project, new FloorInputDto('First floor', 1, 3000));
		$versionId = $this->jsonBody(
			$this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user),
		)['id'];
		self::assertIsInt($versionId);
		$scene = fn (int $floorId): array => $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $versionId, $floorId),
			authenticatedAs: $this->user,
		));

		$ground = $scene($this->floor->id);
		self::assertSame(3000, $ground['stairRise']);
		self::assertIsList($ground['stairs']);
		self::assertIsArray($ground['stairs'][0]);
		self::assertSame(['UShaped', 200], [$ground['stairs'][0]['shape'], $ground['stairs'][0]['gap']]);
		$first = $scene($upper->id);
		self::assertIsArray($first['stairsBelow']);
		self::assertSame([$this->floor->id, 3000], [$first['stairsBelow']['floorId'], $first['stairsBelow']['rise']]);
	}
}

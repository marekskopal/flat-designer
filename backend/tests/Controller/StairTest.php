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
		$spiral = $this->create('stairs', ['shape' => 'Spiral']);
		self::assertSame(1600, $spiral['width']);
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['shape' => 'Spiral', 'width' => 900])->getStatusCode());

		// A shape change keeps a width the new shape allows, else takes its default: a 3.5 m spiral is too wide a flight.
		$wide = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($spiral), ['width' => 3500]))['element'];
		self::assertIsArray($wide);
		self::assertSame(3500, $wide['width']);
		$straight = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($spiral), ['shape' => 'Straight']))['element'];
		self::assertIsArray($straight);
		self::assertSame(['Straight', 900], [$straight['shape'], $straight['width']]);
		$narrow = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($spiral), ['width' => 1400, 'shape' => 'Spiral']))['element'];
		self::assertIsArray($narrow);
		self::assertSame(1400, $narrow['width']);
		$back = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($spiral), ['shape' => 'LShaped']))['element'];
		self::assertIsArray($back);
		self::assertSame(1400, $back['width']);
	}

	public function testAMezzanineOnTheSameLevelSharesNoStairs(): void
	{
		$this->create('stairs', []);
		$floors = Fixture::service(FloorProvider::class);
		$first = $floors->create($this->project, new FloorInputDto('First floor', 1, 3000));
		$mezzanine = $floors->create($this->project, new FloorInputDto('Mezzanine', 1, 3200));
		// The ground floor's stairs climb to the lower of the two and come up through it alone.
		self::assertSame(3000, $this->scene()['stairRise']);
		$scene = fn (int $floorId): array => $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/floors/%d/scene', $this->project->id, $floorId),
			authenticatedAs: $this->user,
		));
		self::assertIsArray($scene($first->id)['stairsBelow']);
		self::assertNull($scene($mezzanine->id)['stairsBelow']);
	}

	public function testAChangedRiseDoesNotBlockEditingTheStair(): void
	{
		// 16 risers over 2850 mm are 178 mm each; over 4000 mm they would be 250 mm, which only a new count is held to.
		$stair = $this->create('stairs', ['shape' => 'LShaped', 'risers' => 16, 'firstFlight' => 8]);
		$floors = Fixture::service(FloorProvider::class);
		$floors->create($this->project, new FloorInputDto('First floor', 1, 4000));
		$moved = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($stair), ['x' => 3000, 'name' => 'Main stairs']))['element'];
		self::assertIsArray($moved);
		self::assertSame([3000, 16, 8], [$moved['x'], $moved['risers'], $moved['firstFlight']]);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'stairs', self::id($stair), ['risers' => 17])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'stairs', self::id($stair), ['firstFlight' => 20])->getStatusCode());
		$counted = $this->jsonBody($this->edit('PATCH', 'stairs', self::id($stair), ['risers' => 20, 'firstFlight' => 10]))['element'];
		self::assertIsArray($counted);
		self::assertSame(20, $counted['risers']);
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

	public function testGardenStepsHaveARiseOfTheirOwnAndStayOnTheirFloor(): void
	{
		// Over 900 mm: 5 risers of 180 mm; 3 would be 300 mm, too high.
		$steps = $this->create('stairs', ['x' => 9000, 'y' => 2000, 'rise' => 900, 'baseZ' => -900, 'risers' => 5]);
		self::assertSame([900, -900, 5], [$steps['rise'], $steps['baseZ'], $steps['risers']]);
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['rise' => 900, 'risers' => 3])->getStatusCode());
		// Fewer than two risers of 140 mm.
		self::assertSame(422, $this->edit('POST', 'stairs', body: ['rise' => 200])->getStatusCode());
		$this->create('stairs', []);

		// Only the stair up to the next floor comes up through it; a version keeps both fields.
		$upper = Fixture::service(FloorProvider::class)->create($this->project, new FloorInputDto('First floor', 1, 3000));
		$above = $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/floors/%d/scene', $this->project->id, $upper->id),
			authenticatedAs: $this->user,
		));
		self::assertIsArray($above['stairsBelow']);
		self::assertIsList($above['stairsBelow']['stairs']);
		self::assertCount(1, $above['stairsBelow']['stairs']);
		$versionId = $this->jsonBody(
			$this->request('POST', sprintf('/api/projects/%d/versions', $this->project->id), [], $this->user),
		)['id'];
		self::assertIsInt($versionId);
		$version = fn (int $floorId): array => $this->jsonBody($this->request(
			'GET',
			sprintf('/api/projects/%d/versions/%d/floors/%d/scene', $this->project->id, $versionId, $floorId),
			authenticatedAs: $this->user,
		));
		self::assertIsList($version($this->floor->id)['stairs']);
		self::assertSame([900, null], array_column($version($this->floor->id)['stairs'], 'rise'));
		$first = $version($upper->id);
		self::assertIsArray($first['stairsBelow']);
		self::assertIsList($first['stairsBelow']['stairs']);
		self::assertCount(1, $first['stairsBelow']['stairs']);
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

<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\DrawingController;
use FlatDesigner\Dto\RoomDetectInputDto;
use FlatDesigner\Dto\WallPolylineInputDto;
use FlatDesigner\Service\Editor\BatchWriter;
use FlatDesigner\Service\Editor\ChangeBatch;
use FlatDesigner\Service\Editor\Drawing\RoomOutliner;
use FlatDesigner\Service\Editor\Drawing\WallDrawer;
use FlatDesigner\Service\Editor\Geometry\RoomDetector;
use FlatDesigner\Service\Editor\UndoService;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Tests\Support\EditorTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DrawingController::class)]
#[CoversClass(WallDrawer::class)]
#[CoversClass(RoomOutliner::class)]
#[CoversClass(RoomDetector::class)]
#[CoversClass(WallPolylineInputDto::class)]
#[CoversClass(RoomDetectInputDto::class)]
#[CoversClass(BodyReader::class)]
#[CoversClass(BatchWriter::class)]
#[CoversClass(ChangeBatch::class)]
#[CoversClass(UndoService::class)]
final class DrawingControllerTest extends EditorTestCase
{
	/** Centre lines of 300 mm outer walls around the inner area 0..4000 × 0..3000. */
	private const array Outline = [[-150, -150], [4150, -150], [4150, 3150], [-150, 3150]];

	public function testAClosedRunOfWallsIsOneChangeAndUndoesAsOne(): void
	{
		$revision = $this->revision();

		$result = $this->drawWalls(['points' => self::Outline, 'closed' => true, 'thickness' => 300, 'type' => 'Exterior']);

		self::assertSame($revision + 1, $result['revision']);
		self::assertIsArray($result['change']);
		self::assertSame('Drew 4 wall(s)', $result['change']['summary']);
		self::assertIsList($result['batch']);
		self::assertCount(4, $result['batch']);
		$walls = $this->sceneList('walls');
		self::assertCount(4, $walls);
		self::assertSame([300, 300, 300, 300], array_column($walls, 'thickness'));
		self::assertSame(['Exterior', 'Exterior', 'Exterior', 'Exterior'], array_column($walls, 'type'));
		// The last wall closes the run back to the first point.
		self::assertSame([-150, 3150, -150, -150], [$walls[3]['startX'], $walls[3]['startY'], $walls[3]['endX'], $walls[3]['endY']]);
		self::assertSame($revision + 1, $this->revision());

		$undo = $this->jsonBody($this->undo());
		self::assertIsList($undo['batch']);
		self::assertCount(4, $undo['batch']);
		self::assertSame([], $this->sceneList('walls'));

		self::assertSame(200, $this->redo()->getStatusCode());
		self::assertCount(4, $this->sceneList('walls'));
	}

	public function testAnInvalidSegmentChangesNothing(): void
	{
		$revision = $this->revision();

		$response = $this->edit('POST', 'walls', body: ['points' => [[0, 0], [3000, 0], [3050, 0]]], suffix: '/polyline');

		self::assertSame(422, $response->getStatusCode());
		self::assertStringContainsString('at least 100 mm', (string) $response->getBody());
		self::assertSame($revision, $this->revision());
		self::assertSame([], $this->sceneList('walls'));

		self::assertSame(422, $this->edit('POST', 'walls', body: ['points' => [[0, 0]]], suffix: '/polyline')->getStatusCode());
		self::assertSame(
			422,
			$this->edit('POST', 'walls', body: ['points' => [[0, 0], [3000, 0]], 'startX' => 5], suffix: '/polyline')->getStatusCode(),
		);
		self::assertSame(400, $this->edit('POST', 'walls', body: ['points' => [[0, 0], [3000]]], suffix: '/polyline')->getStatusCode());
	}

	public function testAStaleRevisionIsRejected(): void
	{
		$revision = $this->revision();

		$response = $this->edit('POST', 'walls', body: ['points' => [[0, 0], [3000, 0]]], baseRevision: $revision + 1, suffix: '/polyline');

		self::assertSame(409, $response->getStatusCode());
	}

	public function testDetectsTheRoomsOnEachSideOfAPartition(): void
	{
		$this->drawWalls(['points' => self::Outline, 'closed' => true, 'thickness' => 300]);
		$this->drawWalls(['points' => [[2000, -150], [2000, 3150]]]);

		$left = $this->detectRoom(['x' => 1000, 'y' => 1500, 'name' => 'Bedroom', 'type' => 'Bedroom']);
		$right = $this->detectRoom(['x' => 3000, 'y' => 1500]);

		self::assertIsArray($left['element']);
		self::assertSame([[0, 0], [1950, 0], [1950, 3000], [0, 3000]], $left['element']['polygon']);
		self::assertSame('Bedroom', $left['element']['name']);
		self::assertIsArray($right['element']);
		self::assertSame([[2050, 0], [4000, 0], [4000, 3000], [2050, 3000]], $right['element']['polygon']);
		self::assertCount(2, $this->sceneList('rooms'));
	}

	public function testDetectingOnAWallOrInTheOpenFails(): void
	{
		$this->drawWalls(['points' => [[-150, -150], [4150, -150], [4150, 3150]], 'thickness' => 300]);
		$revision = $this->revision();

		$onWall = $this->edit('POST', 'rooms', body: ['x' => 2000, 'y' => -100], suffix: '/detect');
		$open = $this->edit('POST', 'rooms', body: ['x' => 2000, 'y' => 1500], suffix: '/detect');

		self::assertSame(422, $onWall->getStatusCode());
		self::assertStringContainsString('inside a wall', (string) $onWall->getBody());
		self::assertSame(422, $open->getStatusCode());
		self::assertStringContainsString('No walls close', (string) $open->getBody());
		self::assertSame($revision, $this->revision());
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function drawWalls(array $body): array
	{
		$response = $this->edit('POST', 'walls', body: $body, suffix: '/polyline');
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		return $this->jsonBody($response);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function detectRoom(array $body): array
	{
		$response = $this->edit('POST', 'rooms', body: $body, suffix: '/detect');
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		return $this->jsonBody($response);
	}
}

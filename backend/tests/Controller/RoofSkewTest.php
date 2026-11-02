<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Service\Editor\Element\RoofDetails;
use Izbik\Service\Editor\Element\RoofHandler;
use Izbik\Service\Editor\Element\RoofSkew;
use Izbik\Service\Project\SnapshotRoofRestorer;
use Izbik\Tests\Support\EditorTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

/** A gable's or shed's skewed verges, a flat roof's skewed ends. */
#[CoversClass(RoofSkew::class)]
#[CoversClass(RoofHandler::class)]
#[CoversClass(RoofDetails::class)]
#[CoversClass(SnapshotRoofRestorer::class)]
final class RoofSkewTest extends EditorTestCase
{
	private const array Gable = ['shape' => 'Gable', 'x' => 4000, 'y' => 3000, 'width' => 8000, 'depth' => 6000, 'eaveHeight' => 2600];

	public function testAGableTakesSkewedVergesAndUndoSquaresThem(): void
	{
		$roof = $this->create('roofs', self::Gable);
		self::assertSame([0, 0], [$roof['startSkew'], $roof['endSkew']]);

		self::assertSame(200, $this->edit('PATCH', 'roofs', self::id($roof), ['startSkew' => 5, 'endSkew' => -12])->getStatusCode());
		self::assertSame([[5, -12]], $this->skews());
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([[0, 0]], $this->skews());

		$shed = $this->create('roofs', ['shape' => 'Shed', 'startSkew' => -45, 'endSkew' => 45] + self::Gable);
		self::assertSame([-45, 45], [$shed['startSkew'], $shed['endSkew']]);

		// A flat roof's ends follow a skewed wall too.
		$flat = $this->create('roofs', ['shape' => 'Flat', 'startSkew' => 5, 'parapet' => 300] + self::Gable);
		self::assertSame([5, 0, 300], [$flat['startSkew'], $flat['endSkew'], $flat['parapet']]);
	}

	public function testRejectsSkewsTheRoofCannotHave(): void
	{
		$roof = self::id($this->create('roofs', self::Gable));
		$rejected = [
			['startSkew' => 46],
			['endSkew' => -46],
			['endSkew' => 'steep'],
			// 6.5 m wide and 6 m deep, the verges turned 45° apart leave the back eaves 6500 − 2 · 3000 = 500 mm long.
			['width' => 6500, 'startSkew' => -45, 'endSkew' => 45],
			// The front eaves of a gable skewed 10° at its end reach 3000 · tan 10° = 528 mm further: 4528 is beyond them.
			['endSkew' => 10, 'downpipes' => [['edge' => 'Front', 'along' => 4600]]],
			['endSkew' => 10, 'downpipes' => [['edge' => 'Back', 'along' => 3500]]],
		];
		foreach ($rejected as $fields) {
			self::assertSame(
				422,
				$this->edit('PATCH', 'roofs', $roof, $fields)->getStatusCode(),
				json_encode($fields, JSON_THROW_ON_ERROR),
			);
		}
		self::assertSame(
			200,
			$this->edit('PATCH', 'roofs', $roof, ['endSkew' => 10, 'downpipes' => [['edge' => 'Front', 'along' => 4500]]])->getStatusCode(),
		);

		// Turned 30° with a 600 mm overhang, the front gutter starts at −4000 + 3000 · tan 30° + 600 · tan 30° − 100 / cos 30°
		// = −2037, inside the eaves line's start at −2268: a downpipe's outlet must reach the gutter.
		$deep = self::id($this->create('roofs', ['startSkew' => 30, 'overhang' => 600, 'vergeOverhang' => 100] + self::Gable));
		self::assertSame(
			422,
			$this->edit('PATCH', 'roofs', $deep, ['downpipes' => [['edge' => 'Front', 'along' => -2200]]])->getStatusCode(),
		);
		self::assertSame(
			200,
			$this->edit('PATCH', 'roofs', $deep, ['downpipes' => [['edge' => 'Front', 'along' => -2000]]])->getStatusCode(),
		);

		self::assertSame(422, $this->edit('POST', 'roofs', null, ['shape' => 'Hip', 'startSkew' => 5] + self::Gable)->getStatusCode());
		// A skewed gable made a hip leaves its skews behind.
		self::assertSame(200, $this->edit('PATCH', 'roofs', $roof, ['shape' => 'Hip'])->getStatusCode());
		self::assertSame([[0, 0], [30, 0]], $this->skews());
	}

	public function testAVersionKeepsTheSkews(): void
	{
		$this->create('roofs', ['startSkew' => 7, 'endSkew' => -3] + self::Gable);
		$project = sprintf('/api/projects/%d', $this->project->id);
		$version = $this->jsonBody($this->request('POST', $project . '/versions', [], $this->user))['id'];
		self::assertIsInt($version);
		self::assertSame(
			200,
			$this->request('POST', sprintf('%s/versions/%d/restore', $project, $version), [], $this->user)->getStatusCode(),
		);

		$floors = $this->jsonBody($this->request('GET', $project, authenticatedAs: $this->user))['floors'];
		self::assertIsList($floors);
		self::assertIsArray($floors[0]);
		self::assertIsInt($floors[0]['id']);
		$scene = $this->jsonBody(
			$this->request('GET', sprintf('%s/floors/%d/scene', $project, $floors[0]['id']), authenticatedAs: $this->user),
		);
		self::assertIsList($scene['roofs']);
		self::assertIsArray($scene['roofs'][0]);
		self::assertSame([7, -3], [$scene['roofs'][0]['startSkew'], $scene['roofs'][0]['endSkew']]);
	}

	/** @return list<array{mixed, mixed}> */
	private function skews(): array
	{
		return array_map(static fn (array $r): array => [$r['startSkew'], $r['endSkew']], $this->sceneList('roofs'));
	}
}

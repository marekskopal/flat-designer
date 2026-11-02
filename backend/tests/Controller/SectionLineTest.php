<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Service\Editor\Element\SectionLineHandler;
use Izbik\Service\Project\SnapshotSectionRestorer;
use Izbik\Tests\Support\EditorTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SectionLineHandler::class)]
#[CoversClass(SnapshotSectionRestorer::class)]
final class SectionLineTest extends EditorTestCase
{
	public function testAddChangeDeleteAndUndoASectionLine(): void
	{
		$line = $this->create('sections', ['startX' => 0, 'startY' => 2000, 'endX' => 6000, 'endY' => 2000]);
		self::assertSame(['A', 0, 2000, 6000, 2000, null], [
			$line['name'],
			$line['startX'],
			$line['startY'],
			$line['endX'],
			$line['endY'],
			$line['depth'],
		]);
		// The next one takes the next letter.
		self::assertSame('B', $this->create('sections', ['startX' => 3000, 'startY' => -1000, 'endX' => 3000, 'endY' => 5000])['name']);

		$changed = $this->jsonBody($this->edit('PATCH', 'sections', self::id($line), ['depth' => 4000, 'endX' => 8000]));
		self::assertIsArray($changed['change']);
		self::assertSame('Changed section line "A" (endX, depth)', $changed['change']['summary']);
		self::assertSame([[8000, 4000], [3000, null]], array_map(
			static fn (array $l): array => [$l['endX'], $l['depth']],
			$this->sceneList('sections'),
		));

		self::assertSame(200, $this->edit('DELETE', 'sections', self::id($line))->getStatusCode());
		self::assertSame(['B'], array_column($this->sceneList('sections'), 'name'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['A', 'B'], array_column($this->sceneList('sections'), 'name'));
	}

	public function testACopyLiesAMetreTheWayItLooksUnderTheNextName(): void
	{
		$line = $this->create('sections', ['startX' => 0, 'startY' => 2000, 'endX' => 6000, 'endY' => 2000]);
		$copy = $this->jsonBody($this->edit('POST', 'sections', self::id($line), suffix: '/duplicate'))['element'];
		self::assertIsArray($copy);
		// It looks north (to the left of west → east), so it lies a metre north.
		self::assertSame(['B', 0, 3000, 6000, 3000], [$copy['name'], $copy['startX'], $copy['startY'], $copy['endX'], $copy['endY']]);
	}

	public function testRejectsAShortLineAndAShallowDepth(): void
	{
		$line = $this->create('sections', []);
		self::assertSame(
			422,
			$this->edit('PATCH', 'sections', self::id($line), ['endX' => $line['startX'], 'endY' => $line['startY']])->getStatusCode(),
		);
		self::assertSame(422, $this->edit('PATCH', 'sections', self::id($line), ['depth' => 50])->getStatusCode());
	}

	public function testAVersionKeepsTheLinesAndARestoreBringsThemBack(): void
	{
		$this->create('sections', ['name' => 'A', 'startX' => 0, 'startY' => 2000, 'endX' => 6000, 'endY' => 2000, 'depth' => 3000]);
		$project = sprintf('/api/projects/%d', $this->project->id);
		$version = $this->jsonBody($this->request('POST', sprintf('%s/versions', $project), [], $this->user))['id'];
		self::assertIsInt($version);
		$this->edit('DELETE', 'sections', self::id($this->sceneList('sections')[0]));

		$stored = $this->jsonBody($this->request(
			'GET',
			sprintf('%s/versions/%d/floors/%d/scene', $project, $version, $this->floor->id),
			authenticatedAs: $this->user,
		));
		self::assertIsList($stored['sections']);
		self::assertSame(['A'], array_column($stored['sections'], 'name'));

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
		self::assertIsList($scene['sections']);
		self::assertIsArray($scene['sections'][0]);
		self::assertSame(['A', 6000, 3000], [$scene['sections'][0]['name'], $scene['sections'][0]['endX'], $scene['sections'][0]['depth']]);
	}
}

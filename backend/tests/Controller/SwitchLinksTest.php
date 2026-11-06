<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Dto\LightingDto;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Route\Routes;
use Izbik\Service\Editor\Element\SwitchControls;
use Izbik\Service\Editor\GroupEditor;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Project\SnapshotSwitchControls;
use Izbik\Service\Schedule\LightingCircuits;
use Izbik\Tests\Support\EditorTestCase;
use Izbik\Tests\Support\Fixture;
use MarekSkopal\ORM\Database\DatabaseInterface;
use Migrations\SeedLightingCatalogueMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(SwitchControls::class)]
#[CoversClass(SnapshotSwitchControls::class)]
#[CoversClass(LightingCircuits::class)]
#[CoversClass(LightingDto::class)]
#[CoversClass(GroupEditor::class)]
final class SwitchLinksTest extends EditorTestCase
{
	private int $switch;

	private int $downlight;

	private int $socket;

	protected function setUp(): void
	{
		parent::setUp();

		FurnitureSeeds::insert(Fixture::service(DatabaseInterface::class)->getPdo(), SeedLightingCatalogueMigration::Files);
		$this->switch = $this->seeded('Light switch (rocker)');
		$this->downlight = $this->seeded('Downlight, recessed round');
		$this->socket = $this->seeded('Socket outlet (Schuko)');
	}

	public function testASwitchControlsLightsOfItsFloor(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$a = $this->point($this->downlight, 1000, 1000, 'A');
		$b = $this->point($this->downlight, 2000, 1000, 'B');

		// Kept distinct and sorted, so a reorder is no change.
		$response = $this->edit('PATCH', 'items', $switch, ['controls' => [$b, $a, $b]]);
		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		self::assertSame([$a, $b], $this->item($switch)['controls']);
		self::assertSame([], $this->item($a)['controls']);
		self::assertNull($this->jsonBody($this->edit('PATCH', 'items', $switch, ['controls' => [$a, $b]]))['change']);

		// Undo takes the links back.
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([], $this->item($switch)['controls']);
	}

	public function testRefusesWhatIsNotASwitchOrALight(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$light = $this->point($this->downlight, 1000, 1000, 'Light');
		$socket = $this->point($this->socket, 0, 2000, 'Socket');
		$box = self::id($this->create('items', ['name' => 'Box', 'x' => 3000, 'y' => 0]));
		$upstairs = $this->request('POST', str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectFloors->value), [
			'name' => 'Upstairs',
			'level' => 1,
		], $this->user);
		self::assertSame(201, $upstairs->getStatusCode(), (string) $upstairs->getBody());
		$ground = $this->floor;
		$this->floor = Fixture::service(FloorRepository::class)->findOne(['id' => self::id($this->jsonBody($upstairs))]) ?? self::fail(
			'No floor.',
		);
		$upstairsLight = $this->point($this->downlight, 1000, 1000, 'Upstairs light');
		$this->floor = $ground;

		$this->assertRefused($socket, [$light], 'Only a switch');
		$this->assertRefused($switch, [$socket], sprintf('Item %d is not a light', $socket));
		$this->assertRefused($switch, [$box], sprintf('Item %d is not a light', $box));
		$this->assertRefused($switch, [$switch], 'cannot control itself');
		$this->assertRefused($switch, [$upstairsLight], 'on its own floor only');
		$this->assertRefused($switch, [0], 'list of item ids');
		$this->assertRefused($switch, range(1, SwitchControls::MaxControls + 1), 'at most 50');
	}

	public function testADeletedLightIsSkippedAndDoesNotBlockTheSwitch(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$light = $this->point($this->downlight, 1000, 1000, 'Light');
		$this->edit('PATCH', 'items', $switch, ['controls' => [$light]]);
		self::assertSame(200, $this->edit('PATCH', 'items', $switch, ['controls' => []])->getStatusCode());
		self::assertSame(200, $this->edit('DELETE', 'items', $light)->getStatusCode());

		// Undoing the unlink after the light went: the deleted light is listed again (and skipped where read).
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(200, $this->undo()->getStatusCode(), 'undoing the unlink');
		self::assertSame([$light], $this->item($switch)['controls']);
		$moved = $this->edit('PATCH', 'items', $switch, ['x' => 500]);
		self::assertSame(200, $moved->getStatusCode(), (string) $moved->getBody());
		self::assertSame(200, $this->edit('DELETE', 'items', $light)->getStatusCode());

		$lighting = $this->lighting();
		self::assertSame([], $lighting['circuits']);
		self::assertSame([$switch], array_column(self::list($lighting['unlinked']), 'itemId'));
	}

	public function testCopiesLinkTheirLights(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$inside = $this->point($this->downlight, 1000, 1000, 'Inside');
		$outside = $this->point($this->downlight, 5000, 1000, 'Outside');
		$this->edit('PATCH', 'items', $switch, ['controls' => [$inside, $outside]]);

		// A copy of the switch alone is a second switch for the same lights.
		$copy = self::id($this->jsonBody($this->edit('POST', 'items', $switch, suffix: '/duplicate'))['element']);
		self::assertSame([$inside, $outside], $this->item($copy)['controls']);

		// A group's copy switches the copies of its lights; the light outside the group stays.
		$group = self::id(
			$this->jsonBody($this->edit('POST', 'groups', body: ['name' => 'Room', 'itemIds' => [$switch, $inside]]))['element'],
		);
		$response = $this->edit('POST', 'groups', $group, [], suffix: '/duplicate');
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$copyGroup = self::id($this->jsonBody($response)['element']);
		$copies = array_values(array_filter($this->sceneList('items'), static fn (array $i): bool => $i['groupId'] === $copyGroup));
		[$switchCopy, $lightCopy] = $copies[0]['name'] === 'Switch' ? $copies : array_reverse($copies);
		$expected = [self::id($lightCopy), $outside];
		sort($expected);
		self::assertSame($expected, $this->item(self::id($switchCopy))['controls']);
	}

	public function testANewVariantIsSwitchedByTheSwitchesOutsideItsSet(): void
	{
		$wallSwitch = $this->point($this->switch, 0, 0, 'Wall switch');
		$pendant = $this->point($this->downlight, 1000, 1000, 'Pendant');
		$ownSwitch = $this->point($this->switch, 2000, 0, 'Own switch');
		$this->edit('PATCH', 'items', $wallSwitch, ['controls' => [$pendant]]);
		$this->edit('PATCH', 'items', $ownSwitch, ['controls' => [$pendant]]);
		$group = self::id(
			$this->jsonBody($this->edit('POST', 'groups', body: ['name' => 'Kitchen A', 'itemIds' => [$pendant, $ownSwitch]]))['element'],
		);
		$set = self::id(
			$this->jsonBody($this->edit('POST', 'variant-sets', body: ['name' => 'Kitchen', 'groupIds' => [$group]]))['element'],
		);

		$response = $this->edit('POST', 'variant-sets', $set, ['fromGroupId' => $group], suffix: '/variants');
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		$items = array_column($this->sceneList('items'), null, 'id');
		$copies = array_filter($items, static fn (array $i): bool => !in_array($i['id'], [$wallSwitch, $pendant, $ownSwitch], true));
		$byName = array_column($copies, null, 'name');
		$pendantCopy = self::id($byName['Pendant']);
		// The shared switch works with either variant; each variant's own switch with its own pendant.
		self::assertSame([$pendant, $pendantCopy], $items[$wallSwitch]['controls']);
		self::assertSame([$pendant], $items[$ownSwitch]['controls']);
		self::assertSame([$pendantCopy], $byName['Own switch']['controls']);
		$lighting = $this->lighting();
		self::assertSame([], $lighting['unswitched']);
		self::assertSame([], $lighting['unlinked']);
	}

	public function testAnItemNoLongerASwitchDropsItsLights(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$light = $this->point($this->downlight, 1000, 1000, 'Light');
		$this->edit('PATCH', 'items', $switch, ['controls' => [$light]]);

		$response = $this->edit('PATCH', 'items', $switch, ['definitionId' => $this->socket, 'params' => ['values' => new stdClass()]]);
		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		self::assertSame([], $this->item($switch)['controls']);

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([$light], $this->item($switch)['controls']);
	}

	public function testTheScheduleListsTheCircuits(): void
	{
		$hall = self::id(
			$this->create('rooms', ['code' => 'R1', 'name' => 'Hall', 'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]]]),
		);
		$bottom = $this->point($this->switch, 100, 5, 'Bottom');
		$top = $this->point($this->switch, 3900, 5, 'Top');
		$a = $this->point($this->downlight, 1000, 1500, 'Light A');
		$b = $this->point($this->downlight, 3000, 1500, 'Light B');
		$lone = $this->point($this->downlight, 8000, 8000, 'Lone light');
		$idle = $this->point($this->switch, 8000, 9000, 'Idle');
		// A two-way pair: both switches control both lights, through one link each.
		$this->edit('PATCH', 'items', $bottom, ['controls' => [$a, $b]]);
		$this->edit('PATCH', 'items', $top, ['controls' => [$b]]);

		$lighting = $this->lighting();

		$circuits = self::list($lighting['circuits']);
		self::assertCount(1, $circuits);
		self::assertIsArray($circuits[0]);
		self::assertSame(1, $circuits[0]['number']);
		self::assertSame([$bottom, $top], array_column(self::list($circuits[0]['switches']), 'itemId'));
		self::assertSame([$a, $b], array_column(self::list($circuits[0]['lights']), 'itemId'));
		self::assertSame(['R1', 'R1'], array_column(self::list($circuits[0]['lights']), 'roomCode'));
		self::assertSame([$hall, $hall], array_column(self::list($circuits[0]['switches']), 'roomId'));
		self::assertSame([$lone], array_column(self::list($lighting['unswitched']), 'itemId'));
		self::assertSame([null], array_column(self::list($lighting['unswitched']), 'roomCode'));
		self::assertSame([$idle], array_column(self::list($lighting['unlinked']), 'itemId'));
	}

	public function testASwitchControlsAnLedStripListedAsALight(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$strip = self::id(
			$this->create('items', ['kind' => 'LedStrip', 'name' => 'Cove', 'x' => 1000, 'y' => 1000, 'params' => ['facing' => 'up']]),
		);
		$unswitched = self::id($this->create('items', ['kind' => 'LedStrip', 'name' => 'Plinth', 'x' => 2000, 'y' => 1000]));

		$response = $this->edit('PATCH', 'items', $switch, ['controls' => [$strip]]);
		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		self::assertSame([$strip], $this->item($switch)['controls']);

		$lighting = $this->lighting();
		$circuits = self::list($lighting['circuits']);
		self::assertCount(1, $circuits);
		self::assertIsArray($circuits[0]);
		self::assertSame([$strip], array_column(self::list($circuits[0]['lights']), 'itemId'));
		self::assertSame([$unswitched], array_column(self::list($lighting['unswitched']), 'itemId'));
		// The schedule lists both strips as lights in one row.
		$floor = self::list($this->jsonBody($this->request('GET', $this->schedulePath(), authenticatedAs: $this->user))['floors'])[0];
		self::assertIsArray($floor);
		$rooms = self::list($floor['rooms']);
		self::assertIsArray($rooms[0]);
		$rows = self::list($rooms[0]['rows']);
		self::assertIsArray($rows[0]);
		self::assertSame(
			['light', 0, 'LED strip', 2],
			[$rows[0]['category'], $rows[0]['definitionId'], $rows[0]['name'], $rows[0]['units']],
		);

		// A facing the viewer does not draw is refused.
		$bad = $this->edit('PATCH', 'items', $strip, ['params' => ['facing' => 'sideways']]);
		self::assertSame(422, $bad->getStatusCode(), (string) $bad->getBody());
	}

	public function testARestoreLinksTheRestoredLights(): void
	{
		$switch = $this->point($this->switch, 0, 0, 'Switch');
		$light = $this->point($this->downlight, 1000, 1000, 'Light');
		$gone = $this->point($this->downlight, 2000, 1000, 'Gone');
		$this->edit('PATCH', 'items', $switch, ['controls' => [$light, $gone]]);
		$this->edit('DELETE', 'items', $gone);
		$version = $this->request('POST', self::path(Routes::ProjectVersions), [], $this->user);
		$versionId = self::id($this->jsonBody($version));

		$restore = $this->request('POST', self::path(Routes::ProjectVersionRestore, $versionId), [], $this->user);
		self::assertSame(200, $restore->getStatusCode(), (string) $restore->getBody());

		$this->floor = Fixture::firstFloor($this->project);
		$items = array_column($this->sceneList('items'), null, 'name');
		self::assertSame([self::id($items['Light'])], $items['Switch']['controls']);
		self::assertNotSame($light, self::id($items['Light']));
	}

	/** @param list<int> $controls */
	private function assertRefused(int $itemId, array $controls, string $message): void
	{
		$response = $this->edit('PATCH', 'items', $itemId, ['controls' => $controls]);
		self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
		self::assertStringContainsString($message, self::stringField($this->jsonBody($response)['message']));
	}

	private function point(int $definitionId, int $x, int $y, string $name): int
	{
		return self::id($this->create('items', [
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'name' => $name,
			'x' => $x,
			'y' => $y,
			'params' => ['values' => new stdClass()],
		]));
	}

	/** @return array<string, mixed> */
	private function item(int $id): array
	{
		$items = array_column($this->sceneList('items'), null, 'id');
		self::assertArrayHasKey($id, $items);

		return $items[$id];
	}

	/** @return array<string, mixed> the floor's `lighting` */
	private function lighting(): array
	{
		$floors = self::list($this->jsonBody($this->request('GET', $this->schedulePath(), authenticatedAs: $this->user))['floors']);
		self::assertIsArray($floors[0]);
		self::assertIsArray($floors[0]['lighting']);
		/** @var array<string, mixed> $lighting */
		$lighting = $floors[0]['lighting'];

		return $lighting;
	}

	private function schedulePath(): string
	{
		return str_replace(
			['{projectId:number}', '{floorId:number}'],
			[(string) $this->project->id, (string) $this->floor->id],
			Routes::ProjectFloorElectricalSchedule->value,
		);
	}

	/** @return list<mixed> */
	private static function list(mixed $value): array
	{
		self::assertIsList($value);

		return $value;
	}

	private function seeded(string $name): int
	{
		$definition = Fixture::service(FurnitureDefinitionRepository::class)->findOne(
			['name' => $name, 'user_id' => null, 'project_id' => null],
		);

		return $definition->id ?? self::fail(sprintf('No seeded definition "%s".', $name));
	}

	private function path(Routes $route, ?int $versionId = null): string
	{
		return str_replace(
			['{projectId:number}', '{versionId:number}'],
			[(string) $this->project->id, (string) $versionId],
			$route->value,
		);
	}
}

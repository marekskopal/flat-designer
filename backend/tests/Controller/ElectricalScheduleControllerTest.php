<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\ElectricalScheduleController;
use Izbik\Dto\ElectricalTotalsDto;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Route\Routes;
use Izbik\Service\Furniture\DefinitionCategoryEnum;
use Izbik\Service\Furniture\FurnitureSeeds;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Schedule\ElectricalScheduleService;
use Izbik\Tests\Support\EditorTestCase;
use Izbik\Tests\Support\Fixture;
use MarekSkopal\ORM\Database\DatabaseInterface;
use Migrations\SeedLightingCatalogueMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(ElectricalScheduleController::class)]
#[CoversClass(ElectricalScheduleService::class)]
#[CoversClass(ElectricalTotalsDto::class)]
#[CoversClass(DefinitionCategoryEnum::class)]
final class ElectricalScheduleControllerTest extends EditorTestCase
{
	public function testCountsThePointsPerRoomByValuesAndElevation(): void
	{
		$kitchen = self::id(
			$this->create('rooms', ['code' => 'R1', 'name' => 'Kitchen', 'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]]]),
		);
		$hall = self::id(
			$this->create('rooms', ['code' => 'R2', 'name' => 'Hall', 'polygon' => [[4000, 0], [6000, 0], [6000, 3000], [4000, 3000]]]),
		);
		FurnitureSeeds::insert(Fixture::service(DatabaseInterface::class)->getPdo(), SeedLightingCatalogueMigration::Files);
		$socket = $this->seeded('Socket outlet (Schuko)');
		$switch = $this->seeded('Light switch (rocker)');
		$downlight = $this->seeded('Downlight, recessed round');
		$data = $this->seeded('Data socket (RJ45)');
		$pendant = $this->seeded('Pendant, bare bulb');

		// Two double sockets above the worktop and a single one at skirting height, against the kitchen's north and south walls.
		foreach ([1000, 1500] as $x) {
			$this->point($socket, $x, 2995, 1100, ['gangs' => 2], width: 151);
		}
		$this->point($socket, 2000, 5, 300);
		// The kitchen's switch stands in the hall's outline but is linked to the kitchen.
		$this->point($switch, 4100, 5, 1050, roomId: $kitchen);
		$this->point($downlight, 5000, 1500, 2500);
		// Pendants differing only in what the schedule does not list (`sag`) share a row.
		$this->point($pendant, 4500, 1000, 1800, ['sag' => 60]);
		$this->point($pendant, 5500, 1000, 1800, ['sag' => 200]);
		// Outside every room.
		$this->point($data, 8000, 8000, 300);
		// Not counted: a box, and a socket of the hidden variant.
		$box = self::id($this->create('items', ['name' => 'Island', 'x' => 2000, 'y' => 1500]));
		$hiddenSocket = self::id($this->point($socket, 5000, 5, 300));
		$groups = [];
		foreach (['Island' => $box, 'Socket' => $hiddenSocket] as $name => $item) {
			$groups[] = self::id($this->jsonBody($this->edit('POST', 'groups', body: ['name' => $name, 'itemIds' => [$item]]))['element']);
		}
		self::assertSame(201, $this->edit('POST', 'variant-sets', body: ['name' => 'Layout', 'groupIds' => $groups])->getStatusCode());

		$schedule = $this->schedule(false);

		self::assertIsList($schedule['floors']);
		$floor = $schedule['floors'][0];
		self::assertIsArray($floor);
		self::assertIsList($floor['rooms']);
		self::assertSame([$kitchen, $hall, null], array_column($floor['rooms'], 'id'));
		[$kitchenRow, $hallRow, $outside] = $floor['rooms'];
		self::assertIsArray($kitchenRow);
		self::assertIsArray($hallRow);
		self::assertIsArray($outside);
		self::assertSame(
			[
				['socket', 'Socket outlet (Schuko)', ['gangs' => 1], 300, 1, 1],
				['socket', 'Socket outlet (Schuko)', ['gangs' => 2], 1100, 2, 4],
				['switch', 'Light switch (rocker)', ['rockers' => 'single'], 1050, 1, 1],
			],
			self::rows($kitchenRow['rows']),
		);
		self::assertSame(
			[
				['light', 'Downlight, recessed round', ['lumens' => 550, 'kelvin' => 3000, 'angle' => 70], 2500, 1, 1],
				['light', 'Pendant, bare bulb', ['lumens' => 400, 'kelvin' => 2200], 1800, 2, 2],
			],
			self::rows($hallRow['rows']),
		);
		self::assertEquals(self::totals(sockets: 3, outlets: 5, switches: 1), $kitchenRow['totals']);
		self::assertEquals(self::totals(lights: 3), $hallRow['totals']);
		self::assertSame('Not in a room', $outside['name']);
		self::assertEquals(self::totals(dataSockets: 1, dataPorts: 2), $outside['totals']);
		$total = self::totals(lights: 3, sockets: 3, outlets: 5, switches: 1, dataSockets: 1, dataPorts: 2);
		self::assertEquals($total, $floor['totals']);
		self::assertEquals($total, $schedule['totals']);

		// The floor's schedule is the same here; another user gets nothing.
		self::assertEquals($total, $this->schedule(true)['totals']);
		self::assertSame(404, $this->request('GET', $this->path(false), authenticatedAs: Fixture::createUser())->getStatusCode());
		self::assertSame(404, $this->request('GET', $this->path(true), authenticatedAs: Fixture::createUser())->getStatusCode());
	}

	public function testUserDefinitionsCountByTheirCategory(): void
	{
		$definition = [
			'format' => 1,
			'category' => 'socket',
			'size' => ['width' => 80, 'depth' => 40, 'height' => 80],
			'parts' => [['type' => 'board', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => 'H', 'd' => 'D']],
		];
		$response = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			['name' => 'Floor box', 'definition' => $definition],
			$this->user,
		);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$this->point(self::id($this->jsonBody($response)), 1000, 1000, 0);

		$schedule = $this->schedule(false);

		self::assertEquals(self::totals(sockets: 1, outlets: 1), $schedule['totals']);

		// Without schedule settings (older JSON) a socket counts its int `gangs`, as before them.
		$older = [...$definition, 'parameters' => [['key' => 'gangs', 'type' => 'int', 'default' => 1]]];
		$response = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			['name' => 'Old socket', 'definition' => $older],
			$this->user,
		);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$this->point(self::id($this->jsonBody($response)), 2000, 1000, 300, ['gangs' => 3]);
		self::assertEquals(self::totals(sockets: 2, outlets: 4), $this->schedule(false)['totals']);

		$definition['category'] = 'heating';
		$invalid = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			['name' => 'Radiator', 'definition' => $definition],
			$this->user,
		);
		self::assertSame(422, $invalid->getStatusCode());
		self::assertStringStartsWith('category: Must be one of light, socket', self::stringField($this->jsonBody($invalid)['message']));
	}

	public function testDefinitionsAreNamedInTheRequestsLanguage(): void
	{
		$definition = [
			'format' => 1,
			'category' => 'socket',
			'translations' => ['cs' => ['name' => 'Podlahová krabice']],
			'size' => ['width' => 80, 'depth' => 40, 'height' => 80],
			'parts' => [['type' => 'board', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => 'H', 'd' => 'D']],
		];
		$response = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			['name' => 'Floor box', 'definition' => $definition],
			$this->user,
		);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$this->point(self::id($this->jsonBody($response)), 1000, 1000, 0);

		$czech = (string) $this->request(
			'GET',
			$this->path(false),
			authenticatedAs: $this->user,
			headers: ['Accept-Language' => 'cs'],
		)->getBody();
		$english = (string) $this->request('GET', $this->path(false), authenticatedAs: $this->user)->getBody();

		$room = static function (string $json): array {
			$room = JsonDecoder::decodeObject($json);
			foreach (['floors', 0, 'rooms', 0] as $key) {
				self::assertIsArray($room);
				$room = $room[$key] ?? null;
			}
			self::assertIsArray($room);
			self::assertIsArray($room['rows']);
			self::assertIsArray($room['rows'][0]);

			return [$room['name'] ?? null, $room['rows'][0]['name'] ?? null];
		};
		self::assertSame(['Mimo místnosti', 'Podlahová krabice'], $room($czech));
		self::assertSame(['Not in a room', 'Floor box'], $room($english));
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function point(int $definitionId, int $x, int $y, int $z, array $values = [], int $width = 80, ?int $roomId = null): array
	{
		return $this->create('items', [
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'x' => $x,
			'y' => $y,
			'z' => $z,
			'width' => $width,
			'roomId' => $roomId,
			'params' => ['values' => $values === [] ? new stdClass() : $values],
		]);
	}

	private function seeded(string $name): int
	{
		$definition = Fixture::service(FurnitureDefinitionRepository::class)->findOne(
			['name' => $name, 'user_id' => null, 'project_id' => null],
		);

		return $definition->id ?? self::fail(sprintf('No seeded definition "%s".', $name));
	}

	/** @return list<array{mixed, mixed, mixed, mixed, mixed, mixed}> category, name, values, z, count, units */
	private static function rows(mixed $rows): array
	{
		self::assertIsList($rows);

		return array_map(static function (mixed $row): array {
			self::assertIsArray($row);

			return [$row['category'], $row['name'], $row['values'], $row['z'], $row['count'], $row['units']];
		}, $rows);
	}

	/** @return array<string, int> */
	private static function totals(
		int $lights = 0,
		int $sockets = 0,
		int $outlets = 0,
		int $switches = 0,
		int $dataSockets = 0,
		int $dataPorts = 0,
		int $plumbingPoints = 0,
	): array {
		return compact('lights', 'sockets', 'outlets', 'switches', 'dataSockets', 'dataPorts', 'plumbingPoints');
	}

	/** @return array<string, mixed> */
	private function schedule(bool $floor): array
	{
		return $this->jsonBody($this->request('GET', $this->path($floor), authenticatedAs: $this->user));
	}

	private function path(bool $floor): string
	{
		return str_replace(
			['{projectId:number}', '{floorId:number}'],
			[(string) $this->project->id, (string) $this->floor->id],
			($floor ? Routes::ProjectFloorElectricalSchedule : Routes::ProjectElectricalSchedule)->value,
		);
	}
}

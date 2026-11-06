<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\ElementController;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Service\Editor\Element\RoomHandler;
use Izbik\Service\Editor\Element\RoomZones;
use Izbik\Service\Json\RoomZonesDecoder;
use Izbik\Tests\Support\EditorTestCase;
use Izbik\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ElementController::class)]
#[CoversClass(RoomHandler::class)]
#[CoversClass(RoomZones::class)]
#[CoversClass(RoomZonesDecoder::class)]
final class RoomZonesTest extends EditorTestCase
{
	private const array Outline = [[0, 0], [4000, 0], [4000, 3000], [0, 3000]];

	public function testFloorAndCeilingZonesAreKeptAndUndone(): void
	{
		$oak = self::materialId('kitchen-oak');
		$room = $this->create('rooms', ['name' => 'Kitchen', 'polygon' => self::Outline, 'ceilingHeight' => 2600]);
		self::assertSame([[], []], [$room['floorZones'], $room['ceilingZones']]);

		$floorZone = ['polygon' => [[0, 0], [1000, 0], [1000, 2000], [0, 2000]], 'materialId' => $oak];
		// A corner within a millimetre of the outline counts as on it.
		$ceilingZone = ['polygon' => [[0, 2400], [4001, 2400], [4000, 3000], [0, 3000]], 'height' => 2300, 'materialId' => null];
		$changed = $this->jsonBody($this->edit('PATCH', 'rooms', self::id($room), [
			'floorZones' => [$floorZone],
			'ceilingZones' => [$ceilingZone],
		]));
		self::assertIsArray($changed['element']);
		self::assertSame([$floorZone], $changed['element']['floorZones']);
		self::assertSame([$ceilingZone], $changed['element']['ceilingZones']);
		self::assertSame([[$floorZone]], array_column($this->sceneList('rooms'), 'floorZones'));

		// [] removes them.
		$cleared = $this->jsonBody($this->edit('PATCH', 'rooms', self::id($room), ['floorZones' => [], 'ceilingZones' => []]));
		self::assertIsArray($cleared['element']);
		self::assertSame([[], []], [$cleared['element']['floorZones'], $cleared['element']['ceilingZones']]);

		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([[$ceilingZone]], array_column($this->sceneList('rooms'), 'ceilingZones'));
	}

	public function testZonesAreValidated(): void
	{
		$oak = self::materialId('kitchen-oak');
		$room = $this->create('rooms', ['name' => 'Kitchen', 'polygon' => self::Outline, 'ceilingHeight' => 2600]);
		$square = [[0, 0], [1000, 0], [1000, 1000], [0, 1000]];
		$invalid = [
			// No material, an unknown one, outside the room, too few corners, crossing itself.
			['floorZones' => [['polygon' => $square]]],
			['floorZones' => [['polygon' => $square, 'materialId' => 999999]]],
			['floorZones' => [['polygon' => [[0, 0], [5000, 0], [5000, 1000], [0, 1000]], 'materialId' => $oak]]],
			['floorZones' => [['polygon' => [[0, 0], [1000, 0]], 'materialId' => $oak]]],
			['floorZones' => [['polygon' => [[0, 0], [1000, 1000], [1000, 0], [0, 1000]], 'materialId' => $oak]]],
			['floorZones' => array_fill(0, 21, ['polygon' => $square, 'materialId' => $oak])],
			['floorZones' => 'tiles'],
			// Too low, too close to the ceiling, without a height.
			['ceilingZones' => [['polygon' => $square, 'height' => 900]]],
			['ceilingZones' => [['polygon' => $square, 'height' => 2560]]],
			['ceilingZones' => [['polygon' => $square]]],
		];
		foreach ($invalid as $body) {
			self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), $body)->getStatusCode(), (string) json_encode($body));
		}

		// Only under a flat ceiling: not in a vaulted room, nor in the open air.
		$zone = ['polygon' => $square, 'height' => 2300];
		self::assertSame(
			422,
			$this->edit('PATCH', 'rooms', self::id($room), ['ceilingZones' => [$zone], 'vaultSpacing' => 600])->getStatusCode(),
		);
		self::assertSame(
			422,
			$this->edit('PATCH', 'rooms', self::id($room), ['ceilingZones' => [$zone], 'ceilingHeight' => null])->getStatusCode(),
		);
		self::assertSame(200, $this->edit('PATCH', 'rooms', self::id($room), ['ceilingZones' => [$zone]])->getStatusCode());
		// A ceiling lowered under a zone's soffit is refused while the zone is there.
		self::assertSame(422, $this->edit('PATCH', 'rooms', self::id($room), ['ceilingHeight' => 2320])->getStatusCode());
	}

	private static function materialId(string $code): int
	{
		$material = Fixture::service(MaterialRepository::class)->findByCode($code);
		self::assertNotNull($material);

		return $material->id;
	}
}

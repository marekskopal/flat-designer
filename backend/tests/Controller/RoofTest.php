<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\RoofHandler;
use FlatDesigner\Service\Editor\Element\RoofWindowHandler;
use FlatDesigner\Service\Editor\Element\RoofWindowPlacement;
use FlatDesigner\Service\Editor\Element\StructureSize;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RoofHandler::class)]
#[CoversClass(RoofWindowHandler::class)]
#[CoversClass(RoofWindowPlacement::class)]
#[CoversClass(StructureSize::class)]
final class RoofTest extends EditorTestCase
{
	public function testANewRoofCoversTheFloorsWalls(): void
	{
		// A 9 × 6 m box of 300 mm exterior walls, 2700 mm high; the outer faces are 9.3 × 6.3 m.
		$this->box(9000, 6000);

		$roof = $this->create('roofs', []);
		self::assertSame(
			['Roof', 'Gable', 4500, 3000, 0, 9300, 6300, 2700, 35, 400, 200, 250, null],
			[
				$roof['name'],
				$roof['shape'],
				$roof['x'],
				$roof['y'],
				$roof['rotation'],
				$roof['width'],
				$roof['depth'],
				$roof['eaveHeight'],
				$roof['pitch'],
				$roof['overhang'],
				$roof['vergeOverhang'],
				$roof['thickness'],
				$roof['parapet'],
			],
		);
		self::assertSame([self::id($roof)], array_column($this->sceneList('roofs'), 'id'));
	}

	public function testTheRidgeRunsAlongTheLongerSide(): void
	{
		$this->box(6000, 10000);

		$roof = $this->create('roofs', ['shape' => 'Hip']);
		self::assertSame([3000, 5000, 90, 10300, 6300], [$roof['x'], $roof['y'], $roof['rotation'], $roof['width'], $roof['depth']]);
	}

	public function testAddChangeDeleteAndUndoWithItsWindows(): void
	{
		$roof = $this->create('roofs', ['x' => 5000, 'y' => 4000, 'width' => 10000, 'depth' => 8000, 'pitch' => 45]);
		$window = $this->create('roof-windows', ['roofId' => self::id($roof)]);
		// Low on the front plane: 400 mm in plan above the eaves (at −4400 with the overhang); 1180 mm up a 45° slope is 834 in plan.
		self::assertSame(
			[self::id($roof), 'Roof window', 'CentrePivot', 0, -3583, 780, 1180],
			[$window['roofId'], $window['name'], $window['type'], $window['along'], $window['across'], $window['width'], $window['length']],
		);
		$roofs = $this->sceneList('roofs');
		self::assertIsList($roofs[0]['windows']);
		self::assertSame([self::id($window)], array_column($roofs[0]['windows'], 'id'));

		$changed = $this->jsonBody($this->edit('PATCH', 'roofs', self::id($roof), ['shape' => 'Hip', 'rotation' => -90, 'x' => 6000]));
		self::assertIsArray($changed['element']);
		self::assertSame(['Hip', 270, 6000], [$changed['element']['shape'], $changed['element']['rotation'], $changed['element']['x']]);
		self::assertArrayNotHasKey('windows', $changed['element']);
		self::assertIsArray($changed['change']);
		self::assertSame('Changed roof "Roof" (shape, x, rotation)', $changed['change']['summary']);

		// Deleting the roof hides its window; undo brings both back.
		self::assertSame(200, $this->edit('DELETE', 'roofs', self::id($roof))->getStatusCode());
		self::assertSame([], $this->sceneList('roofs'));
		self::assertSame(404, $this->edit('PATCH', 'roof-windows', self::id($window), ['name' => 'Gone'])->getStatusCode());
		$refused = $this->edit('POST', 'roof-windows', body: ['roofId' => self::id($roof)]);
		self::assertSame(422, $refused->getStatusCode());
		self::assertStringContainsString('is deleted; undo that first', (string) $refused->getBody());
		self::assertSame(200, $this->undo()->getStatusCode());
		$restored = $this->sceneList('roofs');
		self::assertSame(['Hip'], array_column($restored, 'shape'));
		self::assertIsList($restored[0]['windows']);
		self::assertSame([self::id($window)], array_column($restored[0]['windows'], 'id'));
	}

	public function testFieldsAreChecked(): void
	{
		self::assertSame(422, $this->edit('POST', 'roofs', body: ['pitch' => 80])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'roofs', body: ['shape' => 'Mansard'])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'roofs', body: ['width' => 500])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'roofs', body: ['parapet' => 50])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'roofs', body: ['overhang' => 2500])->getStatusCode());

		$flat = $this->create('roofs', ['shape' => 'Flat', 'parapet' => 900, 'coveringMaterialId' => $this->materialId('roof-membrane')]);
		self::assertSame([900, $this->materialId('roof-membrane')], [$flat['parapet'], $flat['coveringMaterialId']]);
	}

	public function testRoofWindowsStayInsideTheirPlane(): void
	{
		// A 45° gable 10 × 8 m: each plane runs 4 m from its eaves to the ridge in plan (4.4 m with the overhang).
		$roof = $this->create('roofs', ['x' => 5000, 'y' => 4000, 'width' => 10000, 'depth' => 8000, 'pitch' => 45]);
		$add = fn (array $fields): int => $this->edit(
			'POST',
			'roof-windows',
			body: ['roofId' => self::id($roof)] + $fields,
		)->getStatusCode();

		// Off the roof, over the ridge, 300 mm short of a verge.
		self::assertSame(422, $add(['across' => 6000]));
		self::assertSame(422, $add(['across' => -200]));
		self::assertSame(422, $add(['along' => 4800]));
		self::assertSame(201, $add(['along' => -3000, 'across' => -2000]));
		// Overlapping that one, on the same plane; on the back plane the same spot along the ridge is free.
		self::assertSame(422, $add(['along' => -2500, 'across' => -2200]));
		self::assertSame(201, $add(['along' => -3000, 'across' => 2000]));

		$flat = $this->create('roofs', ['shape' => 'Flat', 'x' => 20000]);
		self::assertSame(422, $this->edit('POST', 'roof-windows', body: ['roofId' => self::id($flat)])->getStatusCode());

		// A window keeps its place on the roof when the roof moves, and cannot change roofs.
		$window = $this->create('roof-windows', ['roofId' => self::id($roof), 'along' => 3000, 'across' => 2000, 'type' => 'TopHung']);
		$this->edit('PATCH', 'roofs', self::id($roof), ['x' => 6000, 'rotation' => 30]);
		$roofs = $this->sceneList('roofs');
		self::assertIsList($roofs[0]['windows']);
		self::assertIsArray($roofs[0]['windows'][2]);
		self::assertSame([3000, 2000], [$roofs[0]['windows'][2]['along'], $roofs[0]['windows'][2]['across']]);
		self::assertSame(422, $this->edit('PATCH', 'roof-windows', self::id($window), ['roofId' => self::id($flat)])->getStatusCode());
	}

	public function testAWindowTheRoofLeftBehindIsStillRenamedAndUndone(): void
	{
		$roof = $this->create('roofs', ['x' => 5000, 'y' => 4000, 'width' => 10000, 'depth' => 8000, 'pitch' => 45]);
		$window = $this->create('roof-windows', ['roofId' => self::id($roof), 'along' => 4000, 'across' => -2000]);
		$this->edit('PATCH', 'roof-windows', self::id($window), ['name' => 'Bathroom']);
		// Narrowed to 6 m, the roof no longer reaches the window; its placement is checked only when set anew.
		self::assertSame(200, $this->edit('PATCH', 'roofs', self::id($roof), ['width' => 6000])->getStatusCode());
		$renamed = $this->jsonBody($this->edit('PATCH', 'roof-windows', self::id($window), ['name' => 'Bathroom skylight']));
		self::assertIsArray($renamed['element']);
		self::assertSame('Bathroom skylight', $renamed['element']['name']);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'roof-windows', self::id($window), ['width' => 800])->getStatusCode());
	}

	public function testADefaultWindowFitsAHipDeeperThanWide(): void
	{
		$roof = $this->create(
			'roofs',
			['shape' => 'Hip', 'x' => 0, 'y' => 0, 'width' => 6000, 'depth' => 10000, 'overhang' => 0, 'pitch' => 40],
		);
		// The front plane is a triangle up to t = −2000; the window sits where it is widest, 400 mm above the eaves at −5000.
		$window = $this->create('roof-windows', ['roofId' => self::id($roof)]);
		self::assertSame([0, -4148], [$window['along'], $window['across']]);
	}

	public function testCopiesAreShiftedBesideTheOriginal(): void
	{
		$roof = $this->create('roofs', ['x' => 5000, 'y' => 4000, 'width' => 10000, 'depth' => 8000, 'pitch' => 45]);
		$window = $this->create('roof-windows', ['roofId' => self::id($roof), 'along' => 0, 'across' => -2000, 'width' => 780]);
		$copy = $this->jsonBody($this->edit('POST', 'roof-windows', self::id($window), [], suffix: '/duplicate'));
		self::assertIsArray($copy['element']);
		self::assertSame([880, -2000], [$copy['element']['along'], $copy['element']['across']]);
		// Past the 780 mm window with a 100 mm gap; a second copy of the original lands on the first one.
		self::assertSame(422, $this->edit('POST', 'roof-windows', self::id($window), [], suffix: '/duplicate')->getStatusCode());
		$roofCopy = $this->jsonBody($this->edit('POST', 'roofs', self::id($roof), [], suffix: '/duplicate'));
		self::assertIsArray($roofCopy['element']);
		self::assertSame(5100, $roofCopy['element']['x']);
	}

	public function testOnlyAFlatRoofHasAParapet(): void
	{
		self::assertSame(422, $this->edit('POST', 'roofs', body: ['shape' => 'Gable', 'parapet' => 900])->getStatusCode());
		$flat = $this->create('roofs', ['shape' => 'Flat', 'parapet' => 900]);
		// Pitched, the roof leaves its parapet behind (the inspector sends the shape alone); one given anew is refused.
		$hip = $this->jsonBody($this->edit('PATCH', 'roofs', self::id($flat), ['shape' => 'Hip']))['element'];
		self::assertIsArray($hip);
		self::assertSame(['Hip', null], [$hip['shape'], $hip['parapet']]);
		self::assertSame(422, $this->edit('PATCH', 'roofs', self::id($flat), ['parapet' => 900])->getStatusCode());
		self::assertSame(422, $this->edit('PATCH', 'roofs', self::id($flat), ['shape' => 'Flat', 'parapet' => 50])->getStatusCode());
	}

	public function testAWallRisesToTheRoof(): void
	{
		$wall = $this->create('walls', ['startX' => 0, 'startY' => 0, 'endX' => 0, 'endY' => 6000]);
		self::assertFalse($wall['upToRoof']);

		$gable = $this->jsonBody($this->edit('PATCH', 'walls', self::id($wall), ['upToRoof' => true]));
		self::assertIsArray($gable['element']);
		self::assertTrue($gable['element']['upToRoof']);
		self::assertIsArray($gable['change']);
		self::assertSame('Changed wall "Wall" (upToRoof)', $gable['change']['summary']);
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame([false], array_column($this->sceneList('walls'), 'upToRoof'));
	}

	public function testAColumnUnderARoofReachesIt(): void
	{
		$polygon = [[0, 0], [8000, 0], [8000, 6000], [0, 6000]];
		$attic = $this->create('rooms', ['name' => 'Attic', 'polygon' => $polygon, 'ceilingHeight' => 2600]);
		// A 45° gable from eaves at 1000 mm: the underside is 2000 mm high 1000 mm in from the eaves, 4000 mm at the ridge.
		$this->create('roofs', ['x' => 4000, 'y' => 3000, 'width' => 8000, 'depth' => 6000, 'pitch' => 45, 'eaveHeight' => 1000]);

		$low = $this->create('items', ['kind' => 'Column', 'roomId' => self::id($attic), 'x' => 4000, 'y' => 1000]);
		$middle = $this->create('items', ['kind' => 'Column', 'roomId' => self::id($attic), 'x' => 4000, 'y' => 3000]);
		self::assertSame([0, 2000], [$low['z'], $low['height']]);
		// Under the ridge the flat ceiling is lower than the roof.
		self::assertSame(2600, $middle['height']);

		// A raised deck under the overhang, where the roof comes down to its floor: the ceiling counts.
		$deck = $this->create(
			'rooms',
			['name' => 'Deck', 'polygon' => [[0, -400], [8000, -400], [8000, 0], [0, 0]], 'ceilingHeight' => 2400, 'floorElevation' => 900],
		);
		$post = $this->create('items', ['kind' => 'Column', 'roomId' => self::id($deck), 'x' => 4000, 'y' => -200]);
		self::assertSame([900, 2400], [$post['z'], $post['height']]);
	}

	public function testRoofMaterialsAreSeeded(): void
	{
		$tiles = Fixture::service(MaterialRepository::class)->findByCode('roof-tiles-clay');
		self::assertNotNull($tiles);
		self::assertSame([300, 420], [$tiles->tileWidth, $tiles->tileHeight]);
		self::assertNotNull(Fixture::service(MaterialRepository::class)->findByCode('roof-slate'));
	}

	/** Four exterior walls 300 mm thick and 2700 mm high round a rectangle of centre lines from the origin. */
	private function box(int $width, int $depth): void
	{
		$corners = [[0, 0], [$width, 0], [$width, $depth], [0, $depth]];
		foreach ($corners as $i => [$x, $y]) {
			[$endX, $endY] = $corners[($i + 1) % 4];
			$this->create('walls', [
				'startX' => $x,
				'startY' => $y,
				'endX' => $endX,
				'endY' => $endY,
				'thickness' => 300,
				'height' => 2700,
				'type' => 'Exterior',
			]);
		}
	}

	private function materialId(string $code): int
	{
		$material = Fixture::service(MaterialRepository::class)->findByCode($code);
		self::assertNotNull($material);

		return $material->id;
	}
}

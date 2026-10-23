<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\PlotHandler;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PlotHandler::class)]
final class PlotTest extends EditorTestCase
{
	private const array Boundary = [[-2000, -3000], [12000, -3000], [12000, 9000], [-2000, 9000]];

	public function testAddChangeDeleteAndUndo(): void
	{
		$gravel = Fixture::service(MaterialRepository::class)->findAllOrdered()[0] ?? null;
		self::assertNotNull($gravel);

		$plot = $this->create('plots', ['polygon' => self::Boundary]);
		// JSON writes the whole 168 m² without a decimal point.
		self::assertEquals(
			['Plot', self::Boundary, 168.0, null],
			[$plot['name'], $plot['polygon'], $plot['area'], $plot['groundMaterialId']],
		);
		self::assertSame([self::id($plot)], array_column($this->sceneList('plots'), 'id'));

		$changed = $this->jsonBody(
			$this->edit('PATCH', 'plots', self::id($plot), ['name' => 'Parcel 12/3', 'groundMaterialId' => $gravel->id]),
		);
		self::assertIsArray($changed['element']);
		self::assertSame(['Parcel 12/3', $gravel->id], [$changed['element']['name'], $changed['element']['groundMaterialId']]);
		self::assertIsArray($changed['change']);
		self::assertSame('Changed plot "Parcel 12/3" (name, groundMaterialId)', $changed['change']['summary']);

		self::assertSame(200, $this->edit('DELETE', 'plots', self::id($plot))->getStatusCode());
		self::assertSame([], $this->sceneList('plots'));
		self::assertSame(200, $this->undo()->getStatusCode());
		self::assertSame(['Parcel 12/3'], array_column($this->sceneList('plots'), 'name'));
	}

	public function testANewPlotWithoutABoundaryStandsClearOfTheFloor(): void
	{
		$empty = $this->create('plots', []);
		self::assertSame([[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]], $empty['polygon']);

		$this->create('rooms', ['name' => 'Living', 'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]]]);
		$this->create('walls', ['startX' => -500, 'startY' => 1500, 'endX' => 4500, 'endY' => 1500, 'sagitta' => 1000]);
		$around = $this->create('plots', []);
		// 3 m round the room and the wall ends, plus the wall's bulge.
		self::assertSame([[-4500, -4000], [8500, -4000], [8500, 7000], [-4500, 7000]], $around['polygon']);
	}

	public function testRefusesABadBoundaryOrMaterial(): void
	{
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => [[0, 0], [1000, 0]]])->getStatusCode());
		self::assertSame(
			422,
			$this->edit('POST', 'plots', body: ['polygon' => [[0, 0], [1000, 1000], [1000, 0], [0, 1000]]])->getStatusCode(),
		);
		self::assertSame(
			422,
			$this->edit('POST', 'plots', body: ['polygon' => self::Boundary, 'groundMaterialId' => 999999])->getStatusCode(),
		);
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => self::Boundary, 'height' => 3])->getStatusCode());
		// Further than a room may reach, but a plot may: a kilometre out is fine, past it is not.
		$far = static fn (int $reach): array => [[0, 0], [$reach, 0], [$reach, $reach]];
		self::assertSame(201, $this->edit('POST', 'plots', body: ['polygon' => $far(900000)])->getStatusCode());
		self::assertSame(422, $this->edit('POST', 'plots', body: ['polygon' => $far(1100000)])->getStatusCode());
	}
}

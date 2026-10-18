<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\CutListController;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Furniture\CutList\CutListAccumulator;
use FlatDesigner\Service\Furniture\CutList\CutListService;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CutListController::class)]
#[CoversClass(CutListService::class)]
#[CoversClass(CutListAccumulator::class)]
final class CutListControllerTest extends EditorTestCase
{
	private const array Definition = [
		'format' => 1,
		'size' => ['width' => 800, 'depth' => 300, 'height' => 1800],
		'parameters' => [['key' => 'shelves', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10]],
		'materialSlots' => [['key' => 'carcass', 'default' => 'kitchen-oak'], ['key' => 'legs', 'default' => 'aluminium-brushed']],
		'parts' => [
			['type' => 'board', 'name' => 'Side', 'repeat' => ['count' => 2], 'x' => 'i * (W - 18)', 'y' => 100, 'z' => 0, 'w' => 18, 'h' => 'H - 100', 'd' => 'D', 'material' => 'carcass', 'edges' => ['front']],
			['type' => 'board', 'name' => 'Shelf', 'repeat' => ['count' => 'shelves'], 'x' => 18, 'y' => '100 + i * 400', 'z' => 0, 'w' => 'W - 36', 'h' => 18, 'd' => 'D', 'material' => 'carcass', 'grain' => 'horizontal', 'edges' => ['front']],
			['type' => 'cylinder', 'name' => 'Leg', 'repeat' => ['count' => 4], 'x' => 'i % 2 * (W - 30)', 'y' => 0, 'z' => 'floor(i / 2) * (D - 30)', 'w' => 30, 'h' => 100, 'd' => 30, 'material' => 'legs'],
		],
	];

	public function testListsTheBoardsAndPartsOfTheShownCustomItems(): void
	{
		$definitionId = $this->defineFurniture();
		$this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId, 'name' => 'Shelf A']);
		// Another one with an extra shelf and white boards.
		$white = Fixture::service(MaterialRepository::class)->findByCode('furniture-white-matt') ?? self::fail('No white material.');
		$this->create('items', [
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'name' => 'Shelf B',
			'params' => ['values' => ['shelves' => 4], 'materials' => ['carcass' => $white->id]],
		]);
		// Not furniture of a definition, and a hidden variant: neither is listed.
		$this->create('items', ['kind' => 'Box']);

		$list = $this->cutList();
		$oak = Fixture::service(MaterialRepository::class)->findByCode('kitchen-oak')->name ?? '';

		$boardRows = self::rows($list['boards']);
		$boards = array_map(
			static fn (array $b): array => [$b['material'], $b['thickness'], $b['length'], $b['width'], $b['count'], $b['edges']],
			$boardRows,
		);
		self::assertContains([$oak, 18, 1700, 300, 2, ['front']], $boards);
		self::assertContains([$oak, 18, 764, 300, 3, ['front']], $boards);
		self::assertContains([$white->name, 18, 764, 300, 4, ['front']], $boards);
		self::assertCount(4, $boards);
		$whiteShelves = array_values(
			array_filter(
				self::rows($list['boards']),
				static fn (array $b): bool => $b['material'] === $white->name && $b['length'] === 764,
			),
		);
		self::assertSame(['Shelf B'], $whiteShelves[0]['items']);
		self::assertSame(['Shelf'], $whiteShelves[0]['parts']);

		$others = self::rows($list['others']);
		self::assertCount(1, $others);
		self::assertSame(
			['cylinder', 30, 100, 30, 8],
			[$others[0]['type'], $others[0]['w'], $others[0]['h'], $others[0]['d'], $others[0]['count']],
		);

		$oakTotals = array_values(array_filter(self::rows($list['materials']), static fn (array $m): bool => $m['material'] === $oak))[0];
		// 2 sides 1.7 × 0.3 + 3 shelves 0.764 × 0.3; front edges 2 × 1.7 + 3 × 0.764.
		self::assertEqualsWithDelta(2 * 1.7 * 0.3 + 3 * 0.764 * 0.3, $oakTotals['area'], 0.001);
		self::assertEqualsWithDelta(2 * 1.7 + 3 * 0.764, $oakTotals['edgeLength'], 0.01);
		self::assertSame([], $list['skipped']);

		// The floor's list is the same here; another user gets nothing.
		self::assertSame($list, $this->jsonBody($this->request('GET', $this->path(true), authenticatedAs: $this->user)));
		self::assertSame(404, $this->request('GET', $this->path(false), authenticatedAs: Fixture::createUser())->getStatusCode());
	}

	public function testItemsThatDoNotFitAreSkipped(): void
	{
		$definitionId = $this->defineFurniture();
		$item = $this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId, 'name' => 'Narrow']);
		$definition = self::Definition;
		$definition['parts'][1]['w'] = 'W - 1000';
		$definition['size']['width'] = 1200;
		$this->request('PUT', self::definitionPath($definitionId), ['name' => 'Shelf', 'definition' => $definition], $this->user);

		$list = $this->cutList();

		[$skipped] = self::rows($list['skipped']) + [[]];
		self::assertSame(self::id($item), $skipped['itemId'] ?? null);
		self::assertSame('Ground floor', $skipped['floorName'] ?? null);
		self::assertStringContainsString('parts[1].w', self::stringField($skipped['error'] ?? null));
	}

	/** @return list<array<string, mixed>> */
	private static function rows(mixed $value): array
	{
		self::assertIsList($value);
		$rows = [];
		foreach ($value as $row) {
			self::assertIsArray($row);
			$fields = [];
			foreach ($row as $key => $field) {
				$fields[(string) $key] = $field;
			}
			$rows[] = $fields;
		}

		return $rows;
	}

	/** @return array<string, mixed> */
	private function cutList(): array
	{
		return $this->jsonBody($this->request('GET', $this->path(false), authenticatedAs: $this->user));
	}

	private function path(bool $floor): string
	{
		return str_replace(
			['{projectId:number}', '{floorId:number}'],
			[(string) $this->project->id, (string) $this->floor->id],
			($floor ? Routes::ProjectFloorCutList : Routes::ProjectCutList)->value,
		);
	}

	private function defineFurniture(): int
	{
		$response = $this->request(
			'POST',
			Routes::FurnitureDefinitions->value,
			['name' => 'Shelf', 'definition' => self::Definition],
			$this->user,
		);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

		return self::id($this->jsonBody($response));
	}

	private static function definitionPath(int $definitionId): string
	{
		return str_replace('{definitionId:number}', (string) $definitionId, Routes::FurnitureDefinition->value);
	}
}

<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\CutList;

use FlatDesigner\Dto\CutListBoardDto;
use FlatDesigner\Dto\CutListDto;
use FlatDesigner\Dto\CutListMaterialDto;
use FlatDesigner\Dto\CutListOtherDto;
use FlatDesigner\Service\Furniture\ResolvedPart;

/**
 * Collects the parts of the items into the rows of a cut list: identical boards (material, thickness, length along the
 * grain, width, banded edges) and identical other parts are counted once each.
 */
final class CutListAccumulator
{
	/** Sides of a part's box and the two dimensions of that face, by the part's w, h, d. */
	private const array Faces = [
		'left' => ['h', 'd'],
		'right' => ['h', 'd'],
		'bottom' => ['w', 'd'],
		'top' => ['w', 'd'],
		'back' => ['w', 'h'],
		'front' => ['w', 'h'],
	];

	/** @var array<string, array{board: CutListBoardDto, edgeLength: int}> */
	private array $boards = [];

	/** @var array<string, CutListOtherDto> */
	private array $others = [];

	/** @var list<array{itemId: int, itemName: string, floorName: string, error: string}> */
	private array $skipped = [];

	/** A board or front: thickness = its smallest side, length = the side along the grain (`grain`, default vertical). */
	public function addBoard(?int $materialId, string $material, ResolvedPart $part, string $itemName): void
	{
		$sizes = ['w' => $part->w, 'h' => $part->h, 'd' => $part->d];
		asort($sizes);
		[$thin, $middle, $long] = array_keys($sizes);
		$along = ($part->props['grain'] ?? 'vertical') === 'horizontal' ? 'w' : 'h';
		if ($along === $thin) {
			// The grain runs through the thickness (a part drawn lying down): take the longer side.
			$along = $long;
		}
		$across = $middle === $along ? $long : $middle;
		$thickness = $sizes[$thin];
		$length = $sizes[$along];
		$width = $sizes[$across];
		$edges = self::edges($part->props['edges'] ?? []);

		$key = implode('|', [$materialId ?? '-', $material, $thickness, $length, $width, implode(',', $edges)]);
		$current = $this->boards[$key]['board'] ?? null;
		$this->boards[$key] = [
			'board' => new CutListBoardDto(
				materialId: $materialId,
				material: $material,
				thickness: $thickness,
				length: $length,
				width: $width,
				count: ($current->count ?? 0) + 1,
				edges: $edges,
				parts: self::with($current->parts ?? [], $part->name),
				items: self::with($current->items ?? [], $itemName),
			),
			'edgeLength' => array_sum(array_map(
				static fn (string $side): int => max(array_map(static fn (string $key): int => $sizes[$key], self::Faces[$side])),
				$edges,
			)),
		];
	}

	public function addOther(?int $materialId, string $material, ResolvedPart $part, string $itemName): void
	{
		$key = implode('|', [$part->type, $materialId ?? '-', $material, $part->w, $part->h, $part->d]);
		$current = $this->others[$key] ?? null;
		$this->others[$key] = new CutListOtherDto(
			type: $part->type,
			materialId: $materialId,
			material: $material,
			w: $part->w,
			h: $part->h,
			d: $part->d,
			count: ($current->count ?? 0) + 1,
			parts: self::with($current->parts ?? [], $part->name),
			items: self::with($current->items ?? [], $itemName),
		);
	}

	public function skip(int $itemId, string $itemName, string $floorName, string $error): void
	{
		$this->skipped[] = ['itemId' => $itemId, 'itemName' => $itemName, 'floorName' => $floorName, 'error' => $error];
	}

	/** Boards by material, then thickness, then the longest first. */
	public function result(): CutListDto
	{
		$entries = array_values($this->boards);
		usort(
			$entries,
			static fn (array $a, array $b): int => [$a['board']->material, $a['board']->thickness, -$a['board']->length, -$a['board']->width]
				<=> [$b['board']->material, $b['board']->thickness, -$b['board']->length, -$b['board']->width],
		);

		$totals = [];
		foreach ($entries as ['board' => $board, 'edgeLength' => $edgeLength]) {
			$key = ($board->materialId ?? '-') . '|' . $board->material;
			$total = $totals[$key] ?? ['materialId' => $board->materialId, 'material' => $board->material, 'area' => 0, 'edge' => 0];
			$total['area'] += $board->length * $board->width * $board->count;
			$total['edge'] += $edgeLength * $board->count;
			$totals[$key] = $total;
		}

		$others = array_values($this->others);
		usort($others, static fn (CutListOtherDto $a, CutListOtherDto $b): int => [$a->type, $a->material] <=> [$b->type, $b->material]);

		return new CutListDto(
			boards: array_column($entries, 'board'),
			others: $others,
			materials: array_values(array_map(
				static fn (array $total): CutListMaterialDto => new CutListMaterialDto(
					materialId: $total['materialId'],
					material: $total['material'],
					area: round($total['area'] / 1000000, 3),
					edgeLength: round($total['edge'] / 1000, 2),
				),
				$totals,
			)),
			skipped: $this->skipped,
		);
	}

	/** @return list<string> the banded sides in the order of `Faces` */
	private static function edges(mixed $edges): array
	{
		return is_array($edges)
			? array_values(array_filter(array_keys(self::Faces), static fn (string $side): bool => in_array($side, $edges, true)))
			: [];
	}

	/**
	 * @param list<string> $names
	 * @return list<string>
	 */
	private static function with(array $names, string $name): array
	{
		return in_array($name, $names, true) ? $names : [...$names, $name];
	}
}

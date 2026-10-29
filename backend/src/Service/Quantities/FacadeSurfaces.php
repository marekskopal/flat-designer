<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Dto\WallFinishDto;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Service\Editor\Geometry\FacadeSpans;
use FlatDesigner\Service\Editor\Geometry\Ground;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\Spans;
use FlatDesigner\Service\Editor\Geometry\WallBottom;
use FlatDesigner\Service\Editor\Geometry\WallLine;
use FlatDesigner\Service\Editor\Geometry\WallTop;

/**
 * A floor's façades (FD-88): the wall faces outside every room (`FacadeSpans`), per material, without the openings. A
 * face's own bands count where they lie between the wall's bottom (its base or the ground; on the floor, the lowest
 * band reaches below the floor's level, a plinth) and its top (sloping, or under the roofs); the rest of the face is in
 * the wall's material, or for an exterior wall without one the project's façade material. Summed strip by strip along
 * the face, the top and bottom read in the middle of each.
 */
final readonly class FacadeSurfaces
{
	/** mm: the width of the strips the face is summed in. */
	private const float Step = 50.0;

	/**
	 * @param list<WallDto> $walls
	 * @param list<RoomDto> $rooms
	 * @param list<RoofGeometry> $roofs
	 * @return list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}> amounts in mm²
	 */
	public static function of(array $walls, array $rooms, array $roofs, Ground $ground, ?int $exteriorMaterialId): array
	{
		$outlines = array_map(
			static fn (RoomDto $room): array => [
				'polygon' => array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $room->polygon),
				'outdoor' => $room->type->isOutdoor(),
			],
			$rooms,
		);
		$amounts = [];
		foreach (FacadeSpans::of($walls, $outlines) as $facade) {
			$wall = $facade['wall'];
			// The rest of the face: the wall's own material, or on an exterior wall without one and without bands of its
			// own (null; [] keeps it bare) the project's façade, as the viewer draws it (`facadeBands()`).
			$bare = $wall->materialId === null && $wall->type === WallTypeEnum::Exterior && $wall->finishesOn($facade['side']) === null;
			$own = $wall->materialId ?? ($bare ? $exteriorMaterialId : null);
			foreach (self::face($wall, $facade['side'], $facade['spans'], $roofs, $ground) as [$materialId, $amount]) {
				$key = $materialId ?? $own ?? 0;
				$amounts[$key] = ($amounts[$key] ?? 0.0) + $amount;
			}
		}

		$surfaces = [];
		foreach ($amounts as $materialId => $amount) {
			if ($amount > 0) {
				$surfaces[] = ['surface' => QuantitySurfaceEnum::Facade, 'materialId' => $materialId === 0 ? null : $materialId, 'amount' => $amount];
			}
		}

		return $surfaces;
	}

	/**
	 * A face's area per band material (null: the wall's own), mm².
	 *
	 * @param list<array{float, float}> $spans along the centre line
	 * @param list<RoofGeometry> $roofs
	 * @return list<array{int|null, float}>
	 */
	private static function face(WallDto $wall, OpeningSideEnum $side, array $spans, array $roofs, Ground $ground): array
	{
		$line = WallLine::fromDto($wall);
		$top = new WallTop($line, $wall->height, $wall->upToRoof, $roofs, $wall->heightEnd);
		$bottom = new WallBottom($line, $wall->baseStart, $wall->baseEnd, $wall->onGround ? $ground : null);
		$bands = $wall->finishesOn($side) ?? [];
		$floorStanding = !$wall->onGround && $wall->baseStart === 0 && $wall->baseEnd === 0;
		$plinth = $floorStanding ? min([0.0, ...array_map(static fn (WallFinishDto $band): float => (float) $band->bottom, $bands)]) : null;
		$openings = array_values(array_filter(
			$wall->openings,
			static fn (OpeningDto $o): bool => $o->type !== OpeningTypeEnum::Niche || ($o->side ?? OpeningSideEnum::Left) === $side,
		));

		$amounts = [];
		foreach ($spans as [$from, $to]) {
			for ($x = $from; $x < $to; $x += self::Step) {
				$width = min(self::Step, $to - $x);
				$middle = $x + $width / 2;
				$low = $plinth ?? $bottom->at($middle);
				$high = $top->at($middle);
				foreach (self::heights($bands, $openings, $middle, $low, $high) as [$materialId, $height]) {
					$amounts[] = [$materialId, $width * $height];
				}
			}
		}

		return $amounts;
	}

	/**
	 * The heights at `along` per band material between `low` and `high` (where bands overlap, the first keeps the
	 * stretch), and the rest of the face (null), less the openings there.
	 *
	 * @param list<WallFinishDto> $bands
	 * @param list<OpeningDto> $openings
	 * @return list<array{int|null, float}>
	 */
	private static function heights(array $bands, array $openings, float $along, float $low, float $high): array
	{
		$ranges = [];
		$covered = 0.0;
		$taken = [];
		foreach ($bands as $band) {
			[$bandLow, $bandHigh] = [max((float) $band->bottom, $low), min((float) $band->top, $high)];
			if ($bandHigh <= $bandLow) {
				continue;
			}
			foreach (Spans::subtract([[$bandLow, $bandHigh]], $taken) as [$from, $to]) {
				$ranges[] = [$band->materialId, $from, $to];
				$covered += $to - $from;
			}
			$taken = Spans::merge([...$taken, [$bandLow, $bandHigh]]);
		}
		$heights = [];
		foreach ($ranges as [$materialId, $from, $to]) {
			$heights[] = [$materialId, self::open($openings, $along, $from, $to)];
		}
		// The rest of the face: its whole height less the bands and the openings in it.
		$bare = max($high - $low, 0.0) - $covered;
		if ($bare > 0) {
			$heights[] = [null, max(self::open($openings, $along, $low, $high) - array_sum(array_column($heights, 1)), 0.0)];
		}

		return $heights;
	}

	/**
	 * The height of `from`–`to` at `along` not taken by an opening.
	 *
	 * @param list<OpeningDto> $openings
	 */
	private static function open(array $openings, float $along, float $from, float $to): float
	{
		$height = $to - $from;
		foreach ($openings as $opening) {
			if ($opening->offset < $along && $along < $opening->offset + $opening->width) {
				$height -= max(min((float) ($opening->sillHeight + $opening->height), $to) - max((float) $opening->sillHeight, $from), 0.0);
			}
		}

		return max($height, 0.0);
	}
}

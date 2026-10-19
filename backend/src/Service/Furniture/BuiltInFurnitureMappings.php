<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** The per-kind mappings of `BuiltInFurnitureConverter`: a built-in kind's params to its definition's values and back. */
final readonly class BuiltInFurnitureMappings
{
	private const array FrontKeys = ['type', 'height', 'count', 'handle'];

	/** As the built-in wardrobe: two to six doors, by default one per ~900 mm of width. */
	private const int MinDoors = 2;

	private const int MaxDoors = 6;

	private const int DoorWidth = 900;

	/** As the built-in chair: the seat at 460 unless the given height fits between the seat board and the top. */
	private const int SeatThickness = 25;

	private const int DefaultSeatHeight = 460;

	/**
	 * @param array<mixed> $params
	 * @return array<string, mixed>
	 */
	public static function cabinet(array $params): array
	{
		$fronts = [];
		foreach (is_array($params['fronts'] ?? null) ? $params['fronts'] : [] as $front) {
			if (is_array($front)) {
				$fronts[] = array_intersect_key($front, array_flip(self::FrontKeys));
			}
		}

		return [
			'fronts' => $fronts === [] ? [['type' => 'door']] : $fronts,
			'handle' => is_string($params['handle'] ?? null) ? $params['handle'] : 'none',
			'handleSide' => ($params['handleSide'] ?? null) === 'left' ? 'left' : 'right',
			'blindLeft' => BuiltInFurnitureConverter::int($params['blindLeft'] ?? null, 0),
			'blindRight' => BuiltInFurnitureConverter::int($params['blindRight'] ?? null, 0),
		];
	}

	/**
	 * @param array<mixed> $params
	 * @return array<string, mixed>
	 */
	public static function wardrobe(array $params, int $width): array
	{
		$count = is_int($params['doors'] ?? null) ? $params['doors'] : (int) round($width / self::DoorWidth);
		$count = min(self::MaxDoors, max(self::MinDoors, $count));
		$mirrors = is_array($params['mirrorDoors'] ?? null) ? $params['mirrorDoors'] : [];
		$doors = [];
		for ($door = 1; $door <= $count; $door++) {
			$doors[] = in_array($door, $mirrors, true) ? ['mirror' => true] : [];
		}

		return ['doors' => $doors, 'plinth' => BuiltInFurnitureConverter::int($params['plinth'] ?? null, 60)];
	}

	/**
	 * @param array<mixed> $values
	 * @param array<mixed> $materials
	 * @return array<string, mixed>
	 */
	public static function wardrobeParams(array $values, array $materials): array
	{
		$doors = is_array($values['doors'] ?? null) ? $values['doors'] : [];
		$mirrors = [];
		foreach (array_values($doors) as $index => $door) {
			if (is_array($door) && ($door['mirror'] ?? false) === true) {
				$mirrors[] = $index + 1;
			}
		}

		return array_filter(
			[
				'doors' => count($doors),
				'mirrorDoors' => $mirrors === [] ? null : $mirrors,
				'plinth' => $values['plinth'] ?? null,
				'profileMaterialId' => is_int($materials['profile'] ?? null) ? $materials['profile'] : null,
			],
			static fn (mixed $value): bool => $value !== null,
		);
	}

	/**
	 * As `chairShape()` draws it: the back no wider than the front, the seat height only when it fits.
	 *
	 * @param array<mixed> $params
	 * @return array<string, mixed>
	 */
	public static function chair(array $params, int $width, int $height): array
	{
		$seatHeight = $params['seatHeight'] ?? null;
		$backWidth = $params['backWidth'] ?? null;

		return [
			'seatHeight' => is_int($seatHeight) && $seatHeight > self::SeatThickness && $seatHeight < $height
				? $seatHeight
				: self::DefaultSeatHeight,
			'backWidth' => is_int($backWidth) && $backWidth > 0 ? min($backWidth, $width) : $width,
		];
	}
}

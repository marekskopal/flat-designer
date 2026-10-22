<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * The ceiling and skirting of a room, for `RoomHandler`: the ceiling's material, an optional vaulted ceiling between
 * beams and skirting boards. An open-air room (no ceiling height) has neither ceiling nor vault.
 */
final readonly class RoomCeiling
{
	/** A flat ceiling: no vault, and the beam fields at rest. */
	private const array VaultDefaults = [
		'vaultSpacing' => null,
		'vaultAngle' => 0,
		'vaultRise' => 130,
		'beamWidth' => 80,
		'beamDrop' => 30,
		'beamMaterialId' => null,
	];

	/** The fields as a new room gets them: a flat white ceiling, no skirting. */
	public const array Defaults = ['ceilingMaterialId' => null] + self::VaultDefaults + ['skirtingHeight' => null, 'skirtingMaterialId' => null];

	private const int MinVaultSpacing = 300;
	private const int MaxVaultSpacing = 3000;
	private const int MaxVaultRise = 600;
	private const int MaxBeamWidth = 400;
	private const int MaxBeamDrop = 600;
	private const int MinSkirting = 20;
	private const int MaxSkirting = 400;

	/** Clear height a vault and its beams must leave under them, mm. */
	private const int MinClearHeight = 1800;

	public function __construct(private MaterialResolver $materialResolver)
	{
	}

	/** @return array{ceilingMaterialId: int|null, vaultSpacing: int|null, vaultAngle: int, vaultRise: int, beamWidth: int, beamDrop: int, beamMaterialId: int|null, skirtingHeight: int|null, skirtingMaterialId: int|null} */
	public function fields(?int $ceilingHeight, FieldReader $fields): array
	{
		$skirtingHeight = $fields->nullableInt('skirtingHeight', self::MinSkirting, self::MaxSkirting);
		$skirting = [
			'skirtingHeight' => $skirtingHeight,
			'skirtingMaterialId' => $skirtingHeight === null ? null : $this->material($fields, 'skirtingMaterialId'),
		];
		if ($ceilingHeight === null) {
			return ['ceilingMaterialId' => null] + self::VaultDefaults + $skirting;
		}

		$ceiling = ['ceilingMaterialId' => $this->material($fields, 'ceilingMaterialId')];
		$spacing = $fields->nullableInt('vaultSpacing', self::MinVaultSpacing, self::MaxVaultSpacing);
		if ($spacing === null) {
			return $ceiling + self::VaultDefaults + $skirting;
		}

		$rise = self::intOr($fields, 'vaultRise', 0, self::MaxVaultRise);
		$width = self::intOr($fields, 'beamWidth', 0, self::MaxBeamWidth);
		$drop = self::intOr($fields, 'beamDrop', 0, self::MaxBeamDrop);
		if ($width >= $spacing) {
			throw EditException::invalid(sprintf('Beams %d mm wide do not fit %d mm apart.', $width, $spacing));
		}
		if ($ceilingHeight - $rise - $drop < self::MinClearHeight) {
			throw EditException::invalid(sprintf(
				'The vaults and beams would come down to %d mm above the floor (at least %d).',
				$ceilingHeight - $rise - $drop,
				self::MinClearHeight,
			));
		}

		return $ceiling + [
			'vaultSpacing' => $spacing,
			// The beams' direction; a line runs both ways, so 0 ≤ angle < 180.
			'vaultAngle' => ((self::intOr($fields, 'vaultAngle', -360, 360) % 180) + 180) % 180,
			'vaultRise' => $rise,
			'beamWidth' => $width,
			'beamDrop' => $drop,
			'beamMaterialId' => $this->material($fields, 'beamMaterialId'),
		] + $skirting;
	}

	/** Writes a state that `fields()` normalized into the room. */
	public function apply(Room $room, FieldReader $fields): void
	{
		$room->ceilingMaterial = $this->materialResolver->resolve($fields->nullableId('ceilingMaterialId'));
		$room->vaultSpacing = $fields->nullableInt('vaultSpacing', self::MinVaultSpacing, self::MaxVaultSpacing);
		$room->vaultAngle = $fields->int('vaultAngle', 0, 179, 'degrees');
		$room->vaultRise = $fields->int('vaultRise', 0, self::MaxVaultRise);
		$room->beamWidth = $fields->int('beamWidth', 0, self::MaxBeamWidth);
		$room->beamDrop = $fields->int('beamDrop', 0, self::MaxBeamDrop);
		$room->beamMaterial = $this->materialResolver->resolve($fields->nullableId('beamMaterialId'));
		$room->skirtingHeight = $fields->nullableInt('skirtingHeight', self::MinSkirting, self::MaxSkirting);
		$room->skirtingMaterial = $this->materialResolver->resolve($fields->nullableId('skirtingMaterialId'));
	}

	private function material(FieldReader $fields, string $key): ?int
	{
		$id = $fields->nullableId($key);
		$this->materialResolver->resolve($id);

		return $id;
	}

	/** The field, or its default when not given (states from before it existed). */
	private static function intOr(FieldReader $fields, string $key, int $min, int $max): int
	{
		$default = self::VaultDefaults[$key];
		assert(is_int($default));

		return $fields->raw($key) === null ? $default : $fields->int($key, $min, $max, $key === 'vaultAngle' ? 'degrees' : 'mm');
	}
}

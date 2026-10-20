<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use const SORT_REGULAR;

/**
 * What a part of a furniture definition is, and the options (keys besides `PartEntry::Keys`) each type takes. The
 * viewer's builder (frontend/src/app/viewer/engine/custom) draws them.
 */
enum PartTypeEnum: string
{
	/**
	 * Panel in the slot material; `grain` along its height (vertical) or width; `edges` = banded sides; `shade` darkens
	 * it (0.3: a plinth in the shadow under the fronts); `backWidth` narrows it towards the back (`taper`: on both
	 * sides, or only the left / right edge), e.g. a chair seat.
	 */
	case Board = 'board';
	/** Door or drawer leaf with a handle, as on the kitchen cabinets. */
	case Front = 'front';
	/** Rod or round leg along `axis` (default y), its diameter the box's smaller side across. */
	case Cylinder = 'cylinder';
	/** Leg narrowing from the box's width / depth at the top to `bottomSize` mm at the floor. */
	case TaperedLeg = 'taperedLeg';
	case Glass = 'glass';
	/** LED strip giving light of `color` (#rrggbb). */
	case Led = 'led';
	/** A built-in oven or microwave front (black glass, window, steel handle); bought, so not in the cut list's boards. */
	case Appliance = 'appliance';
	/** A sliding door leaf: a frame in the `profile` slot's material around an infill in the part's, or a `mirror`. */
	case SlidingDoor = 'slidingDoor';
	/**
	 * Upholstery (seat and back cushions, a mattress, a duvet, pillows): the box with its edges rounded by `radius` mm
	 * (default 40, at most half its smallest side). Not a board, so the cut list counts it among the other parts.
	 */
	case Cushion = 'cushion';
	/** No part itself: its `parts` are placed in its box and see its size as `AW`, `AH`, `AD`. */
	case Assembly = 'assembly';

	private const array Grain = ['vertical', 'horizontal'];

	private const array Sides = ['left', 'right', 'bottom', 'top', 'back', 'front'];

	/**
	 * Every visible part may lean back by `tilt` degrees (−90 to 90; positive tips its top towards the back) around a
	 * line along x at `tiltY` / `tiltZ` (mm, the item's frame; default the part's bottom-back edge), e.g. the back posts
	 * of a chair. It changes the drawing only, not the cut list.
	 */
	private const array Tilt = ['tilt' => 'angle', 'tiltY' => 'coordinate', 'tiltZ' => 'coordinate'];

	private const array KindErrors = [
		'mm' => 'Must be a whole number of mm above 0.',
		'color' => 'Must be a colour "#rrggbb".',
		'fraction' => 'Must be a number from 0 to 1.',
		'angle' => 'Must be degrees from -90 to 90.',
		'coordinate' => 'Must be a number (mm).',
		'bool' => 'Must be true or false.',
		'slot' => 'Must be the key of one of the materialSlots.',
		'number' => 'Must be a number, 0 or more.',
	];

	/** @return list<string> */
	public static function values(): array
	{
		return array_map(static fn (self $type): string => $type->value, self::cases());
	}

	/** Why the type has no option `$option`, or null when it has. */
	public function checkOptionKey(string $option): ?string
	{
		return $this->checkOption($option, null, true);
	}

	/** Why `$value` is no good for option `$option` (with `$keyOnly`: only whether the option exists), or null when it is fine. */
	public function checkOption(string $option, mixed $value, bool $keyOnly = false): ?string
	{
		$options = $this->options();
		if ($keyOnly) {
			return array_key_exists($option, $options) ? null : $this->checkOption($option, $value);
		}
		if (!array_key_exists($option, $options)) {
			return sprintf(
				'%s %s part has no option "%s"%s.',
				in_array($this->value[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A',
				$this->value,
				$option,
				$options === [] ? '' : sprintf(' (options: %s)', implode(', ', array_keys($options))),
			);
		}

		$expected = $options[$option];
		if (is_array($expected)) {
			return in_array($value, $expected, true) ? null : sprintf('Must be one of "%s".', implode('", "', $expected));
		}

		return self::checkKind($expected, $value);
	}

	/** Whether the option names a material slot (checked against the definition's slots by the validator). */
	public function isSlotOption(string $option): bool
	{
		return ($this->options()[$option] ?? null) === 'slot';
	}

	private static function checkKind(string $expected, mixed $value): ?string
	{
		if (self::fits($expected, $value)) {
			return null;
		}

		return $expected === 'sides'
			? sprintf('Must be a list of distinct sides: "%s".', implode('", "', self::Sides))
			: self::KindErrors[$expected] ?? self::KindErrors['number'];
	}

	private static function fits(string $kind, mixed $value): bool
	{
		if (in_array($kind, ['fraction', 'angle', 'coordinate', 'number'], true)) {
			return (is_int($value) || is_float($value)) && self::inRange($kind, (float) $value);
		}

		return match ($kind) {
			'sides' => self::isSideList($value),
			'mm' => is_int($value) && $value > 0,
			'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1,
			'bool' => is_bool($value),
			default => is_string($value),
		};
	}

	private static function inRange(string $kind, float $value): bool
	{
		return match ($kind) {
			'fraction' => $value >= 0 && $value <= 1,
			'angle' => abs($value) <= 90,
			'coordinate' => true,
			default => $value >= 0,
		};
	}

	/** @return array<string, list<string>|string> option => allowed values or kind (see `checkKind()`) */
	private function options(): array
	{
		return match ($this) {
			self::Board => [
				'grain' => self::Grain,
				'edges' => 'sides',
				'shade' => 'fraction',
				'backWidth' => 'mm',
				'taper' => ['both', 'left', 'right'],
			] + self::Tilt,
			self::Front => [
				'kind' => ['door', 'drawer'],
				'handle' => ['horizontal', 'vertical', 'none'],
				'handleSide' => ['left', 'right'],
				'grain' => self::Grain,
				'edges' => 'sides',
			] + self::Tilt,
			self::Cylinder => ['axis' => ['x', 'y', 'z']] + self::Tilt,
			self::TaperedLeg => ['bottomSize' => 'mm'] + self::Tilt,
			self::Glass => self::Tilt,
			self::Led => ['color' => 'color', 'intensity' => 'number'] + self::Tilt,
			self::Appliance => ['kind' => ['oven', 'microwave']] + self::Tilt,
			self::SlidingDoor => ['profile' => 'slot', 'mirror' => 'bool', 'profileWidth' => 'mm'] + self::Tilt,
			self::Cushion => ['radius' => 'mm'] + self::Tilt,
			self::Assembly => [],
		};
	}

	private static function isSideList(mixed $value): bool
	{
		return is_array($value)
			&& array_is_list($value)
			&& count(array_unique($value, SORT_REGULAR)) === count($value)
			&& array_filter($value, static fn (mixed $side): bool => in_array($side, self::Sides, true)) === $value;
	}
}

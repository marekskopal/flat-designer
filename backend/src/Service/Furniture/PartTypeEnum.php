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
	/** Panel in the slot material; `grain` along its height (vertical) or width; `edges` = banded sides. */
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
	/** No part itself: its `parts` are placed in its box and see its size as `AW`, `AH`, `AD`. */
	case Assembly = 'assembly';

	private const array Grain = ['vertical', 'horizontal'];

	private const array Sides = ['left', 'right', 'bottom', 'top', 'back', 'front'];

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

	/** @param 'sides'|'mm'|'color'|'number' $expected */
	private static function checkKind(string $expected, mixed $value): ?string
	{
		return match ($expected) {
			'sides' => self::isSideList($value) ? null : sprintf('Must be a list of distinct sides: "%s".', implode('", "', self::Sides)),
			'mm' => is_int($value) && $value > 0 ? null : 'Must be a whole number of mm above 0.',
			'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? null : 'Must be a colour "#rrggbb".',
			default => (is_int($value) || is_float($value)) && $value >= 0 ? null : 'Must be a number, 0 or more.',
		};
	}

	/** @return array<string, list<string>|'sides'|'mm'|'color'|'number'> option => allowed values or kind */
	private function options(): array
	{
		return match ($this) {
			self::Board => ['grain' => self::Grain, 'edges' => 'sides'],
			self::Front => [
				'kind' => ['door', 'drawer'],
				'handle' => ['horizontal', 'vertical', 'none'],
				'handleSide' => ['left', 'right'],
				'grain' => self::Grain,
			],
			self::Cylinder => ['axis' => ['x', 'y', 'z']],
			self::TaperedLeg => ['bottomSize' => 'mm'],
			self::Glass, self::Assembly => [],
			self::Led => ['color' => 'color', 'intensity' => 'number'],
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

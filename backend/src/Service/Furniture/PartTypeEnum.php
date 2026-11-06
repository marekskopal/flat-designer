<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture;

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
	/**
	 * Door or drawer leaf with a handle, as on the kitchen cabinets; it opens as its `Motion` options say. Its `style`
	 * (`FrontStyles`) and `handle` (`Handles`) set its look; a `frameMaterial` or `handleMaterial` slot the item does not
	 * fill and that has no default keeps the front's own material or the black handle.
	 */
	case Front = 'front';
	/**
	 * A solid box not cut from sheet material: stone, concrete, cast or solid metal, a solid timber bar, fabric;
	 * the cut list counts it among the other parts.
	 */
	case Block = 'block';
	/** Rod or round leg along `axis` (default y), its diameter the box's smaller side across; `radius` rounds its ends' edges. */
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
	 * (default 40; 0 is square; the viewer draws at most half the box's smallest side). Not a board, so the cut list
	 * counts it among the other parts.
	 */
	case Cushion = 'cushion';
	/**
	 * A picture (a print, poster or photo): the box in the part's material with the item's `image` file on its front
	 * (+z) face, cropped to fill it. Without an image the front is plain. Bought, so the cut list counts it among the
	 * other parts.
	 */
	case Picture = 'picture';
	/**
	 * A light source at the centre of its box: a `point` light, or a `spot` shining down within `angle` degrees (the
	 * whole cone, default 60). `lumens` (default 800) and `kelvin` (colour temperature, default 2700, warm white) set its
	 * output; `shadow` lets it cast shadows (costly, so the viewer allows only a few). `bulb` draws what glows while it
	 * is on: a `sphere` filling the box (a bare bulb), a `disc` on the box's bottom face (a downlight) or nothing (inside
	 * a shade). Not in the cut list's boards.
	 */
	case Light = 'light';
	/**
	 * A pendant's cable, as thick as the box's smaller side across: from the bottom centre of its box up to the top
	 * centre (the ceiling hook), then along `path` (points {x, z} in the parts' frame, e.g. from a list parameter) to the
	 * ceiling rose, sagging `sag` mm (default 0) between the points, with a ceiling rose `rose` mm across (default 0,
	 * none) at its end. With `ceiling` (default true) the cable runs on the real ceiling of the item's room (flat or
	 * vaulted) instead of at the top of the box.
	 */
	case Cable = 'cable';
	/**
	 * No part itself: its `parts` are placed in its box and see its size as `AW`, `AH`, `AD`. With `opens` (the
	 * `Motion` options) they move as one leaf, e.g. a drawer front with its drawer box.
	 */
	case Assembly = 'assembly';

	private const array Grain = ['vertical', 'horizontal'];

	/**
	 * A front's look: a plain slab; a frame round a recessed panel; flutes on its face; a frame round clear glass or
	 * reeded glass. The frame is `frame` mm wide (0 or none: the style's own) in the `frameMaterial` slot's material.
	 */
	public const array FrontStyles = ['flat', 'shaker', 'fluted', 'glass', 'reeded'];

	/**
	 * A front's handle: a bar across or along it (`handleLength` mm, none: by the front), a knob, an edge pull on its top
	 * (or, out of reach, bottom) edge, a gola channel, push-to-open or none; in the `handleMaterial` slot's material.
	 */
	public const array Handles = ['horizontal', 'vertical', 'knob', 'edge', 'gola', 'push', 'none'];

	/**
	 * Where a front's handle goes: by its height (`auto`), on its top or bottom edge whatever the height (the pull at the
	 * split of two doors), or at reach height on its handle edge (`side`). The viewer reads it (`HandlePosition`).
	 */
	public const array HandlePositions = ['auto', 'top', 'bottom', 'side'];

	/** A built-in appliance front: an oven, a microwave, a steam oven, a coffee machine (`buildAppliance()`). */
	public const array Appliances = ['oven', 'microwave', 'steam', 'coffee'];

	/** An appliance front's finish: black or white glass, stainless steel, or retro (cream with chrome). */
	public const array ApplianceFinishes = ['black', 'white', 'steel', 'retro'];

	private const array Sides = ['left', 'right', 'bottom', 'top', 'back', 'front'];

	/**
	 * Every visible part may lean back by `tilt` degrees (−90 to 90; positive tips its top towards the back) around a
	 * line along x at `tiltY` / `tiltZ` (mm, the item's frame; default the part's bottom-back edge), e.g. the back posts
	 * of a chair, and then turn by `turn` degrees (−90 to 90, counter-clockwise seen from above) around a vertical line
	 * at `turnX` / `turnZ` (default the part's back-left edge), e.g. a wardrobe's slanted end panel. They change the
	 * drawing only, not the cut list.
	 */
	private const array Pose = [
		'tilt' => 'angle',
		'tiltY' => 'coordinate',
		'tiltZ' => 'coordinate',
		'turn' => 'angle',
		'turnX' => 'coordinate',
		'turnZ' => 'coordinate',
	];

	/**
	 * How a front, or an assembly moving as one leaf (a drawer with its box), opens in the viewer: `opens`
	 * swing (a door on its `hinge` edge, default the side away from `handleSide`, hanging on `hingeType` concealed cup
	 * hinges, the default, which open it at most 155°, or a butt hinge), slide (a drawer, `travel` mm out),
	 * lift (a flap turning up about its top edge), fold (a bi-fold flap folding `foldAt` mm below its top, at most half
	 * way) or parallel (a flap rising `travel` mm upright), or none; `angle` is how far it turns (degrees) and `softClose`
	 * damps its closing. They change the drawing only; the cut list splits a folding front in two.
	 */
	private const array Motion = [
		'opens' => ['swing', 'slide', 'lift', 'fold', 'parallel', 'none'],
		'hinge' => ['left', 'right'],
		'hingeType' => ['concealed', 'butt'],
		'angle' => 'opening',
		'travel' => 'mm',
		'foldAt' => 'mm',
		'softClose' => 'bool',
	];

	/** An assembly moves as a whole: it does not fold. */
	private const array AssemblyMotion = [
		'opens' => ['swing', 'slide', 'lift', 'parallel', 'none'],
		'hinge' => ['left', 'right'],
		'hingeType' => ['concealed', 'butt'],
		'angle' => 'opening',
		'travel' => 'mm',
		'softClose' => 'bool',
	];

	private const array KindErrors = [
		'mm' => 'Must be a whole number of mm above 0.',
		'color' => 'Must be a colour "#rrggbb".',
		'fraction' => 'Must be a number from 0 to 1.',
		'angle' => 'Must be degrees from -90 to 90.',
		'opening' => 'Must be degrees from 1 to 180.',
		'coordinate' => 'Must be a number (mm).',
		'bool' => 'Must be true or false.',
		'slot' => 'Must be the key of one of the materialSlots.',
		'number' => 'Must be a number, 0 or more.',
		'image' => 'Must be an image parameter\'s value: a file id or null.',
		'points' => 'Must be a list of points {x, z} (mm), e.g. from a list parameter.',
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
		if (in_array($kind, ['fraction', 'angle', 'opening', 'coordinate', 'number'], true)) {
			return (is_int($value) || is_float($value)) && self::inRange($kind, (float) $value);
		}

		return match ($kind) {
			'sides' => self::isSideList($value),
			'mm' => is_int($value) && $value > 0,
			'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1,
			'bool' => is_bool($value),
			'image' => $value === null || is_int($value) && $value > 0,
			'points' => self::isPointList($value),
			default => is_string($value),
		};
	}

	private static function inRange(string $kind, float $value): bool
	{
		return match ($kind) {
			'fraction' => $value >= 0 && $value <= 1,
			'angle' => abs($value) <= 90,
			'opening' => $value >= 1 && $value <= 180,
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
			] + self::Pose,
			self::Front => [
				'kind' => ['door', 'drawer'],
				'style' => self::FrontStyles,
				'frame' => 'number',
				'frameMaterial' => 'slot',
				'handle' => self::Handles,
				'handleSide' => ['left', 'right'],
				'handleMaterial' => 'slot',
				'handleLength' => 'number',
				'handlePosition' => self::HandlePositions,
				'grain' => self::Grain,
				'edges' => 'sides',
			] + self::Motion + self::Pose,
			self::Cylinder => ['axis' => ['x', 'y', 'z'], 'radius' => 'number'] + self::Pose,
			self::TaperedLeg => ['bottomSize' => 'mm'] + self::Pose,
			self::Block => ['grain' => self::Grain, 'shade' => 'fraction'] + self::Pose,
			self::Glass => self::Pose,
			self::Led => ['color' => 'color', 'intensity' => 'number'] + self::Pose,
			self::Appliance => ['kind' => self::Appliances, 'finish' => self::ApplianceFinishes] + self::Pose,
			self::SlidingDoor => ['profile' => 'slot', 'mirror' => 'bool', 'profileWidth' => 'mm'] + self::Pose,
			self::Cushion => ['radius' => 'number'] + self::Pose,
			self::Picture => ['image' => 'image'] + self::Pose,
			self::Light => [
				'kind' => ['point', 'spot'],
				'lumens' => 'number',
				'kelvin' => 'number',
				'angle' => 'number',
				'shadow' => 'bool',
				'bulb' => ['none', 'sphere', 'disc'],
			],
			self::Cable => ['path' => 'points', 'ceiling' => 'bool', 'sag' => 'number', 'rose' => 'number'],
			self::Assembly => self::AssemblyMotion,
		};
	}

	private static function isPointList(mixed $value): bool
	{
		if (!is_array($value) || !array_is_list($value)) {
			return false;
		}
		foreach ($value as $point) {
			// A literal point is an array, an evaluated record an object.
			$fields = is_object($point) ? get_object_vars($point) : (is_array($point) ? $point : []);
			$x = $fields['x'] ?? null;
			$z = $fields['z'] ?? null;
			if (!(is_int($x) || is_float($x)) || !(is_int($z) || is_float($z))) {
				return false;
			}
		}

		return true;
	}

	private static function isSideList(mixed $value): bool
	{
		return is_array($value)
			&& array_is_list($value)
			&& count(array_unique($value, SORT_REGULAR)) === count($value)
			&& array_filter($value, static fn (mixed $side): bool => in_array($side, self::Sides, true)) === $value;
	}
}

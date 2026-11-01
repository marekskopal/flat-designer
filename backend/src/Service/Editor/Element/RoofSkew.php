<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;

/**
 * Skewed verges (FD-97): a gable's or shed's `startSkew` / `endSkew`, degrees counter-clockwise (±`MaxSkew`, 0 square to
 * the ridge), turning the verge at the ridge's start or end about the ridge's end on the eaves line (`RoofGeometry`); a
 * flat roof's ends turn alike (FD-101: over a skewed wall, its parapet following). A hip roof given a skew anew is
 * refused, and one made so leaves its skews behind. The eaves line's
 * front and back stay at least `RoofHandler::MinSide` long.
 */
final readonly class RoofSkew
{
	/** The fields of a state logged before FD-97, with their values then. */
	public const array Defaults = ['startSkew' => 0, 'endSkew' => 0];

	/** Degrees either way. */
	public const int MaxSkew = 45;

	/** @return array{startSkew: int, endSkew: int} */
	public static function normalize(FieldReader $fields, RoofShapeEnum $shape, ?Roof $current): array
	{
		$skews = [];
		foreach (['startSkew', 'endSkew'] as $key) {
			$skew = $fields->int($key, -self::MaxSkew, self::MaxSkew, 'degrees');
			if ($skew !== 0 && !$shape->canSkew()) {
				if ($current === null || ($key === 'startSkew' ? $current->startSkew : $current->endSkew) !== $skew) {
					throw EditException::invalid('Only a gable, shed or flat roof has skewed ends.');
				}
				$skew = 0;
			}
			$skews[$key] = $skew;
		}

		return $skews;
	}

	/** The front and back of the eaves line and of the outline with the overhangs stay at least `RoofHandler::MinSide` long. */
	public static function check(RoofGeometry $roof): void
	{
		if ($roof->startSkew === 0 && $roof->endSkew === 0) {
			return;
		}
		foreach ([$roof->localOutline(false), $roof->localOutline()] as [$frontLeft, $frontRight, $backRight, $backLeft]) {
			if (min($frontRight[0] - $frontLeft[0], $backRight[0] - $backLeft[0]) < RoofHandler::MinSide) {
				throw EditException::invalid(sprintf(
					'The skewed ends leave the roof\'s front or back too short: with the overhangs and without, they must be at least %d mm long.',
					RoofHandler::MinSide,
				));
			}
		}
	}

	public static function apply(Roof $roof, FieldReader $fields): void
	{
		$roof->startSkew = $fields->int('startSkew', -self::MaxSkew, self::MaxSkew, 'degrees');
		$roof->endSkew = $fields->int('endSkew', -self::MaxSkew, self::MaxSkew, 'degrees');
	}
}

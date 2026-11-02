<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\CutList;

use Izbik\Service\Furniture\ResolvedPart;

/**
 * A front with `opens: fold` (a bi-fold flap) is cut as two panels: the upper one `foldAt` (default half its
 * height, at most half) less half the `Joint` between them, the lower one the rest. The viewer draws it alike
 * (`foldPanels()` in frontend/src/app/viewer/engine/custom/fronts.ts).
 */
final readonly class FoldingFront
{
	/** The gap between the two panels, mm. */
	public const int Joint = 2;

	/** @return list<ResolvedPart> the part itself, or its two panels when it folds */
	public static function panels(ResolvedPart $part): array
	{
		if ($part->type !== 'front' || ($part->props['opens'] ?? null) !== 'fold' || $part->h < 2 * self::Joint + 2) {
			return [$part];
		}

		$half = intdiv($part->h, 2);
		$foldAt = $part->props['foldAt'] ?? null;
		$fold = max(self::Joint, min(is_int($foldAt) ? $foldAt : $half, $half));
		$upper = $fold - intdiv(self::Joint, 2);
		$lower = $part->h - $fold - intdiv(self::Joint, 2);

		return [
			new ResolvedPart(
				$part->name,
				$part->type,
				$part->index,
				$part->x,
				$part->y,
				$part->z,
				$part->w,
				$lower,
				$part->d,
				$part->material,
				$part->props,
				$part->path,
			),
			new ResolvedPart(
				$part->name,
				$part->type,
				$part->index,
				$part->x,
				$part->y + $part->h - $upper,
				$part->z,
				$part->w,
				$upper,
				$part->d,
				$part->material,
				$part->props,
				$part->path,
			),
		];
	}
}

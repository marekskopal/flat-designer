<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\CutList;

use Izbik\Service\Furniture\ResolvedPart;

/**
 * A front framed round its middle (`style` glass or reeded, or shaker with a frame material of its own) is cut as its
 * frame and its middle: two stiles the full height, two rails between them, and the middle a pane of glass (the other
 * parts) or a recessed panel `PanelRecess` thinner than the front. A shaker front in one material is one routed board,
 * so it stays whole. The frame is as wide as the viewer draws it (`frontFrame()` in
 * frontend/src/app/viewer/engine/custom/front-styles.ts).
 */
final readonly class FramedFront
{
	/** The frame width (mm) of a style when the front gives none. */
	public const array FrameWidths = ['shaker' => 70, 'glass' => 40, 'reeded' => 40];

	/** At least this much of the middle (mm) stays inside the frame, across and up. */
	public const int MinMiddle = 20;

	/** A shaker panel is this much (mm) thinner than its frame. */
	public const int PanelRecess = 6;

	/** The thickness (mm) of a glass pane. */
	public const int GlassThickness = 4;

	/**
	 * The pieces of a part as the cut list lists them, named after it (`$label` gives the words stile, rail, panel and
	 * glass in the list's language).
	 *
	 * @param array<mixed> $params the item's params (its `materials` by slot)
	 * @param list<mixed> $slots the definition's material slots
	 * @param callable(string): string $label
	 * @return list<ResolvedPart>
	 */
	public static function cut(ResolvedPart $part, array $params, array $slots, callable $label): array
	{
		if ($part->type !== 'front') {
			return [$part];
		}
		$names = [];
		foreach (['stile', 'rail', 'panel', 'glass'] as $piece) {
			$names[$piece] = $part->name . ': ' . $label($piece);
		}
		$frameSlot = $part->props['frameMaterial'] ?? null;

		return self::pieces($part, is_string($frameSlot) && self::slotFilled($frameSlot, $params, $slots), $names);
	}

	/** The frame width (mm) of a front of `$style`, `$frame` given (0 or null: the style's own), held to leave the middle. */
	public static function width(string $style, ?int $frame, int $w, int $h): int
	{
		$wanted = $frame !== null && $frame > 0 ? $frame : (self::FrameWidths[$style] ?? 0);

		return max(0, min($wanted, intdiv(min($w, $h) - self::MinMiddle, 2)));
	}

	/**
	 * @param bool $ownFrame whether the front's `frameMaterial` slot has a material (the item's or the slot's default)
	 * @param array{stile: string, rail: string, panel: string, glass: string} $names the pieces' names after the front's
	 * @return list<ResolvedPart> the part itself, or its frame and middle
	 */
	public static function pieces(ResolvedPart $part, bool $ownFrame, array $names): array
	{
		$style = $part->props['style'] ?? 'flat';
		$glazed = $style === 'glass' || $style === 'reeded';
		if ($part->type !== 'front' || !($glazed || $style === 'shaker' && $ownFrame)) {
			return [$part];
		}
		$frame = $part->props['frame'] ?? null;
		$f = self::width($style, is_int($frame) || is_float($frame) ? (int) round($frame) : null, $part->w, $part->h);
		if ($f <= 0) {
			return [$part];
		}

		$frameSlot = $part->props['frameMaterial'] ?? null;
		$frameMaterial = $ownFrame && is_string($frameSlot) ? $frameSlot : $part->material;
		$piece = static fn (string $name, string $type, int $x, int $y, int $w, int $h, int $d, ?string $material, array $props): ResolvedPart =>
			new ResolvedPart($name, $type, $part->index, $x, $y, $part->z, $w, $h, $d, $material, $props, $part->path);
		$frameProps = ['edges' => ['left', 'right', 'bottom', 'top']];
		$inner = $part->w - 2 * $f;

		return [
			$piece($names['stile'], 'board', $part->x, $part->y, $f, $part->h, $part->d, $frameMaterial, $frameProps),
			$piece($names['stile'], 'board', $part->x + $part->w - $f, $part->y, $f, $part->h, $part->d, $frameMaterial, $frameProps),
			$piece($names['rail'], 'board', $part->x + $f, $part->y, $inner, $f, $part->d, $frameMaterial, ['grain' => 'horizontal'] + $frameProps),
			$piece(
				$names['rail'],
				'board',
				$part->x + $f,
				$part->y + $part->h - $f,
				$inner,
				$f,
				$part->d,
				$frameMaterial,
				['grain' => 'horizontal'] + $frameProps,
			),
			$glazed
				? $piece($names['glass'], 'glass', $part->x + $f, $part->y + $f, $inner, $part->h - 2 * $f, self::GlassThickness, null, [])
				: $piece(
					$names['panel'],
					'board',
					$part->x + $f,
					$part->y + $f,
					$inner,
					$part->h - 2 * $f,
					max(1, $part->d - self::PanelRecess),
					$part->material,
					[],
				),
		];
	}

	/**
	 * Whether a slot has a material of its own: the item's choice or the slot's default (a front's frame slot without
	 * either keeps the front's material, as the viewer draws it).
	 *
	 * @param array<mixed> $params
	 * @param list<mixed> $slots
	 */
	private static function slotFilled(string $slot, array $params, array $slots): bool
	{
		if (is_array($params['materials'] ?? null) && is_int($params['materials'][$slot] ?? null)) {
			return true;
		}
		foreach ($slots as $definition) {
			if (is_array($definition) && ($definition['key'] ?? null) === $slot && is_string($definition['default'] ?? null)) {
				return true;
			}
		}

		return false;
	}
}

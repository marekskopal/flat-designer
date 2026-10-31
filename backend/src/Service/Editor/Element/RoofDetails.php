<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\GutterProfileEnum;
use FlatDesigner\Model\Entity\Enum\RidgeStyleEnum;
use FlatDesigner\Model\Entity\Enum\RoofEdgeEnum;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use const JSON_THROW_ON_ERROR;

/**
 * A roof's details (FD-91): gutters along the eaves of a pitched roof with downpipes, rows of snow guards on its planes,
 * and its ridge style. Like the parapet, a flat roof given anew gutters or snow guards is refused, and one that had them
 * leaves them behind. Downpipes lie on eaves edges (`RoofEdgeEnum`), `along` mm from the roof's centre line on the eaves
 * line (the edge's middle unless a verge is skewed); null puts them at the eaves' corners. Where they lie is checked when the list is given anew: a roof changed since keeps
 * them, the viewer leaving out one off its eaves.
 */
final readonly class RoofDetails
{
	/** The fields of a state logged before FD-91, with their values then. */
	public const array Defaults = ['gutters' => 'None', 'gutterMaterialId' => null, 'downpipes' => null, 'snowGuards' => 0, 'ridgeStyle' => 'Box'];

	/** Rows of snow guards on a plane. */
	public const int MaxSnowGuards = 5;

	public const int MaxDownpipes = 16;

	/**
	 * @param RoofGeometry $roof the roof as normalised
	 * @return array{gutters: string, downpipes: list<array{edge: string, along: int}>|null, snowGuards: int, ridgeStyle: string}
	 */
	public static function normalize(FieldReader $fields, RoofGeometry $roof, ?Roof $current): array
	{
		$shape = $roof->shape;
		$gutters = $fields->enum('gutters', GutterProfileEnum::class);
		if ($gutters !== GutterProfileEnum::None && !$shape->isPitched()) {
			self::leftBehind($current?->gutters === $gutters, 'gutters');
			$gutters = GutterProfileEnum::None;
		}
		$snowGuards = $fields->int('snowGuards', 0, self::MaxSnowGuards, 'rows');
		if ($snowGuards > 0 && !$shape->isPitched()) {
			self::leftBehind($current?->snowGuards === $snowGuards, 'snow guards');
			$snowGuards = 0;
		}
		$downpipes = self::downpipes($fields->raw('downpipes'));
		$given = $current?->downpipes === null ? null : json_decode($current->downpipes, true, 512, JSON_THROW_ON_ERROR);
		if ($downpipes !== null && $given !== $downpipes) {
			self::checkPlaces($downpipes, $roof);
		}

		return [
			'gutters' => $gutters->value,
			'downpipes' => $downpipes,
			'snowGuards' => $snowGuards,
			'ridgeStyle' => $fields->enum('ridgeStyle', RidgeStyleEnum::class)->value,
		];
	}

	/** @param FieldReader $fields a normalised state's */
	public static function apply(Roof $roof, FieldReader $fields): void
	{
		$roof->gutters = $fields->enum('gutters', GutterProfileEnum::class);
		$downpipes = $fields->raw('downpipes');
		$roof->downpipes = is_array($downpipes) ? json_encode($downpipes, JSON_THROW_ON_ERROR) : null;
		$roof->snowGuards = $fields->int('snowGuards', 0, self::MaxSnowGuards, 'rows');
		$roof->ridgeStyle = $fields->enum('ridgeStyle', RidgeStyleEnum::class);
	}

	/** A detail a flat roof had before stays behind silently; given anew it is refused. */
	private static function leftBehind(bool $had, string $what): void
	{
		if (!$had) {
			throw EditException::invalid(sprintf('Only a pitched roof has %s.', $what));
		}
	}

	/** @return list<array{edge: string, along: int}>|null */
	private static function downpipes(mixed $raw): ?array
	{
		if ($raw === null) {
			return null;
		}
		if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MaxDownpipes) {
			throw EditException::invalid(sprintf('Field "downpipes" must be a list of at most %d downpipes.', self::MaxDownpipes));
		}
		$downpipes = [];
		foreach ($raw as $downpipe) {
			$edge = is_array($downpipe) && is_string($downpipe['edge'] ?? null) ? RoofEdgeEnum::tryFrom($downpipe['edge']) : null;
			$along = is_array($downpipe) ? ($downpipe['along'] ?? null) : null;
			if ($edge === null || !is_int($along)) {
				throw EditException::invalid(
					'A downpipe needs an "edge" (Front, Back, Left or Right) and "along" (mm from the edge\'s middle).',
				);
			}
			$downpipes[] = ['edge' => $edge->value, 'along' => $along];
		}

		return $downpipes;
	}

	/** @param list<array{edge: string, along: int}> $downpipes */
	private static function checkPlaces(array $downpipes, RoofGeometry $roof): void
	{
		foreach ($downpipes as $downpipe) {
			$edge = RoofEdgeEnum::from($downpipe['edge']);
			if (!$edge->isEavesOf($roof->shape)) {
				throw EditException::invalid(
					sprintf('A %s roof has no eaves along its %s edge.', strtolower($roof->shape->value), strtolower($edge->value)),
				);
			}
			[$from, $to] = self::eavesRange($edge, $roof);
			if ($downpipe['along'] < $from || $downpipe['along'] > $to) {
				throw EditException::invalid(sprintf(
					'A downpipe on the %s edge must lie %d to %d mm from the roof\'s centre line.',
					strtolower($edge->value),
					$from,
					$to,
				));
			}
		}
	}

	/**
	 * The `along` a downpipe may take on an eaves edge, whole mm: `RoofGeometry::downpipeSpan()` on the front and back,
	 * within the depth on a hip's sides.
	 *
	 * @return array{int, int}
	 */
	private static function eavesRange(RoofEdgeEnum $edge, RoofGeometry $roof): array
	{
		if (!$edge->alongWidth()) {
			return [-intdiv($roof->depth, 2), intdiv($roof->depth, 2)];
		}
		[$from, $to] = $roof->downpipeSpan($edge === RoofEdgeEnum::Back);

		return [(int) ceil($from), (int) floor($to)];
	}
}

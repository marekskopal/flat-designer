<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Dxf\DxfDrawing;
use Izbik\Service\Dxf\DxfLayer;
use Izbik\Service\Dxf\DxfUnits;
use function array_map;
use function array_values;
use function preg_match;
use function usort;

/**
 * What a DXF holds, for choosing the layers to import: its units (null when the header does not say, or names units a
 * plan is not drawn in) and its layers with what was counted on each and the role its name suggests.
 */
final readonly class DxfSummaryDto
{
	private const string WallNames = '/wall|zed|zeď|zdi|zdiv|stěn|sten|nosn|příčk|prick|mauer|wand/iu';

	private const string OpeningNames = '/door|dver|dveř|okn|window|opening|otvor|glaz|fenster|tür|(^|[-_ .])win([-_ .]|$|d)/iu';

	/** @param list<DxfLayerDto> $layers */
	public function __construct(public ?int $units, public array $layers)
	{
	}

	public static function fromDrawing(DxfDrawing $drawing): self
	{
		$layers = array_values(array_map(
			static fn (DxfLayer $layer): DxfLayerDto => new DxfLayerDto(
				name: $layer->name,
				hidden: $layer->hidden,
				lines: $layer->lines,
				arcs: $layer->arcs,
				inserts: $layer->inserts,
				hatches: $layer->hatches,
				other: $layer->other,
				suggestedRole: self::suggestedRole($layer),
			),
			$drawing->layers,
		));
		usort($layers, static fn (DxfLayerDto $a, DxfLayerDto $b): int => $a->name <=> $b->name);

		return new self(DxfUnits::isKnown($drawing->units) ? $drawing->units : null, $layers);
	}

	private static function suggestedRole(DxfLayer $layer): ?string
	{
		// A hidden layer (frozen or off) holds what the drawing does not show, e.g. walls to be demolished.
		if ($layer->hidden || $layer->lines + $layer->arcs === 0) {
			return null;
		}
		if (preg_match(self::OpeningNames, $layer->name) === 1) {
			return 'opening';
		}

		return preg_match(self::WallNames, $layer->name) === 1 ? 'wall' : null;
	}
}

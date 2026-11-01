<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Dto\PlotDto;
use FlatDesigner\Model\Entity\Plot;

/**
 * The ground of a floor's plots (FD-86): at a plan point, that of the first plot whose boundary holds it (`Terrain`),
 * else the floor's level. The twin is `groundAt()` in `viewer/engine/terrain.ts`.
 */
final readonly class Ground
{
	/** @param list<array{polygon: list<array{float, float}>, terrain: Terrain}> $plots */
	private function __construct(private array $plots)
	{
	}

	/** @param list<Plot> $plots */
	public static function of(array $plots): self
	{
		return self::fromDtos(array_map(static fn (Plot $plot): PlotDto => PlotDto::fromEntity($plot), $plots));
	}

	/** @param list<PlotDto> $plots as a scene serves them */
	public static function fromDtos(array $plots): self
	{
		return new self(array_map(static function (PlotDto $plot): array {
			$polygon = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $plot->polygon);

			return ['polygon' => $polygon, 'terrain' => Terrain::of($plot->terrain, $plot->polygon, $plot->groundLevel)];
		}, $plots));
	}

	/** The ground's height at a plan point, mm against the floor's level. */
	public function at(float $x, float $y): float
	{
		foreach ($this->plots as $plot) {
			if (PlanMath::contains($plot['polygon'], [$x, $y])) {
				return $plot['terrain']->at($x, $y);
			}
		}

		return 0.0;
	}
}

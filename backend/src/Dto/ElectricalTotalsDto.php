<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Furniture\DefinitionCategoryEnum;

/** Counts of the electrical schedule per category, plus the sockets' outlets and the data sockets' ports (their units). */
final readonly class ElectricalTotalsDto
{
	public function __construct(
		public int $lights,
		public int $sockets,
		public int $outlets,
		public int $switches,
		public int $dataSockets,
		public int $dataPorts,
		public int $plumbingPoints,
	) {
	}

	/** @param list<ElectricalRowDto> $rows */
	public static function of(array $rows): self
	{
		$count = static fn (DefinitionCategoryEnum $category): int => array_sum(
			array_map(static fn (ElectricalRowDto $r): int => $r->category === $category ? $r->count : 0, $rows),
		);
		$units = static fn (DefinitionCategoryEnum $category): int => array_sum(
			array_map(static fn (ElectricalRowDto $r): int => $r->category === $category ? $r->units : 0, $rows),
		);

		return new self(
			lights: $count(DefinitionCategoryEnum::Light),
			sockets: $count(DefinitionCategoryEnum::Socket),
			outlets: $units(DefinitionCategoryEnum::Socket),
			switches: $count(DefinitionCategoryEnum::Switch),
			dataSockets: $count(DefinitionCategoryEnum::Data),
			dataPorts: $units(DefinitionCategoryEnum::Data),
			plumbingPoints: $count(DefinitionCategoryEnum::Plumbing),
		);
	}

	/** @param list<self> $totals */
	public static function sum(array $totals): self
	{
		$sum = new self(0, 0, 0, 0, 0, 0, 0);
		foreach ($totals as $t) {
			$sum = new self(
				lights: $sum->lights + $t->lights,
				sockets: $sum->sockets + $t->sockets,
				outlets: $sum->outlets + $t->outlets,
				switches: $sum->switches + $t->switches,
				dataSockets: $sum->dataSockets + $t->dataSockets,
				dataPorts: $sum->dataPorts + $t->dataPorts,
				plumbingPoints: $sum->plumbingPoints + $t->plumbingPoints,
			);
		}

		return $sum;
	}
}

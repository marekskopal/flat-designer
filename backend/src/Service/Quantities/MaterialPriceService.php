<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use DateTimeImmutable;
use FlatDesigner\Dto\MaterialPriceDto;
use FlatDesigner\Dto\MaterialPricesDto;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectMaterialPrice;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\ProjectMaterialPriceRepository;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;

/**
 * A project's price list for the quantities and cost estimate. It is not floor content: saving it bumps no revision
 * and is not undone, versions do not keep it; open pages hear `pricesChanged`.
 */
final readonly class MaterialPriceService
{
	public const int MaxWastePercent = 100;

	private const int MaxPrice = 1000000;

	public function __construct(
		private ProjectMaterialPriceRepository $priceRepository,
		private MaterialRepository $materialRepository,
		private ProjectRepository $projectRepository,
		private ProjectUpdatePublisher $updatePublisher,
	) {
	}

	public function get(Project $project): MaterialPricesDto
	{
		return new MaterialPricesDto(
			currency: $project->currency,
			wastePercent: $project->wastePercent,
			prices: array_map(MaterialPriceDto::fromEntity(...), $this->priceRepository->findByProject($project->id)),
		);
	}

	/**
	 * Sets some prices and keeps the others (the MCP tool); currency and waste stay when null.
	 *
	 * @param list<MaterialPriceDto> $prices
	 */
	public function merge(Project $project, array $prices, ?string $currency, ?int $wastePercent): MaterialPricesDto
	{
		$current = $this->get($project);
		$merged = [];
		foreach ([...$current->prices, ...$prices] as $price) {
			$merged[$price->key()] = $price;
		}

		return $this->replace($project, new MaterialPricesDto(
			currency: $currency ?? $current->currency,
			wastePercent: $wastePercent ?? $current->wastePercent,
			prices: array_values($merged),
		));
	}

	/** Replaces the price list; a price with neither amount is dropped. */
	public function replace(Project $project, MaterialPricesDto $input): MaterialPricesDto
	{
		$currency = strtoupper(trim($input->currency));
		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			throw new ProjectException('Currency must be a three-letter code, e.g. EUR or CZK.');
		}
		if ($input->wastePercent < 0 || $input->wastePercent > self::MaxWastePercent) {
			throw new ProjectException(sprintf('Waste must be between 0 and %d %%.', self::MaxWastePercent));
		}

		$now = new DateTimeImmutable();
		$rows = [];
		foreach ($input->prices as $price) {
			if ($price->pricePerArea === null && $price->pricePerLength === null) {
				continue;
			}
			if (isset($rows[$price->key()])) {
				throw new ProjectException('Each material, and each surface\'s default look, may have one price only.');
			}
			$rows[$price->key()] = $this->row($project, $price, $now);
		}

		foreach ($this->priceRepository->findByProject($project->id) as $old) {
			$this->priceRepository->scheduleDelete($old);
		}
		foreach ($rows as $row) {
			$this->priceRepository->schedulePersist($row);
		}
		$project->currency = $currency;
		$project->wastePercent = $input->wastePercent;
		$project->updatedAt = $now;
		$this->projectRepository->schedulePersist($project);
		$this->priceRepository->flush();
		$this->updatePublisher->markPricesChanged($project->id);

		return $this->get($project);
	}

	private function row(Project $project, MaterialPriceDto $price, DateTimeImmutable $now): ProjectMaterialPrice
	{
		foreach ([$price->pricePerArea, $price->pricePerLength] as $amount) {
			if ($amount !== null && !($amount >= 0 && $amount <= self::MaxPrice)) {
				throw new ProjectException(sprintf('Prices must be between 0 and %d.', self::MaxPrice));
			}
		}
		if (($price->materialId === null) === ($price->surface === null)) {
			throw new ProjectException('A price is for a material (materialId) or for a surface\'s default look (surface), not both.');
		}
		$material = $price->materialId === null ? null : $this->materialRepository->findById($price->materialId)
			?? throw new ProjectException(sprintf('Material %d not found.', $price->materialId));

		$row = new ProjectMaterialPrice(
			project: $project,
			material: $material,
			surface: $material === null ? $price->surface : null,
			pricePerArea: $price->pricePerArea === null ? null : round($price->pricePerArea, 2),
			pricePerLength: $price->pricePerLength === null ? null : round($price->pricePerLength, 2),
		);
		$row->createdAt = $now;
		$row->updatedAt = $now;

		return $row;
	}
}

<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use Izbik\Dto\FurnitureDefinitionInputDto;
use Izbik\Dto\MaterialPriceDto;
use Izbik\Dto\MaterialPricesDto;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Service\Furniture\FurnitureDefinitionProvider;
use Izbik\Service\Furniture\FurnitureException;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\SnapshotJson;
use Izbik\Service\Quantities\MaterialPriceService;
use Izbik\Service\Request\BodyReader;
use Izbik\Service\Request\Exception\InvalidRequestException;

/**
 * What a project file holds besides the snapshot (`ProjectImporter`): the project's own furniture definitions and its
 * prices, materials by code. One this instance does not take is left out with a warning.
 */
final readonly class BundleLibraryImporter
{
	public function __construct(
		private FurnitureDefinitionProvider $definitionProvider,
		private MaterialPriceService $priceService,
		private MaterialRepository $materialRepository,
	) {
	}

	/**
	 * The project's own definitions, validated as a new one is; the snapshot's restore then links its items to them
	 * (`SnapshotDefinitionRestorer` reuses a project definition of the same name and content).
	 *
	 * @param list<array<mixed>> $definitions
	 * @param list<string> $warnings
	 */
	public function definitions(User $owner, Project $project, array $definitions, array &$warnings): void
	{
		foreach ($definitions as $definition) {
			$name = is_string($definition['name'] ?? null) ? $definition['name'] : '';

			try {
				$this->definitionProvider->create($owner, new FurnitureDefinitionInputDto(
					name: $name,
					description: self::text($definition, 'description'),
					scope: FurnitureScopeEnum::Project,
					projectId: $project->id,
					definition: BodyReader::object(['definition' => $definition['definition'] ?? null], 'definition'),
				));
			} catch (FurnitureException | InvalidRequestException $e) {
				$warnings[] = sprintf(
					'Furniture definition "%s" was left out of the project\'s furniture: %s (an item using it keeps it as it was).',
					$name,
					$e->getMessage(),
				);
			}
		}
	}

	/**
	 * The prices, each by a material of this instance (by code) or a surface's default look; one this instance cannot
	 * take is left out alone, and a currency or waste it cannot take leaves the project's default.
	 *
	 * @param array<mixed> $pricing
	 * @param list<string> $warnings
	 */
	public function prices(Project $project, array $pricing, array &$warnings): void
	{
		if ($pricing === []) {
			return;
		}
		$prices = [];
		foreach (SnapshotJson::arrays($pricing['prices'] ?? null) as $price) {
			$prices[] = $this->price($price, $warnings);
		}
		$prices = array_values(array_filter($prices));
		$currency = is_string($pricing['currency'] ?? null) ? $pricing['currency'] : $project->currency;
		$waste = is_int($pricing['wastePercent'] ?? null) ? $pricing['wastePercent'] : $project->wastePercent;

		try {
			$this->priceService->replace($project, new MaterialPricesDto(currency: $currency, wastePercent: $waste, prices: $prices));
		} catch (ProjectException $e) {
			$warnings[] = sprintf('The currency and waste were left at the defaults: %s', $e->getMessage());
			$this->priceService->replace(
				$project,
				new MaterialPricesDto(currency: $project->currency, wastePercent: $project->wastePercent, prices: $prices),
			);
		}
	}

	/**
	 * @param array<mixed> $price the bundle's entry
	 * @param list<string> $warnings
	 */
	private function price(array $price, array &$warnings): ?MaterialPriceDto
	{
		$code = $price['materialCode'] ?? null;
		$material = is_string($code) ? $this->materialRepository->findByCode($code) : null;
		$surfaceName = $price['surface'] ?? null;
		$surface = is_string($surfaceName) ? QuantitySurfaceEnum::tryFrom($surfaceName) : null;
		$problem = match (true) {
			is_string($code) && $material === null => sprintf('this instance has no material "%s"', $code),
			is_string($surfaceName) && $surface === null => sprintf('this instance has no surface "%s"', $surfaceName),
			($material === null) === ($surface === null) => 'it is for neither one material nor one surface',
			default => null,
		};
		if ($problem !== null) {
			$warnings[] = sprintf('A price was left out: %s.', $problem);

			return null;
		}

		return new MaterialPriceDto(
			materialId: $material?->id,
			surface: $surface,
			pricePerArea: self::number($price, 'pricePerArea'),
			pricePerLength: self::number($price, 'pricePerLength'),
		);
	}

	/** @param array<mixed> $data */
	private static function text(array $data, string $key): string
	{
		return is_string($data[$key] ?? null) ? $data[$key] : '';
	}

	/** @param array<mixed> $data */
	private static function number(array $data, string $key): ?float
	{
		$value = $data[$key] ?? null;

		return is_int($value) || is_float($value) ? (float) $value : null;
	}
}

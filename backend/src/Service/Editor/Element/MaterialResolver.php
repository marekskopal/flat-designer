<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Element;

use Izbik\Model\Entity\Material;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Service\Editor\Exception\EditException;

final readonly class MaterialResolver
{
	public function __construct(private MaterialRepository $materialRepository)
	{
	}

	public function resolve(?int $materialId): ?Material
	{
		if ($materialId === null) {
			return null;
		}

		return $this->materialRepository->findById($materialId) ?? throw EditException::invalid(
			sprintf('Material %d does not exist.', $materialId),
		);
	}
}

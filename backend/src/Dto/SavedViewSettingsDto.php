<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\ShareViewEnum;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;
use JsonSerializable;
use stdClass;

/**
 * How a saved view shows its floor: the view mode (`overview`, the 3D view, or `top`, the plan; an eye-level shot is a 3D
 * camera at eye height), the 3D camera (null = the default framing), the display options (`sun`: the sun at a clock time
 * on site, null = the default afternoon sun; `allFloors` draws the floors under it below; `roofs` shows the roofs) and
 * the active variant of each variant set (`variants`: set id → group id; sets left out show their current variant).
 */
final readonly class SavedViewSettingsDto implements ArrayFactoryInterface, JsonSerializable
{
	/** @param array<int, int> $variants */
	public function __construct(
		public ShareViewEnum $view,
		public ?ShareCameraDto $camera,
		public bool $ceilings,
		public bool $labels,
		public ?int $sectionHeight,
		public bool $dimensions,
		public bool $night,
		/** Null: the lights follow the night. */
		public ?bool $lightsOn,
		public bool $presentation,
		public array $variants,
		public ?SunTimeDto $sun = null,
		public bool $allFloors = false,
		public bool $roofs = true,
	) {
	}

	public static function fromArray(array $data): static
	{
		$view = ShareViewEnum::tryFrom(BodyReader::string($data, 'view'));
		if ($view === null || $view === ShareViewEnum::Walkthrough) {
			throw new InvalidRequestException('Field "view" must be "overview" (3D) or "top" (plan).', 422);
		}
		$camera = BodyReader::optionalObject($data, 'camera');

		return new self(
			view: $view,
			camera: $camera === null ? null : ShareCameraDto::fromArray($camera),
			ceilings: self::flag($data, 'ceilings', false),
			labels: self::flag($data, 'labels', true),
			sectionHeight: BodyReader::optionalInt($data, 'sectionHeight'),
			dimensions: self::flag($data, 'dimensions', false),
			night: self::flag($data, 'night', false),
			lightsOn: ($data['lightsOn'] ?? null) === null ? null : BodyReader::bool($data, 'lightsOn'),
			presentation: self::flag($data, 'presentation', false),
			variants: self::variants($data['variants'] ?? null),
			sun: SunTimeDto::optional($data),
			allFloors: self::flag($data, 'allFloors', false),
			// Views saved before the roofs show them.
			roofs: self::flag($data, 'roofs', true),
		);
	}

	/** @return array<string, mixed> `variants` as an object, also with one entry or none */
	public function jsonSerialize(): array
	{
		return [
			'view' => $this->view->value,
			'camera' => $this->camera,
			'ceilings' => $this->ceilings,
			'roofs' => $this->roofs,
			'labels' => $this->labels,
			'sectionHeight' => $this->sectionHeight,
			'dimensions' => $this->dimensions,
			'night' => $this->night,
			'lightsOn' => $this->lightsOn,
			'sun' => $this->sun,
			'presentation' => $this->presentation,
			'allFloors' => $this->allFloors,
			'variants' => $this->variantsObject(),
		];
	}

	/** @param array<int, int> $variants */
	public function withVariants(array $variants): self
	{
		return clone($this, ['variants' => $variants]);
	}

	private function variantsObject(): stdClass
	{
		$object = new stdClass();
		foreach ($this->variants as $setId => $groupId) {
			$object->{(string) $setId} = $groupId;
		}

		return $object;
	}

	/** @param array<string, mixed> $data */
	private static function flag(array $data, string $key, bool $default): bool
	{
		return array_key_exists($key, $data) ? BodyReader::bool($data, $key) : $default;
	}

	/** @return array<int, int> */
	private static function variants(mixed $value): array
	{
		if ($value === null) {
			return [];
		}
		if (!is_array($value)) {
			throw new InvalidRequestException('Field "variants" must be an object of variant set id: group id.', 422);
		}
		$variants = [];
		foreach ($value as $setId => $groupId) {
			if (!is_numeric($setId) || !is_int($groupId)) {
				throw new InvalidRequestException('Field "variants" must be an object of variant set id: group id.', 422);
			}
			$variants[(int) $setId] = $groupId;
		}

		return $variants;
	}
}

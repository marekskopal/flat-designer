<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Element;

use DateTimeImmutable;
use Izbik\Dto\RoofWindowDto;
use Izbik\Model\Entity\AEntity;
use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Model\Entity\Enum\RoofShapeEnum;
use Izbik\Model\Entity\Enum\RoofWindowTypeEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Roof;
use Izbik\Model\Entity\RoofWindow;
use Izbik\Model\Repository\RoofRepository;
use Izbik\Model\Repository\RoofWindowRepository;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\FieldReader;
use Izbik\Service\Editor\Geometry\RoofGeometry;
use const M_PI;

/**
 * Windows in a roof's planes. A window stays on the roof it was created on (`roofId` is fixed) and is placed in
 * the roof's frame, so it moves with the roof; `RoofWindowPlacement` keeps it inside its plane, clear of the others. The
 * placement is checked only when it is set anew: a roof change is not checked against its windows, so one the roof has
 * left behind is still renamed and undone (the viewer leaves it out).
 */
final readonly class RoofWindowHandler implements ElementHandler
{
	/** mm, across the slope and up it. */
	public const int MinSize = 300;

	public const int MaxSize = 3000;

	private const int DefaultWidth = 780;

	private const int DefaultLength = 1180;

	/** mm in plan from the eaves line (the wall plate, not the overhang's edge) to a new window's lower edge. */
	private const int EavesOffset = 400;

	public function __construct(
		private RoofWindowRepository $roofWindowRepository,
		private RoofRepository $roofRepository,
		private MaterialResolver $materialResolver,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::RoofWindow;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['roofId', 'name', 'type', 'along', 'across', 'width', 'length', 'frameMaterialId'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/**
	 * A centre-pivot window 780 × 1180 mm (a common size) low on the roof's widest plane, at the middle of its eaves and
	 * `EavesOffset` up from them in plan (at eye height in an attic): the front plane, or the right side of a hip deeper
	 * than wide, whose front is a triangle too narrow on a small roof.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(Floor $floor, array $fields): array
	{
		$roofId = $fields['roofId'] ?? null;
		$roof = is_int($roofId) ? $this->roofRepository->findOnFloor($floor->id, $roofId) : null;
		$along = 0;
		$across = 0;
		if ($roof !== null && $roof->shape->isPitched()) {
			$offset = self::EavesOffset + self::DefaultLength * cos($roof->pitch * M_PI / 180) / 2;
			if ($roof->shape === RoofShapeEnum::Hip && $roof->depth > $roof->width) {
				$along = (int) round($roof->width / 2 - $offset);
			} else {
				$across = (int) round($offset - $roof->depth / 2);
			}
		}

		return [
			'name' => 'Roof window',
			'type' => RoofWindowTypeEnum::CentrePivot->value,
			'along' => $along,
			'across' => $across,
			'width' => self::DefaultWidth,
			'length' => self::DefaultLength,
			'frameMaterialId' => null,
		];
	}

	public function find(Floor $floor, int $id): ?RoofWindow
	{
		$window = $this->roofWindowRepository->findById($id);

		return $window !== null && $window->roof->floor->id === $floor->id ? $window : null;
	}

	/** A window of a deleted roof counts as deleted too. */
	public function isDeleted(AEntity $element): bool
	{
		$window = self::cast($element);

		return $window->deletedAt !== null || $window->roof->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$window = self::cast($element);

		return ['roofId' => $window->roof->id] + State::of(RoofWindowDto::fromEntity($window));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$currentWindow = $current === null ? null : self::cast($current);
		$fields = new FieldReader($state);
		$roofId = $fields->nullableId('roofId') ?? throw EditException::invalid('Field "roofId" is required.');
		if ($currentWindow !== null && $currentWindow->roof->id !== $roofId) {
			throw EditException::invalid('A roof window cannot be moved to another roof; delete it and add a new one.');
		}
		$roof = $this->roof($floor, $roofId);
		$along = $fields->int('along', -State::CoordinateLimit, State::CoordinateLimit);
		$across = $fields->int('across', -State::CoordinateLimit, State::CoordinateLimit);
		$width = $fields->int('width', self::MinSize, self::MaxSize);
		$length = $fields->int('length', self::MinSize, self::MaxSize);
		if (self::placedAnew($currentWindow, $along, $across, $width, $length)) {
			RoofWindowPlacement::check(
				RoofGeometry::fromRoof($roof),
				$along,
				$across,
				$width,
				$length,
				array_values(array_filter(
					$this->roofWindowRepository->findByRoofs([$roof->id]),
					fn (RoofWindow $w): bool => $w->id !== $currentWindow?->id,
				)),
			);
		}
		$frameMaterialId = $fields->nullableId('frameMaterialId');
		$this->materialResolver->resolve($frameMaterialId);

		return [
			'roofId' => $roof->id,
			'name' => $fields->string('name', 255),
			'type' => $fields->enum('type', RoofWindowTypeEnum::class)->value,
			'along' => $along,
			'across' => $across,
			'width' => $width,
			'length' => $length,
			'frameMaterialId' => $frameMaterialId,
		];
	}

	/** A new window, or one whose place or size is set anew. */
	private static function placedAnew(?RoofWindow $current, int $along, int $across, int $width, int $length): bool
	{
		return $current === null
			|| [$along, $across, $width, $length] !== [$current->along, $current->across, $current->width, $current->length];
	}

	public function create(Floor $floor, array $state): RoofWindow
	{
		$fields = new FieldReader($state);
		$window = State::stamp(new RoofWindow(
			roof: $this->roof($floor, $fields->nullableId('roofId') ?? 0),
			name: '',
			type: RoofWindowTypeEnum::CentrePivot,
			along: 0,
			across: 0,
			width: self::MinSize,
			length: self::MinSize,
		));
		$this->apply($window, $state);

		return $window;
	}

	public function update(AEntity $element, array $state): void
	{
		$window = self::cast($element);
		$window->updatedAt = new DateTimeImmutable();
		$this->apply($window, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$window = self::cast($element);
		$window->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$window->updatedAt = new DateTimeImmutable();
		$this->roofWindowRepository->persist($window);
	}

	public function label(array $state): string
	{
		return sprintf('roof window "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(RoofWindow $window, array $state): void
	{
		$fields = new FieldReader($state);
		$window->name = $fields->string('name', 255);
		$window->type = $fields->enum('type', RoofWindowTypeEnum::class);
		$window->along = $fields->int('along', -State::CoordinateLimit, State::CoordinateLimit);
		$window->across = $fields->int('across', -State::CoordinateLimit, State::CoordinateLimit);
		$window->width = $fields->int('width', self::MinSize, self::MaxSize);
		$window->length = $fields->int('length', self::MinSize, self::MaxSize);
		$window->frameMaterial = $this->materialResolver->resolve($fields->nullableId('frameMaterialId'));
		$this->roofWindowRepository->persist($window);
	}

	private function roof(Floor $floor, int $roofId): Roof
	{
		$roof = $this->roofRepository->findOnFloor($floor->id, $roofId);
		if ($roof === null) {
			throw EditException::invalid(sprintf('Roof %d is not on this floor.', $roofId));
		}
		if ($roof->deletedAt !== null) {
			throw EditException::invalid(sprintf('Roof %d is deleted; undo that first.', $roofId));
		}

		return $roof;
	}

	private static function cast(AEntity $element): RoofWindow
	{
		assert($element instanceof RoofWindow);

		return $element;
	}
}

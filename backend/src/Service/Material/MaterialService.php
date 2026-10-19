<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Material;

use DateTimeImmutable;
use FlatDesigner\Dto\MaterialInputDto;
use FlatDesigner\Model\Entity\Enum\MaterialPatternEnum;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;

/**
 * Admins add and change materials of the global catalogue. Not an element change: no revision, no change log. The code
 * is permanent (furniture definitions name slot defaults by it); the texture stays as seeded. A changed material tells
 * every project (`materialChanged`), since finding where it is used (items, walls, rooms, wall finishes, Custom params,
 * definition defaults) is not worth it.
 */
final readonly class MaterialService
{
	private const int MaxCodeLength = 64;

	private const int MaxNameLength = 255;

	public function __construct(private MaterialRepository $materialRepository, private ProjectUpdatePublisher $updatePublisher)
	{
	}

	public function create(User $author, MaterialInputDto $input): Material
	{
		self::assertAdmin($author);
		$code = self::code($input->code);
		$existing = $this->materialRepository->findByCode($code);
		if ($existing !== null) {
			throw new MaterialException(sprintf('Material code "%s" exists (id %d).', $code, $existing->id));
		}

		[$tileWidth, $tileHeight] = self::tileSize($input->tileWidth, $input->tileHeight);
		$material = new Material(
			code: $code,
			name: self::name($input->name),
			pattern: $input->pattern,
			color: self::color($input->color),
			roughness: self::unit('roughness', $input->roughness),
			metalness: self::unit('metalness', $input->metalness),
			texture: null,
			tileWidth: $tileWidth,
			tileHeight: $tileHeight,
			createdBy: $author,
		);
		$material->createdAt = new DateTimeImmutable();
		$material->updatedAt = $material->createdAt;
		$this->materialRepository->persist($material);

		return $material;
	}

	/** Everything but the code and the texture. */
	public function update(User $user, Material $material, MaterialInputDto $input): Material
	{
		self::assertAdmin($user);
		if ($input->code !== $material->code) {
			throw new MaterialException('The code of a material cannot be changed; furniture definitions refer to it.');
		}

		$material->name = self::name($input->name);
		$material->pattern = $input->pattern;
		$material->color = self::color($input->color);
		$material->roughness = self::unit('roughness', $input->roughness);
		$material->metalness = self::unit('metalness', $input->metalness);
		[$material->tileWidth, $material->tileHeight] = self::tileSize($input->tileWidth, $input->tileHeight);
		$material->updatedAt = new DateTimeImmutable();
		$this->materialRepository->persist($material);
		$this->updatePublisher->markMaterialChanged($material->id);

		return $material;
	}

	public static function pattern(string $pattern): MaterialPatternEnum
	{
		return MaterialPatternEnum::tryFrom($pattern) ?? throw new MaterialException(sprintf(
			'Field "pattern" must be one of %s.',
			implode(', ', array_map(static fn (MaterialPatternEnum $case): string => $case->value, MaterialPatternEnum::cases())),
		));
	}

	private static function assertAdmin(User $user): void
	{
		if (!$user->isAdmin()) {
			throw MaterialException::forbidden('Only admins manage materials.');
		}
	}

	private static function code(string $code): string
	{
		if (strlen($code) > self::MaxCodeLength || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code) !== 1) {
			throw new MaterialException(sprintf(
				'Field "code" must be kebab-case (lower-case letters, digits, hyphens), at most %d characters, e.g. "oak-veneer".',
				self::MaxCodeLength,
			));
		}

		return $code;
	}

	private static function name(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxNameLength) {
			throw new MaterialException(sprintf('Field "name" must be 1 to %d characters.', self::MaxNameLength));
		}

		return $name;
	}

	private static function color(string $color): string
	{
		if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new MaterialException('Field "color" must be #rrggbb, e.g. "#f2f1ed".');
		}

		return strtolower($color);
	}

	private static function unit(string $field, float $value): float
	{
		if ($value < 0.0 || $value > 1.0) {
			throw new MaterialException(sprintf('Field "%s" must be between 0 and 1.', $field));
		}

		return $value;
	}

	/** @return array{int|null, int|null} */
	private static function tileSize(?int $width, ?int $height): array
	{
		if (($width === null) !== ($height === null)) {
			throw new MaterialException('Fields "tileWidth" and "tileHeight" go together.');
		}
		if (($width !== null && $width <= 0) || ($height !== null && $height <= 0)) {
			throw new MaterialException('Fields "tileWidth" and "tileHeight" must be positive (mm).');
		}

		return [$width, $height];
	}
}

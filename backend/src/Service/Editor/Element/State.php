<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\AEntity;
use const JSON_THROW_ON_ERROR;

/** Helpers shared by the element handlers. */
final readonly class State
{
	/** Coordinates are limited to ±100 m around the floor origin. */
	public const int CoordinateLimit = 100000;
	public const int MaxSize = 20000;

	/**
	 * The DTO as the decoded JSON the client sees.
	 *
	 * @return array<string, mixed>
	 */
	public static function of(object $dto): array
	{
		/** @var array<string, mixed> $state */
		$state = json_decode(json_encode($dto, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

		return $state;
	}

	/** Same JSON value regardless of the key order of objects. */
	public static function equals(mixed $a, mixed $b): bool
	{
		return self::canonical($a) === self::canonical($b);
	}

	/**
	 * @param T $entity
	 * @return T
	 * @template T of AEntity
	 */
	public static function stamp(AEntity $entity): AEntity
	{
		$entity->createdAt = new DateTimeImmutable();
		$entity->updatedAt = $entity->createdAt;

		return $entity;
	}

	private static function canonical(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		if (!array_is_list($value)) {
			ksort($value);
		}

		return array_map(self::canonical(...), $value);
	}
}

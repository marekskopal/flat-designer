<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Request body DTO built from the decoded JSON object; throws `InvalidRequestException` on bad input. */
interface ArrayFactoryInterface
{
	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): static;
}

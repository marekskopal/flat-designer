<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

final readonly class Token
{
	public function __construct(public TokenTypeEnum $type, public string $value, public int $position)
	{
	}
}

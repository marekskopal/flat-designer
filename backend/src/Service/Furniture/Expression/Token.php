<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\Expression;

final readonly class Token
{
	public function __construct(public TokenTypeEnum $type, public string $value, public int $position)
	{
	}
}

<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Example\Example;

/** An example project a user may start from. */
final readonly class ExampleDto
{
	public function __construct(public string $slug, public string $name, public string $description, public string $credit)
	{
	}

	public static function fromExample(Example $example): self
	{
		return new self(slug: $example->slug, name: $example->name, description: $example->description, credit: $example->credit);
	}
}

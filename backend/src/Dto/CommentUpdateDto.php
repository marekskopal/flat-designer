<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Body of a comment update: the new text (author only) and/or whether its thread is resolved; null leaves it. */
final readonly class CommentUpdateDto implements ArrayFactoryInterface
{
	public function __construct(public ?string $body, public ?bool $resolved)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			body: BodyReader::optionalString($data, 'body'),
			resolved: array_key_exists('resolved', $data) && $data['resolved'] !== null ? BodyReader::bool($data, 'resolved') : null,
		);
	}
}

<?php

declare(strict_types=1);

namespace Izbik\Service\Storage;

/** An object as stored: its bytes and content type. */
final readonly class StoredObject
{
	public function __construct(public string $body, public string $contentType)
	{
	}
}

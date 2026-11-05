<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use RuntimeException;

/** A file the DXF reader cannot take (not ASCII DXF, too large, malformed); the message is meant for the user. */
final class DxfException extends RuntimeException
{
	public function __construct(string $message)
	{
		parent::__construct($message, 422);
	}
}

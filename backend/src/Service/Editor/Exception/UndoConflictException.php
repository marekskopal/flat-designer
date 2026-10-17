<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Exception;

use FlatDesigner\Model\Entity\ProjectChange;

/** Raised inside the undo transaction; the change is closed after the rollback. */
final class UndoConflictException extends \RuntimeException
{
	public function __construct(public readonly ProjectChange $change, string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 409, $previous);
	}
}

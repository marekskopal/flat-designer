<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Authentication\Exception;

final class AccountLockedException extends \RuntimeException
{
	public function __construct(public readonly int $retryAfterSeconds)
	{
		parent::__construct('Account temporarily locked due to repeated failed sign-in attempts.');
	}
}

<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Request\BodyReader;
use SensitiveParameter;

/** The confirmation of deleting one's own account: the password, or the email for an account without one (Google only). */
final readonly class DeleteAccountDto implements ArrayFactoryInterface
{
	public function __construct(#[SensitiveParameter] public ?string $password, public ?string $email)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(password: BodyReader::optionalString($data, 'password'), email: BodyReader::optionalString($data, 'email'));
	}
}

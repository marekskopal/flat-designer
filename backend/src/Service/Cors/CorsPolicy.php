<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Cors;

final readonly class CorsPolicy
{
	public const string AllowAnyOrigin = '*';

	/** @param list<string> $origins */
	private function __construct(private array $origins)
	{
	}

	public static function fromEnvValue(string $raw): self
	{
		$trimmed = trim($raw);
		if ($trimmed === '') {
			return new self([]);
		}

		$parts = preg_split('/[\s,]+/', $trimmed);
		$origins = [];
		foreach ($parts === false ? [] : $parts as $part) {
			if ($part === '') {
				continue;
			}
			$origins[] = $part;
		}

		return new self(array_values(array_unique($origins)));
	}

	public function allowsAnyOrigin(): bool
	{
		return in_array(self::AllowAnyOrigin, $this->origins, strict: true);
	}

	/** @return list<string> */
	public function origins(): array
	{
		return $this->origins;
	}

	public function resolveAllowedOrigin(?string $requestOrigin): ?string
	{
		if ($this->origins === []) {
			return null;
		}

		if ($this->allowsAnyOrigin()) {
			return self::AllowAnyOrigin;
		}

		if ($requestOrigin === null || $requestOrigin === '') {
			return null;
		}

		return in_array($requestOrigin, $this->origins, strict: true) ? $requestOrigin : null;
	}
}

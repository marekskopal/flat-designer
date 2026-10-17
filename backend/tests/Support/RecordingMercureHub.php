<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;
use const JSON_THROW_ON_ERROR;

/** Stands in for the Mercure hub in tests and keeps what was published. */
final class RecordingMercureHub implements HubInterface
{
	/** @var list<Update> */
	public array $updates = [];

	public function publish(Update $update): string
	{
		$this->updates[] = $update;

		return 'urn:uuid:' . count($this->updates);
	}

	/** @return list<array<string, mixed>> the published data, decoded */
	public function messages(): array
	{
		$messages = [];
		foreach ($this->updates as $update) {
			/** @var array<string, mixed> $data */
			$data = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
			$messages[] = $data;
		}

		return $messages;
	}

	public function getPublicUrl(): string
	{
		return '/.well-known/mercure';
	}

	public function getFactory(): ?TokenFactoryInterface
	{
		return null;
	}

	public function getProtocolVersion(): ProtocolVersion
	{
		return ProtocolVersion::V1;
	}

	public function getCookieName(): string
	{
		return 'mercureAuthorization';
	}
}

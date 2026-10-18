<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\ShareLink;
use const DATE_ATOM;

/** A share link as its owner sees it; `url` is `{app}/s/{token}` (null when the app's base URL is not known). */
final readonly class ShareLinkDto
{
	public function __construct(
		public int $id,
		public string $token,
		public string $name,
		public int $floorId,
		public string $floorName,
		public int $versionId,
		public int $versionNumber,
		public ShareSettingsDto $settings,
		public ?string $expiresAt,
		public bool $expired,
		public ?string $createdByName,
		public string $createdAt,
		public ?string $url,
	) {
	}

	public static function fromEntity(ShareLink $link, ShareSettingsDto $settings, ?string $baseUrl): self
	{
		return new self(
			id: $link->id,
			token: $link->token,
			name: $link->name,
			floorId: $link->floorId,
			floorName: $link->floorName,
			versionId: $link->version->id,
			versionNumber: $link->version->number,
			settings: $settings,
			expiresAt: $link->expiresAt?->format(DATE_ATOM),
			expired: $link->isExpired(new DateTimeImmutable()),
			createdByName: $link->createdBy?->name,
			createdAt: $link->createdAt->format(DATE_ATOM),
			url: $baseUrl === null || $baseUrl === '' ? null : $baseUrl . '/s/' . $link->token,
		);
	}
}

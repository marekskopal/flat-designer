<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/**
 * Body of a new comment: a thread pinned to a point (plan mm, `z` above the floor) or a reply (`parentId`, which takes
 * the thread's point). `floorId` is required in the app and ignored for a share link's guest (`guestName`), whose floor
 * is the link's.
 */
final readonly class CommentInputDto implements ArrayFactoryInterface
{
	public function __construct(
		public ?int $floorId,
		public ?int $parentId,
		public ?int $x,
		public ?int $y,
		public ?int $z,
		public string $body,
		public ?string $guestName = null,
	) {
	}

	public static function fromArray(array $data): static
	{
		return new self(
			floorId: BodyReader::optionalInt($data, 'floorId'),
			parentId: BodyReader::optionalInt($data, 'parentId'),
			x: BodyReader::optionalInt($data, 'x'),
			y: BodyReader::optionalInt($data, 'y'),
			z: BodyReader::optionalInt($data, 'z'),
			body: BodyReader::string($data, 'body'),
			guestName: BodyReader::optionalString($data, 'guestName'),
		);
	}
}

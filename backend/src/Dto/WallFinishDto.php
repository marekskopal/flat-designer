<?php

declare(strict_types=1);

namespace Izbik\Dto;

use JsonSerializable;

/**
 * Horizontal band of a wall finish (e.g. tiling) around a room, heights in mm above the floor. A wall face's own band
 * may line only a stretch of it, `from`–`to` mm along the wall's centre line from its start (null: from its start /
 * to its end); a band without either lines the whole face.
 */
final readonly class WallFinishDto implements JsonSerializable
{
	public function __construct(
		public int $materialId,
		public int $bottom,
		public int $top,
		public ?int $from = null,
		public ?int $to = null,
	)
	{
	}

	/** @param array{materialId: int, bottom: int, top: int, from?: int, to?: int} $band */
	public static function fromArray(array $band): self
	{
		return new self($band['materialId'], $band['bottom'], $band['top'], $band['from'] ?? null, $band['to'] ?? null);
	}

	/** Whether the band lines only a stretch of its face. */
	public function isRanged(): bool
	{
		return $this->from !== null || $this->to !== null;
	}

	/** @return array{materialId: int, bottom: int, top: int, from?: int, to?: int} */
	public function jsonSerialize(): array
	{
		$band = ['materialId' => $this->materialId, 'bottom' => $this->bottom, 'top' => $this->top];
		if ($this->from !== null) {
			$band['from'] = $this->from;
		}
		if ($this->to !== null) {
			$band['to'] = $this->to;
		}

		return $band;
	}
}

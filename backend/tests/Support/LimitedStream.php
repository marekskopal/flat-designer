<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use LogicException;
use Psr\Http\Message\StreamInterface;
use const SEEK_SET;

/** A read-only body of `$size` zero bytes that is never held in memory as a whole; a test of reads that stop early. */
final class LimitedStream implements StreamInterface
{
	private int $position = 0;

	public function __construct(private readonly int $size)
	{
	}

	public function __toString(): string
	{
		throw new LogicException('The body must be read in chunks, not as a whole.');
	}

	public function close(): void
	{
		// Nothing is open.
	}

	public function detach(): mixed
	{
		return null;
	}

	public function getSize(): int
	{
		return $this->size;
	}

	public function tell(): int
	{
		return $this->position;
	}

	public function eof(): bool
	{
		return $this->position >= $this->size;
	}

	public function isSeekable(): bool
	{
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new LogicException('Not seekable.');
	}

	public function rewind(): void
	{
		throw new LogicException('Not seekable.');
	}

	public function isWritable(): bool
	{
		return false;
	}

	public function write(string $string): int
	{
		throw new LogicException('Not writable.');
	}

	public function isReadable(): bool
	{
		return true;
	}

	public function read(int $length): string
	{
		$chunk = str_repeat("\0", max(0, min($length, $this->size - $this->position)));
		$this->position += strlen($chunk);

		return $chunk;
	}

	public function getContents(): string
	{
		throw new LogicException('The body must be read in chunks, not as a whole.');
	}

	public function getMetadata(?string $key = null): mixed
	{
		return $key === null ? [] : null;
	}
}

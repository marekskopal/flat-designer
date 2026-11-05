<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Read;

use Izbik\Service\Dxf\DxfException;
use function ctype_digit;
use function in_array;
use function ltrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * The group code / value pairs of an ASCII DXF, read line by line from the file's text (LF or CRLF), and the records
 * they make: each starts with a code 0 pair naming its type.
 *
 * @phpstan-type Pair array{int, string}
 * @phpstan-type Entity array{type: string, pairs: list<Pair>, vertices?: list<list<Pair>>}
 */
final class DxfTokenizer
{
	private int $offset = 0;

	public function __construct(private readonly string $body)
	{
		// A byte order mark some editors write before the first group code.
		if (str_starts_with($body, "\xEF\xBB\xBF")) {
			$this->offset = 3;
		}
	}

	/** Reads past the end of the section, keeping nothing (a section the import does not read). */
	public function skip(): void
	{
		$pair = $this->next();
		while ($pair !== null && $pair !== [0, 'ENDSEC']) {
			$pair = $this->next();
		}
	}

	/** @return Pair|null */
	public function next(): ?array
	{
		$code = $this->line();
		if ($code === null) {
			return null;
		}
		$value = $this->line() ?? '';
		$code = trim($code);
		$digits = ltrim($code, '-');
		if ($digits === '' || !ctype_digit($digits)) {
			throw new DxfException('The file is not an ASCII DXF drawing.');
		}

		return [(int) $code, trim($value)];
	}

	/**
	 * The pairs up to the end of the section (a header's variables).
	 *
	 * @return list<Pair>
	 */
	public function pairs(): array
	{
		$pairs = [];
		$pair = $this->next();
		while ($pair !== null && $pair !== [0, 'ENDSEC']) {
			$pairs[] = $pair;
			$pair = $this->next();
		}

		return $pairs;
	}

	/**
	 * The records up to the end of the section. Records of other types than `$types` (null: all) keep only their layer,
	 * so a drawing's texts and dimensions take no memory.
	 *
	 * @param list<string>|null $types
	 * @return list<Entity>
	 */
	public function records(?array $types = null): array
	{
		$records = [];
		$pair = $this->next();
		while ($pair !== null && $pair !== [0, 'ENDSEC']) {
			$kept = $pair[0] === 0 && ($types === null || in_array($pair[1], $types, true));
			$pairs = [];
			$following = $this->next();
			while ($following !== null && $following[0] !== 0) {
				if ($kept || $following[0] === 8) {
					$pairs[] = $following;
				}
				$following = $this->next();
			}
			if ($pair[0] === 0) {
				$records[] = ['type' => $pair[1], 'pairs' => $pairs];
			}
			$pair = $following;
		}

		return $records;
	}

	private function line(): ?string
	{
		if ($this->offset >= strlen($this->body)) {
			return null;
		}
		$end = strpos($this->body, "\n", $this->offset);
		if ($end === false) {
			$line = substr($this->body, $this->offset);
			$this->offset = strlen($this->body);

			return $line;
		}
		$line = substr($this->body, $this->offset, $end - $this->offset);
		$this->offset = $end + 1;

		return $line;
	}
}

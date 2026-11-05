<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use Izbik\Service\Dxf\Read\DxfEntities;
use Izbik\Service\Dxf\Read\DxfGeometry;
use Izbik\Service\Dxf\Read\DxfPairs;
use Izbik\Service\Dxf\Read\DxfText;
use Izbik\Service\Dxf\Read\DxfTokenizer;
use function is_numeric;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtoupper;

/**
 * Reads an ASCII DXF (R12 to current) into a `DxfDrawing`: the model space's lines, polylines (bulges as arcs), arcs,
 * circles and hatch boundaries, with block inserts expanded and object coordinate systems applied (`DxfEntities`).
 * Binary DXF and DWG are refused.
 *
 * @phpstan-import-type Pair from DxfTokenizer
 * @phpstan-import-type Entity from DxfTokenizer
 * @phpstan-import-type Block from DxfEntities
 */
final class DxfReader
{
	public const int MaxBytes = 30 * 1024 * 1024;

	private ?int $units = null;

	private readonly DxfText $text;

	private readonly DxfGeometry $geometry;

	/** @var array<string, Block> by upper-case name */
	private array $blocks = [];

	/** @var list<Entity>|null */
	private ?array $entities = null;

	private function __construct()
	{
		$this->text = new DxfText();
		$this->geometry = new DxfGeometry();
	}

	public static function read(string $body): DxfDrawing
	{
		if (strlen($body) > self::MaxBytes) {
			throw new DxfException(sprintf('The DXF file is larger than %d MB.', self::MaxBytes / 1024 / 1024));
		}
		if (str_starts_with($body, 'AutoCAD Binary DXF')) {
			throw new DxfException('This is a binary DXF. Save the drawing as an ASCII DXF and try again.');
		}
		if (preg_match('/^AC10\d\d/', $body) === 1) {
			throw new DxfException('This is a DWG drawing. Save it as an ASCII DXF and try again.');
		}

		return new self()->parse(new DxfTokenizer($body));
	}

	private function parse(DxfTokenizer $tokens): DxfDrawing
	{
		$pair = $tokens->next();
		while ($pair !== null && $pair !== [0, 'EOF']) {
			if ($pair === [0, 'SECTION']) {
				$this->section($tokens, $tokens->next()[1] ?? '');
			}
			$pair = $tokens->next();
		}
		if ($this->entities === null) {
			throw new DxfException('The file is not a DXF drawing: it has no ENTITIES section.');
		}

		$entities = new DxfEntities($this->geometry, $this->text, $this->blocks);
		foreach ($this->entities as $entity) {
			// Code 67 set: paper space.
			if (DxfPairs::value($entity['pairs'], 67) !== '1') {
				$entities->emit($entity, new DxfTransform(), null, null, 0);
			}
		}

		return new DxfDrawing($this->units, $this->geometry->layers, $this->geometry->segments, $this->geometry->arcs);
	}

	private function section(DxfTokenizer $tokens, string $name): void
	{
		match ($name) {
			'HEADER' => $this->header($tokens->pairs()),
			'TABLES' => $this->tables($tokens->records()),
			'BLOCKS' => $this->blocks($tokens->records(DxfEntities::Read)),
			'ENTITIES' => $this->entities = DxfEntities::grouped($tokens->records(DxfEntities::Read)),
			default => $tokens->skip(),
		};
	}

	/** @param list<Pair> $pairs */
	private function header(array $pairs): void
	{
		$variable = null;
		foreach ($pairs as [$code, $value]) {
			if ($code === 9) {
				$variable = $value;
			} elseif ($variable === '$INSUNITS' && $code === 70 && is_numeric($value)) {
				$this->units = (int) $value;
			} elseif ($variable === '$DWGCODEPAGE' && $code === 3) {
				$this->text->useCodepage($value);
			}
		}
	}

	/** @param list<Entity> $records */
	private function tables(array $records): void
	{
		foreach ($records as $record) {
			$name = $this->text->decode(DxfPairs::value($record['pairs'], 2) ?? '');
			if ($record['type'] !== 'LAYER' || $name === '') {
				continue;
			}
			$this->geometry->layer($name)->hidden = (DxfPairs::int($record['pairs'], 70) & 1) !== 0
				|| DxfPairs::int($record['pairs'], 62, 7) < 0;
		}
	}

	/** @param list<Entity> $records */
	private function blocks(array $records): void
	{
		$name = null;
		$base = [0.0, 0.0];
		$entities = [];
		foreach ($records as $record) {
			if ($record['type'] === 'BLOCK') {
				$name = strtoupper($this->text->decode(DxfPairs::value($record['pairs'], 2) ?? ''));
				$base = [DxfPairs::number($record['pairs'], 10), DxfPairs::number($record['pairs'], 20)];
				$entities = [];
			} elseif ($record['type'] === 'ENDBLK' && $name !== null) {
				$this->blocks[$name] = ['x' => $base[0], 'y' => $base[1], 'entities' => DxfEntities::grouped($entities)];
				$name = null;
			} elseif ($name !== null) {
				$entities[] = $record;
			}
		}
	}
}

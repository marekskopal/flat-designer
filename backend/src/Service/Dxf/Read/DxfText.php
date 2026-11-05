<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Read;

use function hexdec;
use function iconv;
use function mb_check_encoding;
use function mb_chr;
use function mb_convert_encoding;
use function preg_replace_callback;
use function strtoupper;

/** Names in a drawing as UTF-8: `\U+XXXX` escapes decoded, other bytes read in the drawing's codepage. */
final class DxfText
{
	/** The iconv names of the codepages `$DWGCODEPAGE` names. */
	private const array Codepages = [
		'ANSI_874' => 'CP874',
		'ANSI_932' => 'CP932',
		'ANSI_936' => 'CP936',
		'ANSI_949' => 'CP949',
		'ANSI_950' => 'CP950',
		'ANSI_1250' => 'CP1250',
		'ANSI_1251' => 'CP1251',
		'ANSI_1252' => 'CP1252',
		'ANSI_1253' => 'CP1253',
		'ANSI_1254' => 'CP1254',
		'ANSI_1255' => 'CP1255',
		'ANSI_1256' => 'CP1256',
		'ANSI_1257' => 'CP1257',
		'ANSI_1258' => 'CP1258',
	];

	private string $codepage = 'CP1252';

	public function useCodepage(string $name): void
	{
		$this->codepage = self::Codepages[strtoupper($name)] ?? $this->codepage;
	}

	public function decode(string $value): string
	{
		if (!mb_check_encoding($value, 'UTF-8')) {
			$converted = iconv($this->codepage, 'UTF-8//IGNORE', $value);
			$value = $converted !== false ? $converted : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
		}

		return preg_replace_callback(
			'/\\\\U\+([0-9A-Fa-f]{4})/',
			static fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8'),
			$value,
		) ?? $value;
	}
}

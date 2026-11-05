<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

/** The drawing units of `$INSUNITS` that make sense for a floor plan, as millimetres per unit. */
final class DxfUnits
{
	public const int Unitless = 0;

	public const int Inches = 1;

	public const int Feet = 2;

	public const int Millimetres = 4;

	public const int Centimetres = 5;

	public const int Metres = 6;

	public const int Decimetres = 14;

	private const array Scales = [
		self::Inches => 25.4,
		self::Feet => 304.8,
		self::Millimetres => 1.0,
		self::Centimetres => 10.0,
		self::Metres => 1000.0,
		self::Decimetres => 100.0,
	];

	/** Whether the import takes these units (otherwise the user has to say which). */
	public static function isKnown(?int $units): bool
	{
		return $units !== null && isset(self::Scales[$units]);
	}

	/** Millimetres per drawing unit; a drawing without known units is taken to be in millimetres. */
	public static function scale(?int $units): float
	{
		return self::Scales[$units ?? self::Millimetres] ?? 1.0;
	}

	/** @return list<int> */
	public static function choices(): array
	{
		return [self::Millimetres, self::Centimetres, self::Decimetres, self::Metres, self::Inches, self::Feet];
	}
}

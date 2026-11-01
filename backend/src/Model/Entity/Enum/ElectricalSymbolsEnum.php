<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** The symbols a user's electrical plan overlay and PDF draw (FD-64). */
enum ElectricalSymbolsEnum: string
{
	/** IEC 60617, simplified. */
	case Iec = 'Iec';
	/** As Czech electrical plans draw them (ČSN EN 60617). */
	case Csn = 'Csn';
}

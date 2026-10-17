<?php

declare(strict_types=1);

namespace FlatDesigner\Route;

enum Routes: string
{
	case Health = '/api/health';

	case Flats = '/api/flats';
	case FlatScene = '/api/flats/{flatId:number}/scene';
}

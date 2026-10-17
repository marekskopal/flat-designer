<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Route\Routes;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\Router\Attribute\RouteGet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class HealthController
{
	public function __construct(private DatabaseInterface $database)
	{
	}

	#[RouteGet(Routes::Health->value)]
	public function actionHealth(ServerRequestInterface $request): ResponseInterface
	{
		try {
			$this->database->getPdo()->query('SELECT 1');
		} catch (Throwable) {
			return new JsonResponse(['status' => 'error', 'database' => 'unreachable'], 503);
		}

		return new JsonResponse(['status' => 'ok', 'database' => 'ok']);
	}
}

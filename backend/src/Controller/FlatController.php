<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Provider\SceneProvider;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class FlatController
{
	public function __construct(private SceneProvider $sceneProvider)
	{
	}

	#[RouteGet(Routes::Flats->value)]
	public function actionGetFlats(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse($this->sceneProvider->getFlats());
	}

	#[RouteGet(Routes::FlatScene->value)]
	public function actionGetScene(ServerRequestInterface $request, int $flatId): ResponseInterface
	{
		$scene = $this->sceneProvider->getScene($flatId);
		if ($scene === null) {
			return new NotFoundResponse('Flat not found.');
		}

		return new JsonResponse($scene);
	}
}

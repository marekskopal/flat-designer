<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Provider\SceneProvider;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The global material catalogue elements refer to by `materialId`. */
final readonly class MaterialController
{
	public function __construct(private SceneProvider $sceneProvider)
	{
	}

	#[RouteGet(Routes::Materials->value)]
	public function actionGetMaterials(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse($this->sceneProvider->getMaterials());
	}
}

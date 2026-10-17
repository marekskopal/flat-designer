<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\Catalog\ItemKindCatalog;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Item kinds with their default size and params, for the editor's "Add item" menu. */
final readonly class ItemKindController
{
	public function __construct(private ItemKindCatalog $catalog)
	{
	}

	#[RouteGet(Routes::ItemKinds->value)]
	public function actionGetItemKinds(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse($this->catalog->all());
	}
}

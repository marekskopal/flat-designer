<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Project\ShareLinkProvider;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Public (no sign-in) read of a share link: its settings and frozen floor scene. Unknown or expired: 404. */
final readonly class SharedViewController
{
	public function __construct(private ShareLinkProvider $shareLinkProvider)
	{
	}

	#[RouteGet(Routes::Share->value)]
	public function actionGetShare(ServerRequestInterface $request, string $token): ResponseInterface
	{
		$view = $this->shareLinkProvider->findSharedView($token);
		if ($view === null) {
			return new NotFoundResponse('This link does not exist or has expired.');
		}

		return new JsonResponse($view);
	}
}

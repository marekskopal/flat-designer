<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Route\Routes;
use Izbik\Service\Realtime\MercureCookieIssuer;
use Izbik\Service\Realtime\ProjectUpdatePublisher;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Starts live updates: sets the Mercure subscriber cookie and tells the client where to listen. The
 * client then opens an EventSource on `hubUrl?topic=<topicPrefix><projectId>` (with credentials).
 */
final readonly class RealtimeController
{
	public function __construct(private MercureCookieIssuer $cookieIssuer, private RequestService $requestService)
	{
	}

	#[RoutePost(Routes::RealtimeSubscription->value)]
	public function actionPostSubscription(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$secure = strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https' || $request->getUri()->getScheme() === 'https';

		return new JsonResponse(
			['hubUrl' => MercureCookieIssuer::CookiePath, 'topicPrefix' => ProjectUpdatePublisher::TopicPrefix],
			200,
			['Set-Cookie' => $this->cookieIssuer->issue($user, $secure)],
		);
	}
}

<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use DateTimeImmutable;
use FlatDesigner\Dto\McpClientDto;
use FlatDesigner\Model\Repository\OAuthAuthorizationRepository;
use FlatDesigner\OAuth\AuthorizationService;
use FlatDesigner\OAuth\ClientService;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use const DATE_ATOM;

/** The signed-in user's connected MCP clients, and disconnecting one. */
final readonly class McpClientController
{
	public function __construct(
		private OAuthAuthorizationRepository $authorizationRepository,
		private ClientService $clientService,
		private AuthorizationService $authorizationService,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::CurrentUserMcpClients->value)]
	public function actionGetClients(ServerRequestInterface $request): ResponseInterface
	{
		$now = time();
		/** @var array<string, array{first: DateTimeImmutable, last: DateTimeImmutable, active: bool}> $byClient */
		$byClient = [];
		foreach ($this->authorizationRepository->findByUser($this->requestService->getUser($request)->id) as $authorization) {
			$active = !$authorization->revoked && (
				($authorization->accessTokenExpires ?? 0) > $now || ($authorization->refreshTokenExpires ?? 0) > $now
			);
			$entry = $byClient[$authorization->clientId] ?? ['first' => $authorization->createdAt, 'last' => $authorization->createdAt, 'active' => false];
			$byClient[$authorization->clientId] = [
				'first' => min($entry['first'], $authorization->createdAt),
				'last' => max($entry['last'], $authorization->createdAt),
				'active' => $entry['active'] || $active,
			];
		}

		$clients = [];
		foreach ($byClient as $clientId => $entry) {
			$clients[] = new McpClientDto(
				clientId: $clientId,
				clientName: $this->clientService->findByClientId($clientId)->clientName ?? $clientId,
				connectedAt: $entry['first']->format(DATE_ATOM),
				lastTokenAt: $entry['last']->format(DATE_ATOM),
				active: $entry['active'],
			);
		}

		return new JsonResponse($clients);
	}

	#[RoutePost(Routes::CurrentUserMcpClientRevoke->value)]
	public function actionPostRevoke(ServerRequestInterface $request, string $clientId): ResponseInterface
	{
		$this->authorizationService->revokeClient($this->requestService->getUser($request), $clientId);

		return new OkResponse();
	}
}

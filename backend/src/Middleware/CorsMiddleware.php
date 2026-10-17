<?php

declare(strict_types=1);

namespace FlatDesigner\Middleware;

use FlatDesigner\Service\Cors\CorsPolicy;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CorsMiddleware implements MiddlewareInterface
{
	private const string AllowHeaders = 'Content-Type, Authorization, X-Origin-Client-Id';
	private const string AllowMethods = 'GET, POST, PUT, DELETE, OPTIONS';
	private const string MaxAge = '600';

	public function __construct(private CorsPolicy $policy)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$requestOrigin = $request->getHeader('Origin')[0] ?? null;
		$allowedOrigin = $this->policy->resolveAllowedOrigin($requestOrigin);

		$response = $request->getMethod() === 'OPTIONS'
			? new EmptyResponse(204)
			: $handler->handle($request);

		return $this->withCorsHeaders($response, $allowedOrigin);
	}

	private function withCorsHeaders(ResponseInterface $response, ?string $allowedOrigin): ResponseInterface
	{
		$response = $response
			->withHeader('Access-Control-Allow-Headers', self::AllowHeaders)
			->withHeader('Access-Control-Allow-Methods', self::AllowMethods)
			->withHeader('Access-Control-Max-Age', self::MaxAge);

		if ($allowedOrigin === null) {
			return $response;
		}

		$response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);

		if ($allowedOrigin !== CorsPolicy::AllowAnyOrigin) {
			$response = $response->withAddedHeader('Vary', 'Origin');
		}

		return $response;
	}
}

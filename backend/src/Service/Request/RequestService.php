<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Request;

use FlatDesigner\Dto\ArrayFactoryInterface;
use FlatDesigner\Middleware\AuthorizationMiddleware;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class RequestService
{
	/** JSON endpoints never need more; file uploads are multipart and bypass this parser. */
	public const int MaxJsonBodyBytes = 1048576;

	/** The signed-in user; only valid on routes behind `AuthorizationMiddleware`. */
	public function getUser(ServerRequestInterface $request): User
	{
		$user = $request->getAttribute(AuthorizationMiddleware::AttributeUser);
		assert($user instanceof User);

		return $user;
	}

	/**
	 * @param class-string<T> $dtoClass
	 * @return T
	 * @template T of ArrayFactoryInterface
	 */
	public function getRequestBodyDto(ServerRequestInterface $request, string $dtoClass): object
	{
		return $dtoClass::fromArray($this->getRequestBody($request));
	}

	/** @return array<string, mixed> */
	private function getRequestBody(ServerRequestInterface $request): array
	{
		$contents = $request->getBody()->getContents();
		if (strlen($contents) > self::MaxJsonBodyBytes) {
			throw new InvalidRequestException('Request body is too large.', 413);
		}

		try {
			return JsonDecoder::decodeObject($contents);
		} catch (RuntimeException $e) {
			throw new InvalidRequestException('Request body must be a JSON object.', 400, $e);
		}
	}
}

<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ModelAssetDto;
use FlatDesigner\Dto\ProjectFileDto;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\ModelAsset\ModelAssetException;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Upload\UploadTicketException;
use FlatDesigner\Service\Upload\UploadTicketService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The upload URLs MCP agents get from `request_file_upload` / `request_model_upload`: the raw body is the file
 * (`curl -T plan.png <url>`). The token authorizes the request, so it needs no access token; the body is read only
 * once the token is good, and only up to the upload limit, so a stray PUT costs no memory.
 */
final readonly class UploadController
{
	public function __construct(private UploadTicketService $ticketService)
	{
	}

	#[RoutePut(Routes::Upload->value)]
	public function actionPutUpload(ServerRequestInterface $request, string $token): ResponseInterface
	{
		try {
			$stored = $this->ticketService->redeem(
				$token,
				fn (): string => self::read($request->getBody(), $this->ticketService->maxBytes() + 1),
			);
		} catch (UploadTicketException | ProjectException | ModelAssetException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse(
			$stored instanceof ModelAsset ? ModelAssetDto::fromEntity($stored, true) : ProjectFileDto::fromEntity($stored),
			201,
		);
	}

	/** At most `$limit` bytes of the stream; one byte over the upload limit is enough for the provider to reject it. */
	private static function read(StreamInterface $stream, int $limit): string
	{
		$body = '';
		while (strlen($body) < $limit && !$stream->eof()) {
			$chunk = $stream->read(min(1 << 20, $limit - strlen($body)));
			if ($chunk === '') {
				break;
			}
			$body .= $chunk;
		}

		return $body;
	}
}

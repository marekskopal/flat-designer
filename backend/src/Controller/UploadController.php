<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\ModelAssetDto;
use Izbik\Dto\ProjectFileDto;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\ProjectFile;
use Izbik\Response\ErrorResponse;
use Izbik\Route\Routes;
use Izbik\Service\ModelAsset\ModelAssetException;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\Transfer\ProjectImportException;
use Izbik\Service\Upload\UploadTicketException;
use Izbik\Service\Upload\UploadTicketService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The upload URLs MCP agents get from `request_file_upload` / `request_model_upload` / `request_project_import`: the raw
 * body is the file (`curl -T plan.png <url>`). The token authorizes the request, so it needs no access token; the body is
 * read only once the token is good, and only up to the upload limit, so a stray PUT costs no memory.
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
			$stored = $this->ticketService->redeem($token, $request->getBody());
		} catch (UploadTicketException | ProjectException | ModelAssetException | ProjectImportException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse(match (true) {
			$stored instanceof ModelAsset => ModelAssetDto::fromEntity($stored, true),
			$stored instanceof ProjectFile => ProjectFileDto::fromEntity($stored),
			default => $stored,
		}, 201);
	}
}

<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ModelAssetDto;
use FlatDesigner\Dto\ModelAssetInputDto;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\ModelAsset\ModelAssetException;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Project\ShareLinkProvider;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\StreamFactory;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

/**
 * The 3D model library: list (`?projectId=` for what a project's items may use), upload (multipart `file` plus the
 * describing fields), change the describing fields, delete, and the file itself; guests of a share link may load the
 * models its scene draws.
 */
final readonly class ModelAssetController
{
	private const string NotFound = 'Model not found.';

	public function __construct(
		private ModelAssetProvider $modelProvider,
		private ModelAssetRepository $modelRepository,
		private ProjectProvider $projectProvider,
		private ShareLinkProvider $shareLinkProvider,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::Models->value)]
	public function actionGetModels(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$projectId = $request->getQueryParams()['projectId'] ?? null;
		$project = null;
		if ($projectId !== null) {
			$project = is_string($projectId) && ctype_digit($projectId)
				? $this->projectProvider->findAccessible($user, (int) $projectId)
				: null;
			if ($project === null) {
				return new NotFoundResponse(ProjectController::NotFound);
			}
		}

		return new JsonResponse(array_map(
			fn (ModelAsset $model): ModelAssetDto => $this->toDto($user, $model),
			$this->modelProvider->listVisible($user, $project),
		));
	}

	#[RoutePost(Routes::Models->value)]
	public function actionPostModel(ServerRequestInterface $request): ResponseInterface
	{
		$uploaded = $request->getUploadedFiles()['file'] ?? null;
		if (!$uploaded instanceof UploadedFileInterface) {
			return new ErrorResponse('Missing "file" multipart field.', 422);
		}
		if (in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			return new ErrorResponse('The file is too large.', 413);
		}
		if ($uploaded->getError() !== UPLOAD_ERR_OK) {
			return new ErrorResponse('Upload failed with code ' . $uploaded->getError() . '.', 422);
		}
		$body = $request->getParsedBody();
		$user = $this->requestService->getUser($request);

		try {
			$input = ModelAssetInputDto::fromArray(is_array($body) ? self::stringKeys($body) : []);
			$model = $this->modelProvider->upload(
				$user,
				$input,
				$uploaded->getClientFilename() ?? 'model.glb',
				$uploaded->getStream()->getContents(),
			);
		} catch (ModelAssetException | InvalidRequestException $e) {
			return new ErrorResponse($e->getMessage(), $e instanceof ModelAssetException ? $e->getCode() : 422);
		}

		return new JsonResponse($this->toDto($user, $model), 201);
	}

	#[RoutePut(Routes::Model->value)]
	public function actionPutModel(ServerRequestInterface $request, int $modelId): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$model = $this->modelProvider->findReadable($user, $modelId);
		if ($model === null) {
			return new NotFoundResponse(self::NotFound);
		}
		$input = $this->requestService->getRequestBodyDto($request, ModelAssetInputDto::class);

		try {
			return new JsonResponse($this->toDto($user, $this->modelProvider->update($user, $model, $input)));
		} catch (ModelAssetException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}

	#[RouteDelete(Routes::Model->value)]
	public function actionDeleteModel(ServerRequestInterface $request, int $modelId): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$model = $this->modelProvider->findReadable($user, $modelId);
		if ($model === null) {
			return new NotFoundResponse(self::NotFound);
		}

		try {
			$this->modelProvider->delete($user, $model);
		} catch (ModelAssetException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new OkResponse();
	}

	/** The file, also of a deleted model as long as the user may read it (items keep drawing deleted models). */
	#[RouteGet(Routes::ModelContent->value)]
	public function actionGetModelContent(ServerRequestInterface $request, int $modelId): ResponseInterface
	{
		$model = $this->modelRepository->findById($modelId);
		if ($model === null || !$this->modelProvider->canRead($this->requestService->getUser($request), $model)) {
			return new NotFoundResponse(self::NotFound);
		}

		return $this->content($model);
	}

	/** A model the share link's scene draws, for its guests (no sign-in). */
	#[RouteGet(Routes::ShareModelContent->value)]
	public function actionGetShareModelContent(ServerRequestInterface $request, string $token, int $modelId): ResponseInterface
	{
		$model = $this->shareLinkProvider->findSharedModel($token, $modelId);
		if ($model === null) {
			return new NotFoundResponse(self::NotFound);
		}

		return $this->content($model);
	}

	private function content(ModelAsset $model): ResponseInterface
	{
		return new Response(
			(new StreamFactory())->createStream($this->modelProvider->readContent($model)),
			200,
			[
				'Content-Type' => 'model/gltf-binary',
				'Content-Length' => (string) $model->size,
				'X-Content-Type-Options' => 'nosniff',
				// A model's file never changes (a new upload is a new model), so the browser may keep it.
				'Cache-Control' => 'private, max-age=86400, immutable',
			],
		);
	}

	private function toDto(User $user, ModelAsset $model): ModelAssetDto
	{
		return ModelAssetDto::fromEntity($model, $this->modelProvider->canWrite($user, $model));
	}

	/**
	 * @param array<mixed> $body
	 * @return array<string, mixed>
	 */
	private static function stringKeys(array $body): array
	{
		$out = [];
		foreach ($body as $key => $value) {
			$out[(string) $key] = $value;
		}

		return $out;
	}
}

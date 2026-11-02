<?php

declare(strict_types=1);

namespace Izbik\Mcp\Tool;

use Izbik\Mcp\McpUserContext;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Project\ProjectProvider;
use RuntimeException;

/** Resolves what a tool call acts on, with the same access rules as the app (owner or admin). */
final readonly class ToolContext
{
	public function __construct(
		private McpUserContext $userContext,
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
	) {
	}

	public function user(): User
	{
		return $this->userContext->getUser();
	}

	/** The app's base URL the request came through, for links the tools hand out (share links, upload URLs). */
	public function baseUrl(): string
	{
		$baseUrl = $this->userContext->getBaseUrl();

		return $baseUrl !== '' ? $baseUrl : throw new RuntimeException('The app\'s base URL is not known to the MCP server.');
	}

	/** The scope argument of the furniture definition and model tools. */
	public static function scope(string $scope): FurnitureScopeEnum
	{
		return FurnitureScopeEnum::tryFrom($scope)
			?? throw new RuntimeException('Scope must be "user", "project" or "global".');
	}

	public function project(int $projectId): Project
	{
		return $this->projectProvider->findAccessible($this->user(), $projectId)
			?? throw new RuntimeException(sprintf('Project %d not found. Use list_projects to see the available ones.', $projectId));
	}

	public function floor(int $projectId, int $floorId): Floor
	{
		return $this->floorRepository->findOneInProject($this->project($projectId)->id, $floorId)
			?? throw new RuntimeException(
				sprintf('Floor %d is not a floor of project %d. Use get_project to list its floors.', $floorId, $projectId),
			);
	}

	/**
	 * The fields a tool call sets: the given (non-null) arguments, plus the `clear` names set to null.
	 *
	 * @param array<string, mixed> $arguments
	 * @param list<string>|null $clear
	 * @param list<string> $clearable
	 * @return array<string, mixed>
	 */
	public static function fields(array $arguments, ?array $clear = null, array $clearable = []): array
	{
		$fields = array_filter($arguments, static fn (mixed $value): bool => $value !== null);
		foreach ($clear ?? [] as $name) {
			if (!in_array($name, $clearable, true)) {
				throw EditException::invalid(sprintf('"%s" cannot be cleared; clearable fields: %s.', $name, implode(', ', $clearable)));
			}
			$fields[$name] = null;
		}

		return $fields;
	}
}

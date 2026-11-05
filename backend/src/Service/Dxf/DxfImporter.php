<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use Izbik\Dto\DxfImportInputDto;
use Izbik\Dto\DxfImportResultDto;
use Izbik\Dto\DxfSummaryDto;
use Izbik\Dto\EditResultDto;
use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Model\Entity\Enum\OpeningTypeEnum;
use Izbik\Model\Entity\Enum\WallTypeEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\ProjectFile;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\WallRepository;
use Izbik\Service\Editor\BatchWriter;
use Izbik\Service\Editor\Element\ElementHandler;
use Izbik\Service\Editor\ElementHandlers;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Project\ProjectFileProvider;
use function array_filter;
use function count;
use function sprintf;

/**
 * Imports the walls and openings of a project's DXF into a floor as one change (one revision, one undo step): every
 * wall is checked before anything is written, so a wall the editor would refuse stops the import; an opening it
 * refuses (too near a wall's end, too tall for it…) is left out with a warning. The walls are interior walls; with
 * `replaceExisting` the floor's walls (and their openings) go in the same change.
 *
 * @phpstan-import-type PlanOpening from DxfPlan
 */
final readonly class DxfImporter
{
	private ElementHandler $walls;

	private ElementHandler $openings;

	public function __construct(
		ElementHandlers $handlers,
		private BatchWriter $writer,
		private ProjectFileProvider $files,
		private WallRepository $wallRepository,
		private DxfPlanner $planner,
	) {
		$this->walls = $handlers->get(ElementTypeEnum::Wall);
		$this->openings = $handlers->get(ElementTypeEnum::Opening);
	}

	public function summary(Project $project, int $fileId): DxfSummaryDto
	{
		return DxfSummaryDto::fromDrawing($this->drawing($project, $fileId));
	}

	public function preview(Project $project, DxfImportInputDto $input): DxfPlan
	{
		return $this->planner->plan($this->drawing($project, $input->fileId), $input->options);
	}

	public function import(User $user, Floor $floor, DxfImportInputDto $input, ?int $baseRevision = null): DxfImportResultDto
	{
		$file = $this->file($floor->project, $input->fileId);
		$plan = $this->planner->plan($this->read($file), $input->options);
		if ($plan->walls === []) {
			throw EditException::invalid('No walls were found on the chosen layers, so nothing was imported.');
		}

		$log = new DxfImportLog($plan->warnings);
		$result = $this->writer->transaction(function () use ($user, $floor, $file, $plan, $input, $baseRevision, $log): EditResultDto {
			// Every wall is validated before the revision is bumped, so a wall the editor refuses changes nothing.
			$states = $this->wallStates($floor, $plan, $input);
			$batch = $this->writer->start(
				$floor,
				$baseRevision,
				// Openings the editor refuses are left out later, so the summary does not count them.
				sprintf('Imported %s: %d wall(s) and their openings', $file->filename, count($states)),
			);
			if ($input->replaceExisting) {
				foreach ($this->wallRepository->findByFloor($floor->id) as $existing) {
					$this->writer->delete($user, $floor, $batch, $this->walls, $existing);
				}
			}

			$wallIds = [];
			$last = null;
			foreach ($states as $state) {
				$wall = $this->writer->create($user, $floor, $batch, $this->walls, $state);
				$wallIds[] = $wall->id;
				$last = $this->walls->state($wall);
			}
			foreach ($plan->openings as $opening) {
				$state = $this->openingState($floor, $opening, $wallIds[$opening['wall']], $input, $log);
				if ($state === null) {
					continue;
				}

				$last = $this->openings->state($this->writer->create($user, $floor, $batch, $this->openings, $state));
				$log->openings++;
			}

			return $batch->result($last);
		});

		return new DxfImportResultDto($result, count($plan->walls), $log->openings, $log->warnings);
	}

	/** @return list<array<string, mixed>> the walls' normalised states */
	private function wallStates(Floor $floor, DxfPlan $plan, DxfImportInputDto $input): array
	{
		$states = [];
		foreach ($plan->walls as $index => $wall) {
			$fields = $wall + array_filter([
				'type' => WallTypeEnum::Interior->value,
				'height' => $input->wallHeight,
				'materialId' => $input->materialId,
			], static fn (mixed $value): bool => $value !== null);

			try {
				$states[] = $this->walls->normalize($floor, $fields + $this->walls->defaults($floor, $fields), null);
			} catch (EditException $e) {
				throw new EditException(sprintf('Wall %d of the drawing: %s', $index + 1, $e->getMessage()), 422, $e);
			}
		}

		return $states;
	}

	/**
	 * The opening's normalised state; null, with a warning, when the editor refuses it.
	 *
	 * @param PlanOpening $opening
	 * @return array<string, mixed>|null
	 */
	private function openingState(Floor $floor, array $opening, int $wallId, DxfImportInputDto $input, DxfImportLog $log): ?array
	{
		$type = OpeningTypeEnum::from($opening['type']);
		$fields = [
			'wallId' => $wallId,
			'type' => $type->value,
			'offset' => $opening['offset'],
			'width' => $opening['width'],
			'leafWidth' => $opening['leafWidth'],
		] + array_filter([
			'hinge' => $opening['hinge'],
			'side' => $opening['side'],
			'sillHeight' => $type === OpeningTypeEnum::Window ? $input->windowSill : null,
			'height' => $type === OpeningTypeEnum::Window ? $input->windowHeight : $input->doorHeight,
		], static fn (string|int|null $value): bool => $value !== null);

		try {
			return $this->openings->normalize($floor, $fields + $this->openings->defaults($floor, $fields), null);
		} catch (EditException $e) {
			$log->warnings[] = sprintf(
				'A %s %d mm wide in wall %d was left out: %s',
				$type->label(),
				$opening['width'],
				$opening['wall'] + 1,
				$e->getMessage(),
			);

			return null;
		}
	}

	private function drawing(Project $project, int $fileId): DxfDrawing
	{
		return $this->read($this->file($project, $fileId));
	}

	private function read(ProjectFile $file): DxfDrawing
	{
		if ($file->size > DxfReader::MaxBytes) {
			throw new DxfException(sprintf('The DXF file is larger than %d MB.', DxfReader::MaxBytes / 1024 / 1024));
		}

		return DxfReader::read($this->files->readContent($file));
	}

	private function file(Project $project, int $fileId): ProjectFile
	{
		return $this->files->find($project, $fileId) ?? throw EditException::notFound(
			sprintf('File %d is not a file of this project.', $fileId),
		);
	}
}

<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\ProjectRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A user's design of one dwelling: 1..N floors (the editable working copy) plus saved versions.
 * `owner` is null only for projects seeded by migrations until `admin:create` claims them.
 */
#[Entity(repositoryClass: ProjectRepository::class)]
class Project extends AEntity
{
	/** Bumped by every change of the floors; clients send it back to detect concurrent edits. */
	#[Column(type: Type::Int, default: 0)]
	public int $revision = 0;

	/** ISO 4217 code of the prices in the quantities and cost estimate (`ProjectMaterialPrice`). */
	#[Column(type: Type::String, size: 3, default: 'EUR')]
	public string $currency = 'EUR';

	/** Allowance for cuts and breakage added to tiles and planks counted in pieces, %. */
	#[Column(type: Type::Int, default: 10)]
	public int $wastePercent = 10;

	/** Degrees clockwise from plan north (+y) to true north, 0–359: the viewer's sun and the drawings' north arrows. */
	#[Column(type: Type::Int, default: 0)]
	public int $northAngle = 0;

	/** Where the building stands, degrees north; set together with `longitude`, for the sun. */
	#[Column(type: Type::Double, nullable: true)]
	public ?float $latitude = null;

	/** Degrees east. */
	#[Column(type: Type::Double, nullable: true)]
	public ?float $longitude = null;

	/** IANA name (e.g. Europe/Prague) the sun's clock time is read in; null: local solar time. */
	#[Column(type: Type::String, size: 64, nullable: true)]
	public ?string $timeZone = null;

	/** The façade of the exterior walls' outer faces nobody set a material or bands for (FD-88); null: the walls' own. */
	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $exteriorMaterial = null;

	public function __construct(
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $owner,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::String, nullable: true)]
		public ?string $address,
		#[Column(type: Type::Text, nullable: true)]
		public ?string $description,
	) {
	}
}

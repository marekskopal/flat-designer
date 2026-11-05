<?php

declare(strict_types=1);

namespace Izbik\Service\Example;

use Izbik\Model\Entity\Enum\LocaleEnum;

/** An example project shipped with the app (`backend/examples/<slug>.json`, see `ExampleCatalogue`). */
final readonly class Example
{
	public function __construct(
		public string $slug,
		public string $name,
		public string $description,
		/** Where it comes from, e.g. the building it is modelled after. */
		public string $credit,
		/** The saved view a new project from it opens at; null: the first floor's overview. */
		public ?string $startView,
		/** A project snapshot (`ProjectSnapshotService`), taken on another instance. */
		public string $snapshot,
		/** @var array<string, array<string, string>> its name, description and credit in other languages (`{cs: {name, …}}`) */
		public array $translations = [],
	) {
	}

	/** The example as a user of that language sees it: its texts translated where it has them. */
	public function in(LocaleEnum $locale): self
	{
		$texts = $this->translations[$locale->value] ?? [];

		return new self(
			slug: $this->slug,
			name: $texts['name'] ?? $this->name,
			description: $texts['description'] ?? $this->description,
			credit: $texts['credit'] ?? $this->credit,
			startView: $this->startView,
			snapshot: $this->snapshot,
			translations: $this->translations,
		);
	}
}

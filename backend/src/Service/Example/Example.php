<?php

declare(strict_types=1);

namespace Izbik\Service\Example;

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
	) {
	}
}

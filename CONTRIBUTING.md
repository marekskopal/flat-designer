# Contributing to Izbik

Thanks for considering a contribution. Izbik is MIT-licensed and built in the open: bug reports, fixes, docs,
furniture definitions and features are all welcome.

## Ground rules

- **Open an issue first** for anything bigger than a fix (a feature, a change of behaviour, a new dependency). A few
  sentences are enough to agree on the approach before you spend time on code.
- **Keep pull requests focused**: one topic each. Send unrelated cleanups separately.
- **Never commit secrets or third-party assets.** `.env` is ignored; add new settings to `.env.example` with a
  comment. Product models, textures without a licence and reference drawings stay out of the repository (see
  *Product models* in [CLAUDE.md](CLAUDE.md)).

## Setting up

You need Docker with Compose, `make` and `openssl`, and for lint, tests and the dev server PHP 8.5 with Composer,
Node.js 24 and pnpm.

```sh
git clone https://github.com/marekskopal/izbik.git
cd izbik
make install   # Composer and pnpm dependencies on the host
make up        # build and start the stack on http://localhost:4400 (creates .env)
make admin     # the first admin account
make dev       # optional: ng serve on http://localhost:4200 against the running stack
```

To try sign-up and the smoke test locally, set `SIGN_UP_ENABLED=1` in `.env` and run `make up` again.

## Checks

CI runs them on every pull request. Run the same before pushing:

```sh
make lint    # phpcs + phpstan (max level), eslint
make test    # PHPUnit (in a one-off backend container, database izbik_test), then Vitest
make smoke   # the browser smoke test against the running stack (needs sign-up open)
```

`make smoke` (`frontend/scripts/smoke.mjs`) signs up a new user, creates a project, draws four walls, makes the room
from them, puts in a door, shares the floor, opens the link as a guest and starts a project from the example. A
failure leaves a screenshot in `frontend/smoke-results/`. `FD_HEADED=1` shows the browser; `FD_SOFTWARE_GL=1` draws
WebGL without a GPU, as CI does.

| Layer | Tool | Bar |
| --- | --- | --- |
| Backend | PHPStan | max level with the strict, deprecation, PHPUnit and shipmonk rules; no `@phpstan-ignore`, no baseline |
| Backend | PHPCS | the slevomat ruleset, tabs (`vendor/bin/phpcbf` fixes most) |
| Frontend | ESLint | explicit accessibility and return types, sorted imports, lines up to 150 |
| Tests | PHPUnit, Vitest | new behaviour comes with tests: the expected case and the likeliest failure |

## How the code is organised

[CLAUDE.md](CLAUDE.md) is the map of the codebase: services, domain conventions (millimetres, the plan's axes), the
editor's rules and every subsystem. Read the parts you touch. Rules that catch most first contributions:

- **Twins**: some logic exists in PHP and in TypeScript (room detection, wall faces, the furniture expression language
  and evaluator, roof geometry, terrain, void areas, gutters, electrical units). Change both and add a case to the
  shared fixture in `backend/tests/fixtures/` in the same change.
- **New element fields** go into the element's handler (`fields()`, `normalize()`, `apply()`) and into the snapshot
  restore, or versions and undo lose them.
- **Engine code** in `frontend/src/app/viewer/engine/` stays framework-free (no Angular imports). Code that changes what
  is drawn outside the existing paths calls `invalidate()`, and code moving anything that casts a shadow calls
  `refreshShadows()`.
- **Data changes** go into a new migration; never edit an applied one.
- No ticket numbers in code, comments or test names; they belong in commit messages.

## Commits and pull requests

- Commit subjects are one imperative line of up to 72 characters (`Add a gate to fences`), the body says why. Reference
  the issue (`Fixes #42`).
- Branch from `main`, open the pull request against `main` and fill in the
  [template](.github/PULL_REQUEST_TEMPLATE.md). A green CI comes before review.
- Add a line to the *Unreleased* section of [CHANGELOG.md](CHANGELOG.md) for a change users or operators notice.
- Push further commits for review comments rather than force-pushing over them.

## Reporting bugs and security problems

- **Bugs**: open an issue with the *Report a problem* form (the app's header links to it): steps, expected and actual
  behaviour, version, browser, logs.
- **Security**: never as a public issue. Follow [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contributions are licensed under the project's [MIT License](LICENSE).

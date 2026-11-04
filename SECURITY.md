# Security policy

## Supported versions

Izbik is in beta. Security fixes go into the latest release only, so upgrade before reporting (see
[docs/self-hosting.md](docs/self-hosting.md#upgrading)).

| Version | Supported |
| --- | --- |
| The latest release | Yes |
| Older releases | No |

## Reporting a vulnerability

**Do not open a public GitHub issue for a security problem.**

Open a [private security advisory](https://github.com/marekskopal/izbik/security/advisories/new) on GitHub instead,
with:

- what the vulnerability is and what an attacker gains by it,
- the steps or a proof of concept to reproduce it,
- the version or commit affected, and how it runs (published images, built from source),
- a fix you would suggest, if you have one.

You will hear back within a few days. A fix and a disclosure date are agreed with you, and reporters who wish to be
named are credited in the release notes.

## Scope

This policy covers what is in this repository: the backend API, MCP server and live updates (`backend/`), the
frontend (`frontend/`), the proxy configuration (`proxy/`), the compose files and the Makefile. Of particular
interest:

- access to another user's projects, files, models or furniture definitions, or to a project through a share link
  beyond what the link allows;
- authentication and accounts: tokens, password reset, email verification, Sign in with Google, the OAuth flow of MCP
  clients, sign-up on a server where it is closed;
- uploads (project files, models, the one-time upload URLs) and anything a guest's comment can make the app or an
  agent do.

Report a problem in a dependency to its project; tell us as well if it affects Izbik. The configuration of a server
(its `.env`, TLS, firewall) is its operator's; [docs/self-hosting.md](docs/self-hosting.md) describes the settings to
check.

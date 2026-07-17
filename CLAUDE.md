# Agent quick-reference

This file is an optional convenience for AI coding agents — **nothing in this
repository depends on it**, and deleting it loses nothing. The authoritative
contracts live in the neutral docs:

- **Downstream base contract** (src/ is read-only in project copies; check
  version + CHANGELOG before reporting bugs; missing Laravel features are
  scope boundaries): [docs/conventions.md](docs/conventions.md), "Downstream
  projects: the base contract".
- **Rules for changing the framework itself** (docs move with code,
  `composer test`, JS copies byte-identical, version bump + CHANGELOG):
  [docs/conventions.md](docs/conventions.md), "Framework core changes".
- **Full specification**, verified against source: [docs/](docs/README.md).

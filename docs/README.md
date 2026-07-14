# KallioMicro Documentation

Official specification of the KallioMicro framework — a minimal, secure PHP 8.1+ MVC framework by Mesvac Oy, designed as a reusable base for downstream projects.

Every document is **verified against the source in `src/`**; when a doc and the code disagree, one of them gets fixed — never silent drift. Two kinds of annotation are used deliberately: **scope notes** mark intentional baseline boundaries (the base ships mechanisms; deployments own policies), while **⚠ markers with an "as of" date** mark genuine defects or sharp edges awaiting a fix. Descriptions are technical by intent: signatures, contracts, and behavior over narrative.

| Document | Covers |
|---|---|
| [overview.md](overview.md) | Design philosophy, module map + dependency rules, DI container, configuration/env, entry points, request/CLI lifecycles, error handling, support helpers |
| [routing-and-middleware.md](routing-and-middleware.md) | Router (groups, resources, params, named routes, 404/405), middleware, Request/Response, Controller base class, HttpException |
| [database.md](database.md) | Connection (PDO config, transactions), QueryBuilder full API + identifier validation, write guards, RawExpression |
| [validation.md](validation.md) | `Controller::validate()` — rule reference, return shape, numeric-vs-length semantics |
| [auth.md](auth.md) | AuthManager, the four providers (Local/Entra/Google/LDAP), Session, CSRF, roles/profiles |
| [api-response.md](api-response.md) | The ApiResponse action system + the kalliomicro.js client contract (data-action triggers, full action reference) |
| [views.md](views.md) | ViewEngine (layouts/sections/escaping), layout contract, styling, shipped views |
| [console.md](console.md) | Console kernel, commands, argument parsing, scheduler |
| [conventions.md](conventions.md) | Complexity budget, naming, controller/security conventions, extension guidance for downstream projects |

## Scope note

This repository is the **base framework**. Larger derived deployments add modules on top (background job queues, RBAC permission services, API token auth, file storage, integration layers, build pipelines); those are documented in their own repositories. These docs describe exactly what ships here — absences are stated explicitly so downstream projects know what they own.

# Conventions

Rules for code in this repository and guidance for projects built on it.

## Complexity budget

Standing directive. Before adding any abstraction, layer, flag, catch-block, or config surface:

1. **Name the concrete failure or requirement it serves today.** "Might be useful" is not a requirement; generalize for reuse only when a second consumer exists.
2. **Prefer net-simpler fixes** — the best fix often removes code.
3. **Name governance costs explicitly** when structure is added to honor module boundaries or security posture.
4. **Cut test before commit:** would the system lose anything measurable if this were cut? If honestly no, cut it.

The base framework itself is the product of this rule — it ships no queue, no RBAC, no migrations, no build pipeline, because the base has no consumer for them. Downstream projects add modules when a real requirement arrives.

## PHP standards

- Every file starts with `declare(strict_types=1);` immediately after `<?php`.
- PHP 8.1+ idioms: typed properties, readonly, `match`, named arguments, constructor promotion, first-class enums.
- PSR-4: `KallioMicro\` → `src/`, `App\` → `app/`. Framework code never imports `App\*`.

## Naming

| Element | Convention | Example |
|---|---|---|
| Classes / files | PascalCase | `AssessmentController.php` |
| Methods / properties | camelCase | `handleCallback()`, `$basePath` |
| DB tables / columns | snake_case | `core_users`, `user_id` |
| Routes | kebab-case | `/app/assessments` |
| Config keys | dot.notation | `app.debug` |
| Views | dot.notation | `assessments.form` |
| Console commands | `namespace:verb-phrase`, keep short | `task:backup` |

Interfaces and consumer-facing names describe the **role**, not a concrete external system; system names belong in implementation classes only (`ErpProvider` interface, `VismaErpProvider` implementation). This keeps external systems swappable by rebinding.

## Controllers

- Signature: `public function action(Request $request, string $id): Response` — `$request` first, route params after, always returning `Response`.
- Mutation method skeleton, in order: `requireCsrf()` → `validate()` → authorize (ownership/role, strict `!==` comparisons) → write → `ApiResponse` chain → `toResponse()`.
- Thin controllers: extract logic shared between web and console (or spanning multiple tables in one transaction) into service classes registered in the container.
- Named verbs over flag parameters: two methods (`archive()` / `reject()`), not one method with a mode flag.

## Framework core changes

Changes to `src/` affect every downstream project:

- Must not break existing controller patterns or route definitions.
- Must preserve the QueryBuilder's parameter-binding and identifier-validation behavior.
- Update the matching document in `docs/` in the same change — docs are verified against code, and a ⚠ marker with an "as of" date is the required way to document a known defect that isn't fixed yet.

## Security checklist (pre-commit)

1. `declare(strict_types=1);` in every new PHP file.
2. CSRF on every state-changing endpoint (`requireCsrf()` and/or route-level `CsrfMiddleware`).
3. Auth middleware on protected route groups (`/app`, `/api` except health).
4. Ownership or role check before update/delete — strict comparison.
5. QueryBuilder with bindings; `whereRaw()` only with `$bindings`; no SQL interpolation, ever.
6. `$view->e()` / `e()` on all dynamic template output.
7. No sensitive fields in responses (`password`, `*_hash`, `*_token`, `secret`) — whitelist with `->select([...])`.
8. `ApiResponse` chains end `->toResponse()`.
9. Validate before any database write.
10. No `env()` calls outside `config/*.php`.

## Extension guidance for downstream projects

- **Services:** register in `public/index.php` next to the AuthManager binding. Once the CLI needs the same services, extract the bindings into a shared file both entry points `require` — do not duplicate them.
- **Middleware:** implement `MiddlewareInterface`; wire as closures on routes (`Route::middleware()` takes closures).
- **Auth providers:** implement `AuthProviderInterface` (or `OAuthProviderInterface`) and `registerProvider()` on the AuthManager.
- **Client actions:** new `ApiResponse` action types need a matching `case` in `kalliomicro.js` (`executeAction`) *in the same change* — a builder without a client handler is silently dropped.
- **Mechanism vs. policy:** the base deliberately ships mechanisms without policies — impersonation without an authorization gate, a scheduler without overlap locking, login without throttling, sessions without a shared store, OAuth without account-provisioning rules. These are scope boundaries, not defects: each policy belongs to the deployment that knows its requirements. Decide each consciously before production. The contracts are stated twice on purpose: as scope notes in [auth.md](auth.md) / [console.md](console.md), and as docblocks at the call sites (`Session::impersonate()`, `AuthManager::attemptWith()` / `handleOAuthCallback()`, `Console::schedule()`), so nobody has to leave the editor to learn what they own.

# Views & Templates

> Sources: `src/View/ViewEngine.php`, `resources/views/`, `public/assets/`.
> `src/View/` is standalone. Templates are **native PHP** — no template language, no build step.

---

## ViewEngine

Constructed by the Application as a singleton (alias `'view'`) with `resources/views` as the view path. Template names use dot notation: `assessments.form` → `resources/views/assessments/form.php`. Extensions tried: `.php`, `.html.php`, `.twig` — ⚠ the `.twig` entry is vestigial (files are `include`d as PHP; there is no Twig engine), as is the constructor's `$cachePath` (no template caching exists).

### Render flow and layout inheritance

```php
// page template
<?php $view->extends('layouts.app'); ?>
<?php $view->section('content'); ?>
    …page body…
<?php $view->endSection(); ?>
```

`render()` merges shared data under the call data (call data wins), runs registered composers, renders the template, and — if the template called `extends()` — renders the layout with the child's output injected as **`$content`**. The shipped layout emits the body via `<?= $content ?>` and uses `yield()` only for the optional `styles`/`scripts` blocks:

```php
<main class="container py-4"><?= $content ?></main>
…
<?= $view->yield('scripts') ?>
```

Other mechanics: `partial($template, $data)` / `include()` render without layout handling; `component($name, $data, $slot)` renders `components/{name}` with `$slot` available (⚠ no `components/` directory ships in the base — the mechanism exists for downstream component libraries); `exists($template)` checks resolvability; `composer($pattern, $callback)` registers pre-render data callbacks (exact or `*` wildcard match).

### Escaping — the XSS rules

```php
$view->e($value)     // htmlspecialchars, ENT_QUOTES | ENT_HTML5, UTF-8; null → ''
$view->attr($value)  // same flags (attribute contexts)
$view->js($value)    // json_encode with HEX_TAG|HEX_APOS|HEX_QUOT|HEX_AMP — inline JS values
```

**Every `<?=` emitting dynamic data uses `$view->e()`** (or the global `e()` helper). The only unescaped emissions allowed are developer-authored HTML fragments.

### Shared data

`share($key, $value)` / `shareMany()` put data into every subsequent render **and** into the helpers that read the shared bag. `Controller::prepareViewData()` injects `csrf_token`, `user`, `flash` into the render data *and shares* `csrf_token` + `user` into the engine — this is what makes the following work inside templates:

- `$view->csrf()` — hidden `csrf_token` input from the shared token
- `$view->isAuth()` — `!empty($shared['user'])`
- `$view->hasRole($roles)` — intersects with `$shared['user']['roles']`

(`flash` is injected as data only.) If you render through the engine directly (not via a controller), `share()` these yourself.

### Translations — `t()` is a stub

`t(string $key, array $params = []): string` currently returns the **key itself** with `:name` placeholders substituted — there is no lookup, no language files, no locale handling (`app.locale` is ignored). The placeholder syntax (`:name`) is the contract downstream translation implementations must keep. Write user-facing text through `t()` anyway if your project will add translations later; otherwise plain strings are fine at this scale.

### Formatting and attribute helpers

```php
$view->date($value, 'd.m.Y')          // strtotime/DateTimeInterface aware; null/'' → ''
$view->datetime($value, 'd.m.Y H:i')
$view->number($value, 2, ',', ' ')    // Finnish defaults: comma decimal, space thousands
$view->classIf($cond, 'active')       // conditional class
$view->attrIf($cond, 'disabled')      // conditional attribute (optionally valued)
$view->selected($value, $expected)    // 'selected' for <option>
$view->checked($value, $expected)     // 'checked'; array $expected = in_array
$view->method('PUT')                  // _method spoofing hidden field (POST forms)
```

---

## Layout contract (`resources/views/layouts/app.php`)

The shipped layout provides everything the client contract needs:

- `<meta name="csrf-token" content="…">` — token source for kalliomicro.js
- **Bootstrap 5.3 via CDN** (CSS + JS bundle) — the only styling dependency; no Tailwind, no npm, no compiled assets
- An inline `<style>` block: flash container (fixed top-right), `.is-loading`, and modal z-index stacking for levels 1–3
- `#flash-messages` and `#modal-container`
- A Bootstrap navbar gated on `$view->isAuth()`
- `<script src="/assets/js/kalliomicro.js">` — the client (served from `public/assets/js/`)
- `$view->yield('styles')` / `$view->yield('scripts')` extension points

## Shipped views

`layouts/app.php`, `home.php`, `dashboard.php`, `auth/login.php`, `assessments/index.php`, `assessments/form.php` — the assessments pair demonstrates the full data-action + ApiResponse modal CRUD flow (see [api-response.md](api-response.md)).

⚠ Known demo gap (as of 2026-07-14): `AssessmentController` also references `assessments.create`, `assessments.edit`, `assessments.show`, and `assessments.partials.table-rows`, which do not exist — those controller paths throw `View not found` until the views are added.

## Static assets

No build pipeline: files under `public/assets/` are served as `/assets/*` verbatim; the `asset($path)` helper builds `{app.url}/assets/{path}`. When you edit `resources/assets/js/kalliomicro.js`, copy it to `public/assets/js/` — the two must stay identical.

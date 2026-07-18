# Views & Templates

> Sources: `src/View/ViewEngine.php`, `resources/views/`, `public/assets/`.
> `src/View/` is standalone. Templates are **native PHP** — no template language, no build step.

---

## ViewEngine

Constructed by the Application as a singleton (alias `'view'`) with `resources/views` as the view path. Template names use dot notation: `assessments.form` → `resources/views/assessments/form.php`. Extensions tried: `.php`, `.html.php`. (The constructor's `$cachePath` parameter is currently unused — templates are plain PHP includes, nothing is compiled or cached.)

### Render flow and layout inheritance

```php
// page template
<?php $view->extends('layouts.app'); ?>
<?php $view->section('content'); ?>
    …page body…
<?php $view->endSection(); ?>
```

`render()` starts each page with a **clean sections slate** (a previous render's captured sections never leak into the next), merges shared data under the call data (call data wins), runs registered composers, renders the template, and — if the template called `extends()` — renders the layout with the child's *direct* output injected as `$content`.

**View data cannot collide with the engine's own locals.** Data is extracted with `EXTR_SKIP`, so the keys `__path`, `__data`, `view` and `this` are unavailable to templates — they are silently dropped rather than overwriting engine state. This is a security boundary, not a style rule: under the default `EXTR_OVERWRITE` a data key named `__path` replaced the template path the engine was about to `include`, which is arbitrary file inclusion for any project that flattens request input into view data (the natural "repopulate the form" pattern). Pass such a value under any other name.

**Layouts must emit the body via `$view->yield('content', $content ?? '')`, never bare `$content`.** A section-based page captures its entire body into the `content` section, leaving the direct output (and therefore `$content`) empty — a layout echoing bare `$content` renders section-based pages blank. The `$content` argument is only the fallback for templates that emit output without sections:

```php
<main class="container py-4"><?= $view->yield('content', $content ?? '') ?></main>
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

### Translations — `t()`

```php
$view->t('greet.hello', ['name' => 'World'])   // "Hello World"
```

- Files: `resources/lang/{locale}.json` — **flat** JSON maps whose keys are the full dot-namespaced strings (`"common.save": "Save"`). Missing files are fine (empty map).
- Placeholders are **`:name`**, substituted by plain string replace.
- Locale: `setLocale()` / `getLocale()` (default `config('app.locale')`). Fallback-locale strings (`config('app.fallback_locale')`, default `en`) load first; the active locale overrides key by key.
- A **missing key renders the key itself** — ugly but findable in the UI, which beats a silent fallback that hides the gap. Don't wrap `t()` calls in fallback strings.

The base ships no language files — the demo uses plain English strings. Projects that need i18n add `resources/lang/*.json` and route user-facing text through `t()`.

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

`layouts/app.php`, `home.php`, `dashboard.php`, `auth/login.php`, and the complete assessments demo: `index.php`, `form.php` (create/edit modal content), `detail.php` (read-only modal content), `create.php` / `edit.php` / `show.php` (full-page fallbacks embedding the same partials), and `partials/table-rows.php` — the table body shared by the index page and the AJAX search endpoint so the two render identical markup. Together they demonstrate the full data-action + ApiResponse modal CRUD flow (see [api-response.md](api-response.md)).

## Static assets

No build pipeline: files under `public/assets/` are served as `/assets/*` verbatim; the `asset($path)` helper builds `{app.url}/assets/{path}`. When you edit `resources/assets/js/kalliomicro.js`, copy it to `public/assets/js/` — the two must stay identical.

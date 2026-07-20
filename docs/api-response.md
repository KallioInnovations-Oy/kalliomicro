# ApiResponse Action System & the kalliomicro.js Client

> Sources: `src/Http/ApiResponse.php` (producer), `resources/assets/js/kalliomicro.js` (consumer; `public/assets/js/kalliomicro.js` is the served copy — keep them in sync).

The framework's signature feature: controllers return a **declarative list of UI actions** as JSON; a single client-side handler applies them to the DOM. No inline `eval()`, no per-endpoint JavaScript.

```
User action (data-action element / data-ajax form)
  → kalliomicro.js fetch()  (X-CSRF-Token + X-Requested-With injected)
    → Controller returns ApiResponse::…->toResponse()   (JSON envelope)
      → processResponse(): flash the message, execute each action in order
```

The client is **standalone vanilla JS** — no HTMX, no jQuery dependency (jQuery/DataTables are optional integration hooks, see `refresh_table`).

> **Historical note:** this client predates the 2026-03 decision to move server-driven UI to **HTMX + Tailwind** (the direction derived deployments follow, with an HTMX-based client implementing the same envelope). It remains the base's zero-build default and is fully functional — but treat the **action contract** (the envelope and action table below) as the durable specification; the `data-action` trigger system and Bootstrap styling are the legacy layer. A project starting fresh should choose its client stack first.

---

## The envelope

```json
{
  "success": true,
  "code": 1,
  "message": "Saved",
  "actions": [ { "type": "flash", "level": 1, "message": "Record created" }, … ],
  "data": { … }
}
```

- `code` levels (`ApiResponse::CODE_*`): `0` bypass, `1` success, `2` info, `3` warning, `4` error. The client maps them to alert styles `info/success/info/warning/error`.
- `actions` is omitted when empty; `data` when null.
- HTTP status comes from the factory; the client dispatches on the *body*.

## Building responses (server)

```php
return ApiResponse::success('Saved')
    ->flash('Record created', ApiResponse::CODE_SUCCESS)
    ->closeModal()
    ->replace('#assessments-table tbody', $rowsHtml)
    ->toResponse();
```

**Factories** (always start here — never build the JSON array by hand):

| Factory | success | code | HTTP |
|---|---|---|---|
| `success($msg = '')` | true | 1 | 200 |
| `info($msg)` / `warning($msg)` | true | 2 / 3 | 200 |
| `error($msg, $httpStatus = 400)` | false | 4 | 400 |
| `notFound($msg)` / `unauthorized($msg)` / `forbidden($msg)` | false | 4 | 404 / 401 / 403 |
| `validationError($msg, $errors)` | false | 4 | 422 + `data.validation_errors` |
| `serverError($msg)` | false | 4 | 500 |

Modifiers: `setSuccess()`, `setCode()`, `setMessage()`, `setHttpStatus()`, `withData($data)`, `withHeader($name, $value)`. Terminal: every chain ends with `->toResponse()`.

## Action reference

Every builder has a working client handler. Payload column = fields the client consumes.

| Builder | Payload | Client behavior |
|---|---|---|
| `flash($message, $level = null)` | `message`, `level` (defaults to response code) | Bootstrap alert in `#flash-messages`, auto-dismiss 5 s |
| `replace($target, $content)` | `target`, `content` | `innerHTML` swap, re-initializes injected content |
| `append($target, $content)` / `prepend($target, $content)` | `target`, `content` | `insertAdjacentHTML` beforeend / afterbegin |
| `remove($target)` | `target` | Element removed |
| `updateField($target, $value)` / `updateFields([$t => $v])` | `target`, `value` | Sets `.value` on INPUT/TEXTAREA/SELECT, else `.textContent` |
| `redirect($url)` | `url` | `window.location.href = url` |
| `openTab($url)` | `url` | `window.open(url, '_blank')` |
| `modal($content, $size = 'md', $id = null)` | `content`, `size`, `id` | Builds a Bootstrap modal + backdrop in `#modal-container` (see sizes note) |
| `nestedModal($content, $size = 'md', $level = 2)` | `content`, `size`, `level` | Stacked modal; z-index per level (1–3 styled by the layout) |
| `closeModal($level = null)` | `level` | Closes that level, or the topmost |
| `closeAllModals()` | — | Closes everything |
| `refreshTable($target, $data = null)` | `target`, `data` | DataTables redraw if loaded; otherwise full page reload — see below |
| `addTableRows($target, $rows)` | `target`, `rows` | Inserts HTML into `{target} tbody` |
| `clearForm($target)` | `target` | Empties inputs (skips hidden/submit/button) |
| `resetForm($target)` | `target` | Native `form.reset()` |
| `toggleDisabled($target, $disabled)` | `target`, `disabled` | Sets `.disabled` on all matches |
| `toggleVisibility($target, $visible)` | `target`, `visible` | Toggles Bootstrap `d-none` |
| `toggleClass($target, $class, $add)` | `target`, `class`, `add` | `classList.toggle` |
| `scrollTo($target)` | `target` | Smooth scroll, centered |
| `focus($target)` | `target` | `.focus()` |
| `triggerEvent($target, $event, $detail = [])` | `target`, `event`, `detail` | Dispatches a bubbling CustomEvent |
| `download($url, $filename = null)` | `url`, `filename` | Temporary `<a download>` click |
| `confirm($message, array $onConfirm)` | `message`, `on_confirm` | Confirm dialog; on OK executes the nested actions |
| `addAction(array $action)` | raw | Escape hatch for custom types (add a client case) |

Notes:

- **Modal sizes:** `size` becomes the Bootstrap dialog class `modal-{size}` — only `sm`, `lg`, `xl` are real Bootstrap classes; `md` (default width) and `full` are effectively no-ops. `id`, `size` and the nesting level are attribute-escaped before interpolation, so they cannot break out of the attribute; `content` is injected raw by design — it is the server-rendered modal body.
- **`refresh_table` semantics:** with jQuery + DataTables loaded, the table redraws in place; without them (the shipped layout loads neither) it falls back to a **full page reload** so state is always reflected. For partial updates without a reload, prefer `replace('#table tbody', $rowsHtml)` — the pattern the demo `AssessmentController::search()` uses.
- **`validationError()` field errors render per-field.** The client reads `data.validation_errors` (`field => messages`), marks each matching `[name="field"]` input `is-invalid`, and shows the message in an adjacent `.invalid-feedback` (a template-authored one is reused — its original text is remembered in `data-km-original` and restored on clear; otherwise one is created, tagged `data-km-error`). The first invalid field is focused; errors clear on the next submit of the same form. Rendering is **strictly scoped to the submitting form** (resolved from the trigger element); when no form is resolvable (e.g. a standalone action button), nothing is rendered — the flash message alone reports the failure, so unrelated forms are never touched. The client's own `validateForm()` still pre-checks `[required]` fields before submitting.

---

## Client-side wiring (kalliomicro.js)

An IIFE module exposing the global `KallioMicro` (`{ init, request, flash, showModal, closeTopModal, closeAllModals, processResponse, executeAction, config }`). Auto-initializes on `DOMContentLoaded`; all listeners are delegated, so injected content keeps working.

### Configuration

`KallioMicro.init(options)` merges over defaults: `csrfToken` (auto-read from `<meta name="csrf-token">` or a `csrf_token` input), `csrfHeader: 'X-CSRF-Token'`, `csrfField: 'csrf_token'`, `flashDuration: 5000`, `flashContainer: '#flash-messages'`, `modalContainer: '#modal-container'`, `loadingClass: 'is-loading'`, `debug: false`.

### Requests and CSRF

`request(url, options)` wraps `fetch` with `Accept: application/json` and `X-Requested-With: XMLHttpRequest`; **POST/PUT/PATCH/DELETE additionally carry the `X-CSRF-Token` header**. AJAX form submissions also append the `csrf_token` field when missing. Network errors surface as an error flash.

**Only `application/json` bodies are consumed.** A response the server did not label `application/json` is reported as a failure (`{success: false, code: 4}`) and its body is **discarded** — it is never handed to `innerHTML`/`insertAdjacentHTML`. This matters because `fetch` follows redirects transparently: an auth or consent gate answering `302` → HTML login page would otherwise arrive looking exactly like the endpoint's own output, and the client would inject that entire page into a modal. Nothing in the body distinguishes the two, so the content type is the only trustworthy signal.

When the response was redirected (`response.redirected`) the client additionally emits a `redirect` action to the final URL — but **only if that URL is same-origin**, so a server-side open redirect cannot turn a background request into an off-site navigation. Cross-origin destinations are reported in the console and flashed, never followed.

Consequence for controllers: **serve modal/partial content as JSON**, i.e. an `ApiResponse` with a `modal`/`replace` action (or explicit `withData(['content' => …])`). Returning a bare HTML view to a `data-action` endpoint no longer renders — it flashes an error.

### Trigger system — `data-action`

Clicks on `[data-action]` elements dispatch declaratively:

| `data-action` | Attributes read | Behavior |
|---|---|---|
| `submit` | `data-form` (id) or closest form; `data-url` | AJAX-submits the form |
| `load` | `data-url`, `data-method` (GET) | Fetches and processes the response — **placement comes from the response's actions**, see below |
| `confirm` | `data-message`, `data-confirm-text`, `data-cancel-text`, `data-url`, `data-method` (POST), other `data-*` as payload | Confirm dialog → request |
| `modal` | `data-url`, `data-size` (md) | Fetches content into a modal (JSON `response.data.content`, or a `modal` action) |
| `close-modal` | — | Closes the topmost modal |
| `toggle` | `data-target`, `data-toggle-class` (`d-none`) | Class toggle |
| `copy` | `data-copy-text` or element text | Clipboard copy + flash |

Forms opt into AJAX with the `data-ajax` attribute (method from `data-method` → `form.method` → POST); `data-auto-submit` on a field submits its form on change. ESC closes the topmost modal.

**`data-target` on a `load` action is inert.** The client acts only on the actions in the response, and each of those carries its own `target`; `data-target` has never influenced placement. It is accepted (markup may use it to document intent) but no longer *required*, and setting one logs a `console.warn` — a mandatory-looking attribute that does nothing is worse than no attribute. To place loaded content, return `replace('#container', $html)` (or `append`/`prepend`) from the controller.

### Failure visibility

Two classes of silent failure are now audible in the console. Neither changes what the DOM ends up looking like:

- **Missing target.** Every target-taking action that matches no element logs `KallioMicro: no element matches "<selector>" — <action> skipped`. The action still no-ops (a shared response may name targets a given page does not have), but a stale selector no longer looks identical to a working feature.
- **A throwing action.** Actions execute independently inside a `try`/`catch`: a malformed selector (`querySelector` raises `SyntaxError`) used to abort every remaining action in the list and escape as an unhandled promise rejection. Now the bad action is logged with `console.error`, an error flash is shown, and the rest of the list still runs. The four request call sites (`load`, `confirm`, `modal`, form submit) also carry a `.catch()` for the same reason.

### ⚠ Delegation amplifies HTML-injection bugs (as of 2026-07-18 — by design, not fixed in the client)

`data-action` handling is delegated at `document` level, so it applies to **any** matching element, including markup injected later by a `replace`/`append`/`modal` action. A single unescaped value that lands in the DOM as

```html
<button data-action="submit" data-url="/app/users/1/delete" data-method="POST">Click me</button>
```

turns one victim click into a fully authenticated request **with the CSRF token attached automatically** by the client. A Content-Security-Policy cannot see this: no new script executes, no inline handler is present — it is ordinary markup plus the framework's own listener.

This is not a standalone vulnerability; it requires an HTML-injection precondition, i.e. the application already failed to escape something. It is documented here because **the precondition is cheaper to reach than most people assume, and the consequence is worse**: with delegation, an escaping slip is not a defacement or a script-injection that CSP might catch, it is a state-changing, CSRF-valid POST one click away. Deliberately not "fixed" in the client — restricting delegation to non-injected markup would break `replace`, which exists precisely so server-rendered content stays interactive. The mitigation belongs where the bug is: escape all dynamic template output (`$view->e()` / `e()`, [conventions](conventions.md) security checklist item 6) and treat any HTML-injection finding as high severity in an app built on this client.

### Events

The client dispatches `km:response` (`{response, trigger}`) after every processed response and `km:content-loaded` (`{element}`) after every content injection — hook these for custom behavior (e.g. re-initializing widgets in injected HTML).

### DOM anchors the layout must provide

`<meta name="csrf-token">`, `#flash-messages`, `#modal-container` (both containers are auto-created if missing, but the layout ships them), and the modal z-index CSS for stacking levels 1–3.

---

## Canonical flow — modal create/edit (from the demo)

```php
// Open: <button data-action="modal" data-url="/app/assessments/create" data-size="lg">
public function create(Request $request): Response
{
    $content = $this->renderPartial('assessments.form', ['assessment' => null]);
    return ApiResponse::success()->modal($content, 'lg')->toResponse();
}

// Submit: <form id="assessment-form" data-ajax="true" action="/app/assessments" method="POST">
//         with <?= $view->csrf() ?> — and <?= $view->method('PUT') ?> on edit
public function store(Request $request): Response
{
    $this->requireCsrf();
    $validation = $this->validate([...]);
    if (!$validation['valid']) {
        return ApiResponse::validationError('Please fix the errors', $validation['errors'])->toResponse();
    }
    // … insert …
    return ApiResponse::success()
        ->flash('Assessment created', ApiResponse::CODE_SUCCESS)
        ->closeModal()
        ->replace('#assessments-table tbody', $this->renderPartial('assessments.partials.table-rows', [...]))
        ->toResponse();
}
```

## Rules summary

1. Always use `ApiResponse` factories; never hand-build the JSON envelope.
2. Every chain ends with `->toResponse()`.
3. Keep `resources/assets/js/kalliomicro.js` and `public/assets/js/kalliomicro.js` identical — the `public/` copy is what browsers load.
4. Never hand-roll CSRF headers — the client injects them on every mutating request.
5. Prefer `replace()` over `refresh_table` when a partial update suffices — without DataTables, `refresh_table` costs a full page reload.
6. Endpoints reached by the client must answer `application/json`; a non-JSON body is discarded, not rendered.
7. Escape every dynamic value in your templates — with document-level delegation, injected markup inherits the framework's authenticated, CSRF-carrying triggers.

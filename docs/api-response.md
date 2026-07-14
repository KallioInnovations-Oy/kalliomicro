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

- **Modal sizes:** `size` becomes the Bootstrap dialog class `modal-{size}` — only `sm`, `lg`, `xl` are real Bootstrap classes; `md` (default width) and `full` are effectively no-ops.
- **`refresh_table` semantics:** with jQuery + DataTables loaded, the table redraws in place; without them (the shipped layout loads neither) it falls back to a **full page reload** so state is always reflected. For partial updates without a reload, prefer `replace('#table tbody', $rowsHtml)` — the pattern the demo `AssessmentController::search()` uses.
- ⚠ **`validationError()` field errors are not rendered per-field** by the stock client — `data.validation_errors` is ignored; only the message is flashed. The client's own `validateForm()` pre-checks `[required]` fields before submitting. Render server-side errors yourself (re-render the form partial via `replace`/`modal`) or extend the client.

---

## Client-side wiring (kalliomicro.js)

An IIFE module exposing the global `KallioMicro` (`{ init, request, flash, showModal, closeTopModal, closeAllModals, processResponse, executeAction, config }`). Auto-initializes on `DOMContentLoaded`; all listeners are delegated, so injected content keeps working.

### Configuration

`KallioMicro.init(options)` merges over defaults: `csrfToken` (auto-read from `<meta name="csrf-token">` or a `csrf_token` input), `csrfHeader: 'X-CSRF-Token'`, `csrfField: 'csrf_token'`, `flashDuration: 5000`, `flashContainer: '#flash-messages'`, `modalContainer: '#modal-container'`, `loadingClass: 'is-loading'`, `debug: false`.

### Requests and CSRF

`request(url, options)` wraps `fetch` with `Accept: application/json` and `X-Requested-With: XMLHttpRequest`; **POST/PUT/PATCH/DELETE additionally carry the `X-CSRF-Token` header**. AJAX form submissions also append the `csrf_token` field when missing. Non-JSON responses are wrapped as `{success, code, message: '', data: {content}}`; network errors surface as an error flash.

### Trigger system — `data-action`

Clicks on `[data-action]` elements dispatch declaratively:

| `data-action` | Attributes read | Behavior |
|---|---|---|
| `submit` | `data-form` (id) or closest form; `data-url` | AJAX-submits the form |
| `load` | `data-url`, `data-target`, `data-method` (GET) | Fetches and processes the response |
| `confirm` | `data-message`, `data-confirm-text`, `data-cancel-text`, `data-url`, `data-method` (POST), other `data-*` as payload | Confirm dialog → request |
| `modal` | `data-url`, `data-size` (md) | Fetches content into a modal (`response.data.content` or a `modal` action) |
| `close-modal` | — | Closes the topmost modal |
| `toggle` | `data-target`, `data-toggle-class` (`d-none`) | Class toggle |
| `copy` | `data-copy-text` or element text | Clipboard copy + flash |

Forms opt into AJAX with the `data-ajax` attribute (method from `data-method` → `form.method` → POST); `data-auto-submit` on a field submits its form on change. ESC closes the topmost modal.

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

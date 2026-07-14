# Validation

> Source: `Controller::validate()` and its private `validate*` methods in `src/Http/Controller.php`.
> Validation is implemented inline on the controller base class — there is no standalone Validator class in the base framework.

```php
$validation = $this->validate([
    'name'  => 'required|string|max:255',
    'email' => 'required|email',
]);

if (!$validation['valid']) {
    return ApiResponse::validationError('Please fix the errors', $validation['errors'])->toResponse();
}
```

## API

```php
protected function validate(array $rules, array $messages = []): array
```

- `$rules` — `field => 'rule1|rule2:param|…'` (pipe-delimited string) or `field => [rules]` array form.
- A rule with parameters splits on the first `:`; the parameter list splits on commas — **except `regex`, which takes the whole remainder** so patterns may contain commas (`regex:/^[A-Z]{2,4}[0-9]+$/` works).
- Values come from `$this->all()` (query + post). A field absent from the input validates as `null` — which passes every rule except `required`.
- **Unknown rule names throw `InvalidArgumentException`** — a typo like `requird` fails loudly instead of silently passing.

### Return shape

```php
[
    'valid'  => bool,
    'errors' => array<string, string[]>,   // field => messages (max one per field — first failing rule wins)
    'data'   => array<string, mixed>,      // the raw input, verbatim — NO sanitization
]
```

⚠ `data` echoes the raw input — whitelist fields explicitly before writing to the database.

### Custom messages

Third parameter overrides built-ins. Precedence: `messages['field.rule']` → `messages['field']` → built-in English default.

## Rule reference

| Rule | Passes when | Notes |
|---|---|---|
| `required` | value is not `null`, `''`, or `[]` | The only rule that fails on empty |
| `email` | `FILTER_VALIDATE_EMAIL` | Skips null/empty |
| `numeric` | `is_numeric()` | Skips null/empty |
| `integer` | `FILTER_VALIDATE_INT` | Skips null/empty |
| `string` | value is `null` or a PHP string | |
| `min:N` / `max:N` | see numeric-vs-length below | Skip null/empty |
| `between:N,M` | `min:N` then `max:M`; first failure wins | |
| `in:a,b,c` | `in_array($value, $params, strict: true)` | Params are strings — matches form input; a real PHP int fails against `'1'` |
| `confirmed` | `$data["{field}_confirmation"]` matches (`!==`) | |
| `url` | `FILTER_VALIDATE_URL` | Skips null/empty |
| `date` | `strtotime()` succeeds | |
| `regex:pattern` | `preg_match($pattern, $value)` | Pattern taken whole — commas allowed |

### min/max: string length vs numeric comparison

`min`/`max`/`between` compare **numerically** only when the field's rule list also declares `numeric` or `integer`, or the value is a real PHP `int`/`float`. Otherwise they compare **string length**.

Rationale: HTTP form input is always strings. Without the declared-rule check, `'123412342134'` with `max:255` would compare as a number and fail when the author meant "max 255 characters":

```php
'title'  => 'required|string|max:255',   // max = 255 characters
'amount' => 'required|numeric|max:255',  // max = value ≤ 255
```

## Behavioral notes

1. **First error per field only** — evaluation stops at the first failing rule; order rules accordingly (`required` first).
2. **No database-aware rules** — uniqueness/existence checks belong in the controller and in schema constraints.
3. Client side, `kalliomicro.js` performs only a required-fields pre-check; server `validation_errors` reach the client in `data.validation_errors` but are **not** rendered per-field by the stock client (only the message is flashed) — see [api-response.md](api-response.md).

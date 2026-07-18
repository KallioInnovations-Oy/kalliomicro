<?php

declare(strict_types=1);

namespace KallioMicro\Http;

use KallioMicro\Core\Application;
use KallioMicro\Database\Connection;
use KallioMicro\View\ViewEngine;
use KallioMicro\Auth\Session;

/**
 * Controller - Base controller class
 *
 * Provides common functionality for all controllers including
 * view rendering, response building, validation, and database access.
 */
abstract class Controller
{
    protected Application $app;
    protected Request $request;
    protected ?Connection $db = null;
    protected ?ViewEngine $view = null;
    protected ?Session $session = null;

    /**
     * Initialize controller with dependencies
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->request = $app->make(Request::class);

        if ($app->has('db')) {
            $this->db = $app->make(Connection::class);
        }

        if ($app->has('view')) {
            $this->view = $app->make(ViewEngine::class);
        }

        if ($app->has('session')) {
            $this->session = $app->make(Session::class);
        }

        $this->boot();
    }

    /**
     * Override this method to run initialization logic
     */
    protected function boot(): void
    {
        // Override in child classes
    }

    // Response helpers

    /**
     * Create a success API response
     */
    protected function success(string $message = ''): ApiResponse
    {
        return ApiResponse::success($message);
    }

    /**
     * Create an error API response
     */
    protected function error(string $message, int $httpStatus = 400): ApiResponse
    {
        return ApiResponse::error($message, $httpStatus);
    }

    /**
     * Create a JSON response
     *
     * @param array<string, mixed>|object $data
     */
    protected function json(array|object $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * Create an HTML response
     */
    protected function html(string $content, int $status = 200): Response
    {
        return Response::html($content, $status);
    }

    /**
     * Render a view template
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        if ($this->view === null) {
            throw new \RuntimeException('View engine not configured');
        }

        $content = $this->view->render($template, $this->prepareViewData($data));
        return Response::html($content, $status);
    }

    /**
     * Render a view and return as ApiResponse action
     *
     * Renders as a partial for the same reason renderPartial() does: the output
     * goes into a DOM target, so a template calling extends() must not drag the
     * page layout in with it. This used to call render() and was the second
     * door to the <!DOCTYPE html>-in-a-modal bug fixed in 1.2.0.
     *
     * @param array<string, mixed> $data
     */
    protected function renderToResponse(string $template, string $target, array $data = []): ApiResponse
    {
        return ApiResponse::success()
            ->replace($target, $this->renderPartial($template, $data));
    }

    /**
     * Render a partial/component — no layout, whatever the template asks for
     *
     * This is the documented way to build modal content, so it must ignore
     * extends(): rendering through render() wrapped a template that called
     * extends() in the full page layout and injected a whole <!DOCTYPE html>
     * document into the modal body.
     *
     * @param array<string, mixed> $data
     */
    protected function renderPartial(string $template, array $data = []): string
    {
        if ($this->view === null) {
            throw new \RuntimeException('View engine not configured');
        }

        return $this->view->partial($template, $this->prepareViewData($data));
    }

    /**
     * Prepare data for view rendering
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function prepareViewData(array $data): array
    {
        // Add common view data
        $data['csrf_token'] = $this->session?->getCsrfToken();
        $data['user'] = $this->session?->getUser();
        $data['flash'] = $this->session?->getFlash();

        // Also share into the engine: $view->csrf(), isAuth() and hasRole()
        // read the shared bag, not per-render data — without this they see
        // an empty token / no user in every template.
        if ($this->view !== null) {
            $this->view->share('csrf_token', $data['csrf_token']);
            $this->view->share('user', $data['user']);
        }

        return $data;
    }

    /**
     * Create a redirect response
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Create a redirect back response
     *
     * The Referer header is client-supplied: a cross-origin referer falls back
     * to '/', and even a same-origin one is reduced to path + query so this
     * can never become an open redirect.
     */
    protected function back(): Response
    {
        $referer = $this->request->header('referer', '/');

        // parse_url on both sides: handles ports and bracketed IPv6 hosts
        // uniformly ('[::1]:8080' → '::1'), and a missing Host header parses
        // to null rather than crashing the comparison.
        $refHost = parse_url($referer, PHP_URL_HOST);
        $hostHeader = (string) $this->request->header('host', '');
        $reqHost = $hostHeader === '' ? null : parse_url('http://' . $hostHeader, PHP_URL_HOST);

        if (is_string($refHost) && strtolower($refHost) !== strtolower((string) $reqHost)) {
            return Response::redirect('/');
        }

        return Response::redirect(Session::sanitizeRelativeUrl($referer));
    }

    // Request helpers

    /**
     * Get request input
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    /**
     * Get all request input
     *
     * @return array<string, mixed>
     */
    protected function all(): array
    {
        return $this->request->all();
    }

    /**
     * Get only specific input keys
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    protected function only(array $keys): array
    {
        return $this->request->only($keys);
    }

    /**
     * Get route parameter
     */
    protected function route(string $key, mixed $default = null): mixed
    {
        return $this->request->route($key, $default);
    }

    /**
     * Check if request is AJAX
     */
    protected function isAjax(): bool
    {
        return $this->request->isAjax();
    }

    /**
     * Check if request wants JSON
     */
    protected function wantsJson(): bool
    {
        return $this->request->wantsJson();
    }

    // Validation

    /**
     * Validate request input
     *
     * @param array<string, string|array> $rules
     * @param array<string, string> $messages
     * @return array{valid: bool, errors: array<string, string[]>, data: array<string, mixed>}
     */
    protected function validate(array $rules, array $messages = []): array
    {
        $data = $this->all();
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $params = [];

                // Parse rule:param1,param2 format
                if (str_contains($rule, ':')) {
                    [$rule, $paramString] = explode(':', $rule, 2);
                    // Regex patterns may legitimately contain commas — take the remainder whole
                    $params = $rule === 'regex' ? [$paramString] : explode(',', $paramString);
                }

                $error = $this->validateRule($field, $value, $rule, $params, $data, $fieldRules);

                if ($error !== null) {
                    $customKey = "{$field}.{$rule}";
                    $errors[$field][] = $messages[$customKey] ?? $messages[$field] ?? $error;
                    break; // Stop on first error for this field
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data,
        ];
    }

    /**
     * Validate a single rule
     */
    private function validateRule(string $field, mixed $value, string $rule, array $params, array $data, array $allRules = []): ?string
    {
        return match ($rule) {
            'required' => $this->validateRequired($field, $value),
            'email' => $this->validateEmail($field, $value),
            'numeric' => $this->validateNumeric($field, $value),
            'integer' => $this->validateInteger($field, $value),
            'string' => $this->validateString($field, $value),
            'min' => $this->validateMin($field, $value, $params[0] ?? 0, $allRules),
            'max' => $this->validateMax($field, $value, $params[0] ?? PHP_INT_MAX, $allRules),
            'between' => $this->validateBetween($field, $value, $params[0] ?? 0, $params[1] ?? PHP_INT_MAX, $allRules),
            'in' => $this->validateIn($field, $value, $params),
            'confirmed' => $this->validateConfirmed($field, $value, $data),
            'url' => $this->validateUrl($field, $value),
            'date' => $this->validateDate($field, $value),
            'regex' => $this->validateRegex($field, $value, $params[0] ?? ''),
            // A typo'd rule name silently passing is worse than an exception in dev
            default => throw new \InvalidArgumentException(
                "Unknown validation rule: {$rule}. Shipped rules: required, email, numeric, integer, "
                . "string, min, max, between, in, confirmed, url, date, regex. Database-aware rules "
                . "(unique/exists) are intentionally not shipped — see docs/validation.md."
            ),
        };
    }

    /**
     * Whether min/max/between should compare numerically instead of by string length.
     *
     * Form input always arrives as strings, so a bare is_numeric() check would
     * compare '123412342134' as a number against max:255 when the author meant
     * "max 255 characters". Numeric comparison applies only when the field also
     * declares numeric/integer, or the value is a real PHP int/float.
     */
    private function isNumericContext(array $allRules, mixed $value): bool
    {
        foreach ($allRules as $declaredRule) {
            $name = str_contains($declaredRule, ':') ? strstr($declaredRule, ':', true) : $declaredRule;
            if ($name === 'numeric' || $name === 'integer') {
                return true;
            }
        }

        return is_int($value) || is_float($value);
    }

    private function validateRequired(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return "The {$field} field is required.";
        }
        return null;
    }

    private function validateEmail(string $field, mixed $value): ?string
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "The {$field} must be a valid email address.";
        }
        return null;
    }

    private function validateNumeric(string $field, mixed $value): ?string
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            return "The {$field} must be a number.";
        }
        return null;
    }

    private function validateInteger(string $field, mixed $value): ?string
    {
        if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            return "The {$field} must be an integer.";
        }
        return null;
    }

    private function validateString(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return "The {$field} must be a string.";
        }
        return null;
    }

    private function validateMin(string $field, mixed $value, mixed $min, array $allRules = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $min = (int) $min;

        if ($this->isNumericContext($allRules, $value)) {
            if (is_numeric($value) && $value < $min) {
                return "The {$field} must be at least {$min}.";
            }
            return null;
        }

        if (is_string($value) && strlen($value) < $min) {
            return "The {$field} must be at least {$min} characters.";
        }

        return null;
    }

    private function validateMax(string $field, mixed $value, mixed $max, array $allRules = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $max = (int) $max;

        if ($this->isNumericContext($allRules, $value)) {
            if (is_numeric($value) && $value > $max) {
                return "The {$field} may not be greater than {$max}.";
            }
            return null;
        }

        if (is_string($value) && strlen($value) > $max) {
            return "The {$field} may not be greater than {$max} characters.";
        }

        return null;
    }

    private function validateBetween(string $field, mixed $value, mixed $min, mixed $max, array $allRules = []): ?string
    {
        $minError = $this->validateMin($field, $value, $min, $allRules);
        if ($minError) {
            return $minError;
        }

        return $this->validateMax($field, $value, $max, $allRules);
    }

    private function validateIn(string $field, mixed $value, array $allowed): ?string
    {
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            return "The selected {$field} is invalid.";
        }
        return null;
    }

    private function validateConfirmed(string $field, mixed $value, array $data): ?string
    {
        $confirmation = $data["{$field}_confirmation"] ?? null;
        if ($value !== $confirmation) {
            return "The {$field} confirmation does not match.";
        }
        return null;
    }

    private function validateUrl(string $field, mixed $value): ?string
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            return "The {$field} must be a valid URL.";
        }
        return null;
    }

    private function validateDate(string $field, mixed $value): ?string
    {
        if ($value !== null && $value !== '' && strtotime($value) === false) {
            return "The {$field} is not a valid date.";
        }
        return null;
    }

    private function validateRegex(string $field, mixed $value, string $pattern): ?string
    {
        if ($value !== null && $value !== '' && !preg_match($pattern, $value)) {
            return "The {$field} format is invalid.";
        }
        return null;
    }

    // Security helpers

    /**
     * Verify CSRF token
     */
    protected function verifyCsrf(): bool
    {
        if ($this->session === null) {
            return false;
        }

        // An empty csrf_token field (e.g. rendered before the session token was
        // shared) must not shadow the X-CSRF-Token header the JS client sends.
        $token = $this->input('csrf_token');
        if ($token === null || $token === '') {
            $token = $this->request->header('x-csrf-token');
        }

        return $this->session->verifyCsrfToken($token);
    }

    /**
     * Abort with error if CSRF verification fails
     */
    protected function requireCsrf(): void
    {
        if (!$this->verifyCsrf()) {
            throw new \RuntimeException('CSRF token mismatch', 403);
        }
    }

    /**
     * Check if user is authenticated
     */
    protected function isAuthenticated(): bool
    {
        return $this->session?->isAuthenticated() ?? false;
    }

    /**
     * Get the current authenticated user
     *
     * @return array<string, mixed>|null
     */
    protected function user(): ?array
    {
        return $this->session?->getUser();
    }

    /**
     * Get current user ID
     */
    protected function userId(): ?int
    {
        return $this->session?->getUserId();
    }

    // Database helpers

    /**
     * Get a database table query builder
     */
    protected function table(string $table): \KallioMicro\Database\QueryBuilder
    {
        if ($this->db === null) {
            throw new \RuntimeException('Database not configured');
        }

        return $this->db->table($table);
    }
}

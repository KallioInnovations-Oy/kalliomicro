<?php

declare(strict_types=1);

namespace KallioMicro\View;

use RuntimeException;

/**
 * ViewEngine - Template rendering engine
 *
 * Provides a clean API for rendering templates with:
 * - Layout/section support
 * - Partial/component rendering
 * - Escaping helpers
 * - Extension support (can use native PHP or integrate with Twig)
 */
class ViewEngine
{
    private string $viewPath;

    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, callable> */
    private array $composers = [];

    /** @var string[] */
    private array $extensions = ['.php', '.html.php'];

    private ?string $currentLayout = null;

    private ?string $locale = null;

    /** @var array<string, string>|null Lazily loaded translation map */
    private ?array $translations = null;

    /** @var array<string, string> */
    private array $sections = [];

    private ?string $currentSection = null;

    /**
     * There is no cache path parameter
     *
     * Templates are plain PHP includes — nothing is compiled, so nothing is
     * cached. The constructor used to take a $cachePath, store it in a private
     * property with no accessor, and never read it again: not an extension
     * point, since a downstream could not reach it either, just write-only
     * state that made the Application compute a storage path for nothing.
     * PHP ignores surplus arguments to userland functions, so an existing
     * `new ViewEngine($views, $cache)` call keeps working.
     */
    public function __construct(string $viewPath)
    {
        $this->viewPath = rtrim($viewPath, '/');

        if (!is_dir($this->viewPath)) {
            throw new RuntimeException("View path does not exist: {$this->viewPath}");
        }
    }

    /**
     * Render a template with data
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->resolvePath($template);

        // Each page render starts with a clean slate — otherwise sections
        // captured by an earlier render (e.g. 'scripts') leak into this one.
        // currentSection/currentLayout are reset for a sharper reason: a render
        // that threw mid-template never reached endSection() and never cleared
        // its layout, so this singleton would wrap the next, unrelated page in
        // a layout it never requested and mis-close the next section.
        $this->sections = [];
        $this->currentSection = null;
        $this->currentLayout = null;

        // Merge shared data
        $data = array_merge($this->shared, $data);

        // Run view composers
        $this->runComposers($template, $data);

        // Render the template
        $content = $this->renderFile($path, $data);

        // Handle layout if one was set
        if ($this->currentLayout !== null) {
            $layoutPath = $this->resolvePath($this->currentLayout);
            $this->currentLayout = null;

            $data['content'] = $content;
            $content = $this->renderFile($layoutPath, $data);
        }

        return $content;
    }

    /**
     * Render a partial/component (no layout)
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        $path = $this->resolvePath($template);
        $data = array_merge($this->shared, $data);

        // Snapshot rather than test absolutely: a partial rendered from inside
        // a page would otherwise see the PAGE's layout and report it as its own.
        $layoutBefore = $this->currentLayout;

        $content = $this->renderFile($path, $data);

        if ($this->currentLayout !== $layoutBefore && $this->currentLayout !== null) {
            $declared = $this->currentLayout;

            // Don't leave the parent render wrapped in a layout it never asked for
            $this->currentLayout = $layoutBefore;

            throw new RuntimeException(sprintf(
                'View [%s] calls extends(\'%s\') but was rendered as a partial. '
                . 'partial() does no layout handling, so a template that captures '
                . 'its body into sections returns an empty string and the content '
                . 'silently disappears. Drop the extends()/section() calls to make '
                . 'it a real partial, or render it with render() as a full page.',
                $template,
                $declared
            ));
        }

        return $content;
    }

    /**
     * Render a component with slot support
     *
     * @param array<string, mixed> $data
     */
    public function component(string $template, array $data = [], ?string $slot = null): string
    {
        $data['slot'] = $slot;
        return $this->partial("components/{$template}", $data);
    }

    /**
     * Check if a template exists
     */
    public function exists(string $template): bool
    {
        try {
            $this->resolvePath($template);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Share data with all templates
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Share multiple values with all templates
     *
     * @param array<string, mixed> $data
     */
    public function shareMany(array $data): void
    {
        $this->shared = array_merge($this->shared, $data);
    }

    /**
     * Register a view composer (runs before template)
     */
    public function composer(string $template, callable $callback): void
    {
        $this->composers[$template] = $callback;
    }

    /**
     * Run view composers for a template
     *
     * @param array<string, mixed> $data
     */
    private function runComposers(string $template, array &$data): void
    {
        if (isset($this->composers[$template])) {
            $this->composers[$template]($data);
        }

        // Check for wildcard composers
        foreach ($this->composers as $pattern => $callback) {
            if (str_contains($pattern, '*')) {
                $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
                if (preg_match("/^{$regex}$/", $template)) {
                    $callback($data);
                }
            }
        }
    }

    /**
     * Resolve template name to file path
     */
    private function resolvePath(string $template): string
    {
        // Convert dot notation to path
        $template = str_replace('.', '/', $template);

        // is_file(), not file_exists(): a directory exists, so exists('assessments')
        // answered true for the folder resources/views/assessments and the render
        // then failed inside include() instead of with "View not found".
        foreach ($this->extensions as $ext) {
            $path = $this->viewPath . '/' . $template . $ext;
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        // Try without extension
        $path = $this->viewPath . '/' . $template;
        if (is_file($path) && is_readable($path)) {
            return $path;
        }

        throw new RuntimeException("View not found: {$template}");
    }

    /**
     * Render a PHP template file
     *
     * @param array<string, mixed> $__data
     */
    private function renderFile(string $__path, array $__data): string
    {
        // Declared before extract() so EXTR_SKIP protects it like the parameters.
        $__level = ob_get_level();

        // Extract data to local scope. EXTR_SKIP is required, not cosmetic:
        // the default EXTR_OVERWRITE lets a data key named '__path' replace the
        // include target below with an arbitrary file. Since $__path/$__data
        // are parameters they already exist, so EXTR_SKIP leaves them intact
        // and those key names are silently unavailable to templates.
        extract($__data, EXTR_SKIP);

        // Make view engine available in templates (after extract — not clobberable)
        $view = $this;

        ob_start();

        try {
            // func_get_arg() re-reads the original argument rather than the
            // local, so the include target holds even if the guard above moves.
            include func_get_arg(0);
        } catch (\Throwable $e) {
            // A template that threw inside section() left that buffer open too,
            // so a single ob_end_clean() discards the section and leaves this
            // method's own buffer behind — PHP then flushes the aborted page at
            // shutdown, ahead of the error page. Unwind to the entry depth.
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }

            throw $e;
        }

        return ob_get_clean();
    }

    // Template helpers (available in templates via $view->method())

    /**
     * Set the layout for the current view
     */
    public function extends(string $layout): void
    {
        $this->currentLayout = $layout;
    }

    /**
     * Start a section
     */
    public function section(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /**
     * End the current section
     */
    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw new RuntimeException('No section started');
        }

        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    /**
     * Yield a section (in layouts)
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Include another template
     *
     * @param array<string, mixed> $data
     */
    public function include(string $template, array $data = []): string
    {
        return $this->partial($template, $data);
    }

    /**
     * Escape HTML
     */
    public function e(mixed $value): string
    {
        return self::escape($value);
    }

    /**
     * The single implementation behind both `$view->e()` and the global `e()`
     * helper, which docs/views.md presents as interchangeable — they drifted
     * apart (one returned 'Array', the other raised a TypeError) precisely
     * because each had its own copy of this logic.
     *
     * Non-stringable values are rejected rather than coerced: `e($row)` printing
     * "Array" is a bug that reaches production looking like content, and the
     * escaping helper is the wrong place to guess what the template meant.
     */
    public static function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException(
                'e() expects a string, scalar, Stringable or null; ' . get_debug_type($value) . ' given.'
            );
        }

        // ENT_SUBSTITUTE: without it a single invalid UTF-8 byte (legacy
        // imports are full of them) makes htmlspecialchars return '', blanking
        // the whole field instead of the one bad character.
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for JavaScript
     */
    public function js(mixed $value): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE for the same reason as ENT_SUBSTITUTE
        // above — without it json_encode returns false on one bad byte, which
        // this method's string return type turned into an unreadable "Return
        // value must be of type string" TypeError mid-template. THROW_ON_ERROR
        // keeps the remaining failures (recursion, INF/NAN) loud but named.
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Escape for HTML attribute
     */
    public function attr(mixed $value): string
    {
        return self::escape($value);
    }

    /**
     * Format date
     */
    public function date(mixed $value, string $format = 'd.m.Y'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value)) {
            $value = strtotime($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        return date($format, (int) $value);
    }

    /**
     * Format datetime
     */
    public function datetime(mixed $value, string $format = 'd.m.Y H:i'): string
    {
        return $this->date($value, $format);
    }

    /**
     * Format number
     */
    public function number(mixed $value, int $decimals = 2, string $decPoint = ',', string $thousandsSep = ' '): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $decimals, $decPoint, $thousandsSep);
    }

    /**
     * Translate text (placeholder - implement with your i18n system)
     *
     * @param array<string, mixed> $params
     */
    public function t(string $key, array $params = []): string
    {
        $this->translations ??= $this->loadTranslations();

        // A missing key renders the key itself — ugly but findable in the UI,
        // which beats a silent fallback that hides the gap.
        $text = $this->translations[$key] ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace(":{$name}", (string) $value, $text);
        }

        return $text;
    }

    /**
     * Set the active locale (clears the translation cache)
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->translations = null;
    }

    public function getLocale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        // Guarded on the container, not on function_exists('config'):
        // helpers.php is in composer's autoload.files, so config() is defined
        // the moment the autoloader runs and that test could never be false.
        // What actually breaks is config() without an Application — it resolves
        // through app(), so a bare `new ViewEngine($path)` in a script fataled
        // with "Call to a member function make() on null". app() is used rather
        // than importing Application so src/View/ stays standalone.
        return app() !== null ? (string) config('app.locale', 'en') : 'en';
    }

    /**
     * Load translations from resources/lang/{locale}.json
     *
     * Files are FLAT JSON maps — the whole dot-namespaced key is one JSON key
     * ("common.save": "Save"). Fallback-locale strings load first; the active
     * locale overrides key by key. Missing files are fine (empty map).
     *
     * @return array<string, string>
     */
    private function loadTranslations(): array
    {
        $langPath = dirname($this->viewPath) . '/lang';
        $locale = $this->getLocale();
        $fallback = app() !== null ? (string) config('app.fallback_locale', 'en') : 'en';

        $load = static function (string $loc) use ($langPath): array {
            $file = "{$langPath}/{$loc}.json";
            if (!is_file($file)) {
                return [];
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            return is_array($decoded) ? $decoded : [];
        };

        $translations = $fallback !== $locale ? $load($fallback) : [];

        return array_merge($translations, $load($locale));
    }

    /**
     * Generate CSRF token field
     */
    public function csrf(): string
    {
        $token = $this->shared['csrf_token'] ?? '';
        return '<input type="hidden" name="csrf_token" value="' . $this->e($token) . '">';
    }

    /**
     * Generate method spoofing field
     */
    public function method(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . $this->e($method) . '">';
    }

    /**
     * Check if current user has role
     *
     * @param string|string[] $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $userRoles = $this->shared['user']['roles'] ?? [];
        $roles = (array) $roles;

        return !empty(array_intersect($roles, $userRoles));
    }

    /**
     * Check if user is authenticated
     */
    public function isAuth(): bool
    {
        return !empty($this->shared['user']);
    }

    /**
     * Conditionally add CSS class
     */
    public function classIf(bool $condition, string $class, string $else = ''): string
    {
        return $condition ? $class : $else;
    }

    /**
     * Conditionally include attribute
     */
    public function attrIf(bool $condition, string $attr, ?string $value = null): string
    {
        if (!$condition) {
            return '';
        }

        if ($value === null) {
            return $attr;
        }

        return $attr . '="' . $this->e($value) . '"';
    }

    /**
     * Generate selected attribute for select options
     */
    public function selected(mixed $value, mixed $expected): string
    {
        return $value == $expected ? 'selected' : '';
    }

    /**
     * Generate checked attribute for checkboxes/radios
     */
    public function checked(mixed $value, mixed $expected = true): string
    {
        if (is_array($expected)) {
            return in_array($value, $expected) ? 'checked' : '';
        }

        return $value == $expected ? 'checked' : '';
    }
}

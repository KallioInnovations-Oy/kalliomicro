<?php

declare(strict_types=1);

namespace KallioMicro\Http;

/**
 * ApiResponse - Unified response builder with declarative actions
 *
 * Provides a fluent interface for building standardized API responses
 * that can be handled uniformly by the frontend without eval().
 *
 * Response structure:
 * {
 *     "success": bool,
 *     "code": int (0-4: bypass, success, info, warning, error),
 *     "message": string,
 *     "actions": [...],
 *     "data": mixed
 * }
 */
class ApiResponse
{
    public const CODE_BYPASS = 0;
    public const CODE_SUCCESS = 1;
    public const CODE_INFO = 2;
    public const CODE_WARNING = 3;
    public const CODE_ERROR = 4;

    private bool $success = true;
    private int $code = self::CODE_SUCCESS;
    private string $message = '';

    /** @var array<int, array<string, mixed>> */
    private array $actions = [];

    /** @var mixed */
    private $data = null;

    private int $httpStatus = 200;

    /** @var array<string, string> */
    private array $headers = [];

    // Factory methods

    public static function success(string $message = ''): self
    {
        return (new self())
            ->setSuccess(true)
            ->setCode(self::CODE_SUCCESS)
            ->setMessage($message);
    }

    public static function info(string $message): self
    {
        return (new self())
            ->setSuccess(true)
            ->setCode(self::CODE_INFO)
            ->setMessage($message);
    }

    public static function warning(string $message): self
    {
        return (new self())
            ->setSuccess(true)
            ->setCode(self::CODE_WARNING)
            ->setMessage($message);
    }

    public static function error(string $message, int $httpStatus = 400): self
    {
        return (new self())
            ->setSuccess(false)
            ->setCode(self::CODE_ERROR)
            ->setMessage($message)
            ->setHttpStatus($httpStatus);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    public static function validationError(string $message, array $errors = []): self
    {
        return self::error($message, 422)->withData(['validation_errors' => $errors]);
    }

    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::error($message, 500);
    }

    // Setters (fluent)

    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    public function setCode(int $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function setHttpStatus(int $status): self
    {
        $this->httpStatus = $status;
        return $this;
    }

    /**
     * @param mixed $data
     */
    public function withData($data): self
    {
        $this->data = $data;
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    // Action builders - these define what the frontend should do

    /**
     * Show a flash message/toast notification
     */
    public function flash(string $message, ?int $level = null): self
    {
        $this->actions[] = [
            'type' => 'flash',
            'level' => $level ?? $this->code,
            'message' => $message,
        ];
        return $this;
    }

    /**
     * Replace content in a target element
     */
    public function replace(string $target, string $content): self
    {
        $this->actions[] = [
            'type' => 'replace',
            'target' => $target,
            'content' => $content,
        ];
        return $this;
    }

    /**
     * Append content to a target element
     */
    public function append(string $target, string $content): self
    {
        $this->actions[] = [
            'type' => 'append',
            'target' => $target,
            'content' => $content,
        ];
        return $this;
    }

    /**
     * Prepend content to a target element
     */
    public function prepend(string $target, string $content): self
    {
        $this->actions[] = [
            'type' => 'prepend',
            'target' => $target,
            'content' => $content,
        ];
        return $this;
    }

    /**
     * Remove an element from the DOM
     */
    public function remove(string $target): self
    {
        $this->actions[] = [
            'type' => 'remove',
            'target' => $target,
        ];
        return $this;
    }

    /**
     * Update a form field value
     */
    public function updateField(string $target, string $value): self
    {
        $this->actions[] = [
            'type' => 'update_field',
            'target' => $target,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * Update multiple form fields
     *
     * @param array<string, string> $fields target => value pairs
     */
    public function updateFields(array $fields): self
    {
        foreach ($fields as $target => $value) {
            $this->updateField($target, $value);
        }
        return $this;
    }

    /**
     * Redirect to a URL
     */
    public function redirect(string $url): self
    {
        $this->actions[] = [
            'type' => 'redirect',
            'url' => $url,
        ];
        return $this;
    }

    /**
     * Open a URL in a new tab
     */
    public function openTab(string $url): self
    {
        $this->actions[] = [
            'type' => 'open_tab',
            'url' => $url,
        ];
        return $this;
    }

    /**
     * Show content in a modal
     */
    public function modal(string $content, string $size = 'md', ?string $id = null): self
    {
        $this->actions[] = [
            'type' => 'modal',
            'content' => $content,
            'size' => $size, // sm, md, lg, xl, full
            'id' => $id,
        ];
        return $this;
    }

    /**
     * Show a nested modal (modal within modal)
     */
    public function nestedModal(string $content, string $size = 'md', int $level = 2): self
    {
        $this->actions[] = [
            'type' => 'nested_modal',
            'content' => $content,
            'size' => $size,
            'level' => $level, // 2 = second layer, 3 = third layer
        ];
        return $this;
    }

    /**
     * Close the current modal
     */
    public function closeModal(?int $level = null): self
    {
        $this->actions[] = [
            'type' => 'close_modal',
            'level' => $level, // null = close topmost, number = close specific level
        ];
        return $this;
    }

    /**
     * Close all modals
     */
    public function closeAllModals(): self
    {
        $this->actions[] = [
            'type' => 'close_all_modals',
        ];
        return $this;
    }

    /**
     * Refresh/redraw a DataTable
     */
    public function refreshTable(string $target, ?array $data = null): self
    {
        $action = [
            'type' => 'refresh_table',
            'target' => $target,
        ];
        if ($data !== null) {
            $action['data'] = $data;
        }
        return $this->addAction($action);
    }

    /**
     * Add rows to a DataTable
     */
    public function addTableRows(string $target, string $rows): self
    {
        $this->actions[] = [
            'type' => 'add_table_rows',
            'target' => $target,
            'rows' => $rows,
        ];
        return $this;
    }

    /**
     * Clear form fields
     */
    public function clearForm(string $target): self
    {
        $this->actions[] = [
            'type' => 'clear_form',
            'target' => $target,
        ];
        return $this;
    }

    /**
     * Reset form to initial state
     */
    public function resetForm(string $target): self
    {
        $this->actions[] = [
            'type' => 'reset_form',
            'target' => $target,
        ];
        return $this;
    }

    /**
     * Enable/disable form elements
     */
    public function toggleDisabled(string $target, bool $disabled): self
    {
        $this->actions[] = [
            'type' => 'toggle_disabled',
            'target' => $target,
            'disabled' => $disabled,
        ];
        return $this;
    }

    /**
     * Show/hide an element
     */
    public function toggleVisibility(string $target, bool $visible): self
    {
        $this->actions[] = [
            'type' => 'toggle_visibility',
            'target' => $target,
            'visible' => $visible,
        ];
        return $this;
    }

    /**
     * Add/remove CSS class
     */
    public function toggleClass(string $target, string $className, bool $add): self
    {
        $this->actions[] = [
            'type' => 'toggle_class',
            'target' => $target,
            'class' => $className,
            'add' => $add,
        ];
        return $this;
    }

    /**
     * Scroll to an element
     */
    public function scrollTo(string $target): self
    {
        $this->actions[] = [
            'type' => 'scroll_to',
            'target' => $target,
        ];
        return $this;
    }

    /**
     * Focus on an element
     */
    public function focus(string $target): self
    {
        $this->actions[] = [
            'type' => 'focus',
            'target' => $target,
        ];
        return $this;
    }

    /**
     * Trigger a custom event
     */
    public function triggerEvent(string $target, string $event, array $detail = []): self
    {
        $this->actions[] = [
            'type' => 'trigger_event',
            'target' => $target,
            'event' => $event,
            'detail' => $detail,
        ];
        return $this;
    }

    /**
     * Download a file
     */
    public function download(string $url, ?string $filename = null): self
    {
        $this->actions[] = [
            'type' => 'download',
            'url' => $url,
            'filename' => $filename,
        ];
        return $this;
    }

    /**
     * Show a confirmation dialog before proceeding
     */
    public function confirm(string $message, array $onConfirm): self
    {
        $this->actions[] = [
            'type' => 'confirm',
            'message' => $message,
            'on_confirm' => $onConfirm, // Actions to execute on confirm
        ];
        return $this;
    }

    /**
     * Add a raw action (for extensibility)
     *
     * @param array<string, mixed> $action
     */
    public function addAction(array $action): self
    {
        $this->actions[] = $action;
        return $this;
    }

    // Output methods

    /**
     * Build the response array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if (!empty($this->actions)) {
            $response['actions'] = $this->actions;
        }

        if ($this->data !== null) {
            $response['data'] = $this->data;
        }

        return $response;
    }

    /**
     * Convert to JSON string
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Convert to HTTP Response object
     */
    public function toResponse(): Response
    {
        $response = Response::json($this->toArray(), $this->httpStatus);

        foreach ($this->headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Send the response directly
     */
    public function send(): void
    {
        $this->toResponse()->send();
    }

    // Getters

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

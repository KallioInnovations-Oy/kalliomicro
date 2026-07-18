<?php

declare(strict_types=1);

namespace KallioMicro\Auth;

/**
 * AuthResult - Authentication result object
 */
class AuthResult
{
    private bool $success;
    private string $message;

    /** @var array<string, mixed>|null */
    private ?array $user;

    private ?string $redirectUrl;

    private function __construct(
        bool $success,
        string $message = '',
        ?array $user = null,
        ?string $redirectUrl = null
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->user = $user;
        $this->redirectUrl = $redirectUrl;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function success(array $user, string $message = ''): self
    {
        return new self(true, $message, $user);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public static function redirect(string $url): self
    {
        return new self(false, '', null, $url);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function needsRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}

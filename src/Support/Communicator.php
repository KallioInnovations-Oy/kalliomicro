<?php

declare(strict_types=1);

namespace KallioMicro\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Communicator - Unified notification service for email and webhooks
 *
 * Supports:
 * - SMTP email via PHPMailer
 * - Teams webhooks (MessageCard format)
 * - Slack webhooks
 * - Generic webhooks
 *
 * All methods return a Result object for consistent error handling.
 */
class Communicator
{
    /**
     * Last-resort floor for the email settings, guaranteeing every key
     * sendEmail() reads exists even with no config file and no caller config.
     * Deployment values belong in config/notifications.php, not here.
     */
    private const EMAIL_FALLBACKS = [
        'host' => 'localhost',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_address' => 'noreply@example.com',
        'from_name' => 'KallioMicro',
    ];

    private ?Logger $logger;
    private array $emailConfig;
    private array $webhookConfig;

    public function __construct(?Logger $logger = null, array $emailConfig = [], array $webhookConfig = [])
    {
        $this->logger = $logger;

        // Merge, never swap. sendEmail() reads port/encryption/from_address
        // unguarded, so a caller passing only ['host' => ...] used to drop the
        // other six keys; the resulting "Undefined array key" warnings become
        // ErrorExceptions once an ExceptionHandler is registered, and those
        // escape the PHPMailerException-only catch below — breaking this
        // class's contract that every method returns a CommunicatorResult.
        $this->emailConfig = array_merge(
            self::EMAIL_FALLBACKS,
            $this->configured('notifications.email'),
            $emailConfig
        );

        // Same shape, milder symptom (webhook reads are ?? guarded): a caller
        // passing only ['teams' => ...] used to lose the configured Slack URL.
        $this->webhookConfig = array_merge($this->configured('notifications.webhooks'), $webhookConfig);
    }

    /**
     * Read a config section, tolerating the absence of a booted Application —
     * Communicator is usable from a bare script (`new Communicator($logger)`),
     * where config() would fatal on a null container. No container simply means
     * no configured values, and the fallbacks above still apply.
     *
     * @return array<string, mixed>
     */
    private function configured(string $key): array
    {
        if (!function_exists('config') || \KallioMicro\Core\Application::getInstance() === null) {
            return [];
        }

        $values = config($key, []);

        return is_array($values) ? $values : [];
    }

    /**
     * Send an email
     *
     * @param array{
     *     to: string|array<string>,
     *     subject: string,
     *     body: string,
     *     from?: string,
     *     from_name?: string,
     *     cc?: string|array<string>,
     *     bcc?: string|array<string>,
     *     attachments?: array<string>,
     *     is_html?: bool
     * } $message
     */
    public function sendEmail(array $message): CommunicatorResult
    {
        try {
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->emailConfig['host'];
            $mail->Port = $this->emailConfig['port'];
            $mail->CharSet = 'UTF-8';

            // Authentication
            if (!empty($this->emailConfig['username'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $this->emailConfig['username'];
                $mail->Password = $this->emailConfig['password'];
            }

            // Encryption
            if ($this->emailConfig['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($this->emailConfig['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            // From
            $fromAddress = $message['from'] ?? $this->emailConfig['from_address'];
            $fromName = $message['from_name'] ?? $this->emailConfig['from_name'];
            $mail->setFrom($fromAddress, $fromName);

            // To
            $to = is_array($message['to']) ? $message['to'] : [$message['to']];
            foreach ($to as $recipient) {
                $mail->addAddress($recipient);
            }

            // CC
            if (!empty($message['cc'])) {
                $cc = is_array($message['cc']) ? $message['cc'] : [$message['cc']];
                foreach ($cc as $recipient) {
                    $mail->addCC($recipient);
                }
            }

            // BCC
            if (!empty($message['bcc'])) {
                $bcc = is_array($message['bcc']) ? $message['bcc'] : [$message['bcc']];
                foreach ($bcc as $recipient) {
                    $mail->addBCC($recipient);
                }
            }

            // Attachments
            if (!empty($message['attachments'])) {
                foreach ($message['attachments'] as $attachment) {
                    if (is_string($attachment) && file_exists($attachment)) {
                        $mail->addAttachment($attachment);
                    }
                }
            }

            // Content
            $isHtml = $message['is_html'] ?? true;
            $mail->isHTML($isHtml);
            $mail->Subject = $message['subject'];
            $mail->Body = $message['body'];

            if ($isHtml) {
                // Create plain text version from HTML
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message['body']));
            }

            // Send
            $mail->send();

            $this->log('success', 'Email sent successfully', [
                'to' => $message['to'],
                'subject' => $message['subject'],
            ]);

            return CommunicatorResult::success('Email sent successfully');

        } catch (PHPMailerException $e) {
            $this->log('error', 'Email send failed: ' . $e->getMessage(), [
                'to' => $message['to'] ?? 'unknown',
                'subject' => $message['subject'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return CommunicatorResult::failure('Email send failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a Microsoft Teams notification
     */
    public function sendTeamsNotification(
        string $title,
        string $text,
        ?string $webhookUrl = null,
        string $themeColor = '0078D7'
    ): CommunicatorResult {
        $url = $webhookUrl ?? $this->webhookConfig['teams'] ?? null;

        if (empty($url)) {
            return CommunicatorResult::failure('Teams webhook URL not configured');
        }

        // Teams MessageCard format
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'summary' => $title,
            'themeColor' => $themeColor,
            'title' => $title,
            'text' => $text,
        ];

        return $this->sendWebhook($url, $payload, 'Teams');
    }

    /**
     * Send a Slack notification
     */
    public function sendSlackNotification(
        string $text,
        ?string $channel = null,
        ?string $webhookUrl = null,
        ?string $username = null,
        ?string $iconEmoji = null
    ): CommunicatorResult {
        $url = $webhookUrl ?? $this->webhookConfig['slack'] ?? null;

        if (empty($url)) {
            return CommunicatorResult::failure('Slack webhook URL not configured');
        }

        $payload = [
            'text' => $text,
        ];

        if ($channel !== null) {
            $payload['channel'] = $channel;
        }

        if ($username !== null) {
            $payload['username'] = $username;
        }

        if ($iconEmoji !== null) {
            $payload['icon_emoji'] = $iconEmoji;
        }

        return $this->sendWebhook($url, $payload, 'Slack');
    }

    /**
     * Send a generic webhook
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function sendWebhook(
        string $url,
        array $payload,
        string $name = 'Webhook',
        array $headers = []
    ): CommunicatorResult {
        try {
            $ch = curl_init($url);

            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize cURL');
            }

            $jsonPayload = json_encode($payload);
            if ($jsonPayload === false) {
                throw new \RuntimeException('Failed to encode payload as JSON');
            }

            $defaultHeaders = [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload),
            ];

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($error) {
                throw new \RuntimeException("cURL error: {$error}");
            }

            if ($httpCode >= 400) {
                throw new \RuntimeException("HTTP error {$httpCode}: {$response}");
            }

            $this->log('success', "{$name} notification sent successfully", [
                'url' => $this->maskUrl($url),
                'http_code' => $httpCode,
            ]);

            return CommunicatorResult::success("{$name} notification sent successfully");

        } catch (\Throwable $e) {
            $this->log('error', "{$name} notification failed: " . $e->getMessage(), [
                'url' => $this->maskUrl($url),
                'error' => $e->getMessage(),
            ]);

            return CommunicatorResult::failure("{$name} notification failed: " . $e->getMessage());
        }
    }

    /**
     * Register a webhook URL for a channel
     */
    public function registerWebhook(string $channel, string $url): self
    {
        $this->webhookConfig[$channel] = $url;
        return $this;
    }

    /**
     * Get a webhook URL by channel name
     */
    public function getWebhookUrl(string $channel): ?string
    {
        return $this->webhookConfig[$channel] ?? null;
    }

    /**
     * Mask sensitive parts of URL for logging
     */
    private function maskUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return '***';
        }

        $masked = ($parsed['scheme'] ?? 'https') . '://';
        $masked .= $parsed['host'] ?? 'unknown';
        $masked .= '/***';

        return $masked;
    }

    /**
     * Log a message if logger is available
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $context['source'] = 'Communicator';

        match ($level) {
            'success' => $this->logger->success($message, $context),
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}

/**
 * Result object for Communicator operations
 */
class CommunicatorResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $data = []
    ) {}

    public static function success(string $message, array $data = []): self
    {
        return new self(true, $message, $data);
    }

    public static function failure(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }
}

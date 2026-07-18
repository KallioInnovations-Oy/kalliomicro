<?php

declare(strict_types=1);

namespace Tests\Support;

use ErrorException;
use KallioMicro\Support\Communicator;
use KallioMicro\Support\CommunicatorResult;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The constructor swapped the whole defaults array for the caller's config
 * instead of merging over it, so `new Communicator(null, ['host' => ...])`
 * left sendEmail() reading six keys that no longer existed. Those "Undefined
 * array key" warnings become ErrorExceptions as soon as an ExceptionHandler is
 * registered, and sendEmail() only catches PHPMailerException — so they escaped
 * the method entirely, breaking the documented "every method returns a
 * CommunicatorResult" contract.
 */
class CommunicatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function emailConfigOf(Communicator $communicator): array
    {
        $property = new ReflectionProperty(Communicator::class, 'emailConfig');
        $property->setAccessible(true);

        return $property->getValue($communicator);
    }

    public function testPartialEmailConfigKeepsTheRemainingDefaults(): void
    {
        $config = $this->emailConfigOf(new Communicator(null, ['host' => 'smtp.example.com']));

        $this->assertSame('smtp.example.com', $config['host']);

        foreach (['port', 'username', 'password', 'encryption', 'from_address', 'from_name'] as $key) {
            $this->assertArrayHasKey($key, $config, "partial config dropped '{$key}'");
        }
    }

    public function testCallerConfigWinsOverTheDefaults(): void
    {
        $config = $this->emailConfigOf(new Communicator(null, ['port' => 2525, 'encryption' => 'ssl']));

        $this->assertSame(2525, $config['port']);
        $this->assertSame('ssl', $config['encryption']);
    }

    public function testPartialWebhookConfigKeepsTheOtherChannel(): void
    {
        $communicator = new Communicator(null, [], ['teams' => 'https://teams.example/hook']);

        $this->assertSame('https://teams.example/hook', $communicator->getWebhookUrl('teams'));
        $this->assertSame(
            'Slack webhook URL not configured',
            $communicator->sendSlackNotification('hello')->message,
            'an unset channel must still fail as a Result, not by missing key'
        );
    }

    /**
     * The regression itself: with warnings promoted the way ExceptionHandler
     * promotes them, sendEmail() must still return rather than raise. The send
     * is expected to fail — nothing listens on the address — which is exactly
     * the path that must produce a CommunicatorResult.
     */
    public function testSendEmailWithPartialConfigReturnsAResultInsteadOfRaising(): void
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $this->markTestSkipped('PHPMailer is not installed');
        }

        // Only undefined-key warnings are promoted: PHPMailer installs its own
        // handler around the socket calls, and promoting its diagnostics would
        // fail this test for an unrelated reason.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (str_contains($message, 'Undefined array key')) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });

        try {
            $result = (new Communicator(null, ['host' => '127.0.0.1', 'port' => 1]))->sendEmail([
                'to' => 'someone@example.com',
                'subject' => 'Partial config',
                'body' => 'body',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(CommunicatorResult::class, $result);
        $this->assertTrue($result->isFailure());
    }
}

<?php

declare(strict_types=1);

namespace PHPMailer\PHPMailer {
    if (!class_exists('PHPMailer', false)) {
        class PHPMailer {}
    }
}

namespace {

require_once __DIR__.'/bootstrap.php';

$GLOBALS['__captured_mail'] = [];
$GLOBALS['__captured_smtp'] = [];

function system_mail_send($to, $subject, $body, $headers) {
    $GLOBALS['__captured_mail'][] = compact('to', 'subject', 'body', 'headers');
    return true;
}

function smtp_socket_send(array $config, string $fromEmail, string $fromName, string $replyToEmail, string $toEmail, string $subject, string $bodyHtml): array {
    $GLOBALS['__captured_smtp'][] = compact('config', 'fromEmail', 'fromName', 'replyToEmail', 'toEmail', 'subject');
    return [true, 'mocked'];
}

require_once __DIR__.'/../lib/utils.php';

final class EmailTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__test_settings'] = [];
        $GLOBALS['__captured_mail'] = [];
        $GLOBALS['__captured_smtp'] = [];
    }

    public function testSendEmailFallsBackToMail(): void
    {
        $result = send_email('client@example.com', 'Teste', '<p>Corpo</p>');
        $this->assertTrue($result);
        $this->assertEquals(1, count($GLOBALS['__captured_mail']));
    }

    public function testSendEmailUsesSmtpWhenConfigured(): void
    {
        setting_set('smtp_config', [
            'host' => 'smtp.example.com',
            'port' => '587',
            'user' => 'mailer@example.com',
            'pass' => 'secret',
            'secure' => 'tls',
            'from_name' => 'Loja Teste',
            'from_email' => 'mailer@example.com',
        ]);
        $result = send_email('client@example.com', 'Pedido', '<p>Detalhes</p>');
        $this->assertTrue($result);
        $this->assertEquals(1, count($GLOBALS['__captured_smtp']));
        $this->assertEquals(0, count($GLOBALS['__captured_mail']));
    }
}

}

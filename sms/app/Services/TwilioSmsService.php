<?php
namespace App\Services;

use Twilio\Rest\Client;

class TwilioSmsService
{
    private Client $client;
    private string $from;

    public function __construct(string $sid, string $token, string $from)
    {
        // Client do Twilio; requer o pacote twilio/sdk instalado via Composer.
        $this->client = new Client($sid, $token);
        $this->from = $from;
    }

    /**
     * Envia SMS retornando array com sucesso/erro e id da mensagem.
     */
    public function sendSms(string $to, string $message): array
    {
        try {
            $sms = $this->client->messages->create($to, [
                'from' => $this->from,
                'body' => $message,
            ]);

            return [
                'success' => true,
                'message_id' => $sms->sid ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}

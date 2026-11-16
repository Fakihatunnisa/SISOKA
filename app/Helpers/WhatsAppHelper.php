<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WhatsAppHelper
{
    public static function kirimPesanWhatsApp($phone, $message): bool
    {
        if (!config('services.whatsapp.token')) {
            return false;
        }
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(15)
                ->post(config('services.whatsapp.url') ?: 'https://wanesia.com/api/send_message', [
                    'token' => config('services.whatsapp.token'),
                    'number' => $phone,
                    'message' => $message,
                ]);

            // Fail closed on transport errors, provider errors and unknown responses.
            return $response->successful()
                && in_array($response->json('status'), [true, 'success'], true);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            return false;
        }
    }

}

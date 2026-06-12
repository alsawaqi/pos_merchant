<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * P-G9 — a deliberately SLIM port of pos_admin's ScalefusionService.
 *
 * The merchant portal gets a restricted MDM surface for its own
 * devices: live telemetry + the four safe actions (restart, shutdown,
 * on-screen message, beep). The whitelist is structural — the sharp
 * admin-only verbs (lock / unlock / mark-lost / wipe / factory reset /
 * delete) simply do not exist on this class, and there is no generic
 * action passthrough, so no request shape can reach them from this app.
 *
 * Joined to scalefusion purely by pos_devices.kiosk_id; the HTTP
 * encoding quirks (v1 form bodies, rawurlencoded device_ids[] query)
 * are byte-identical to the admin client. No summary-map caching here:
 * the merchant only ever looks at one device at a time.
 */
final class ScalefusionClient
{
    /**
     * Full live device detail from the v3 API: the rich telemetry the
     * Live dialog renders (RAM, storage, CPU/thermals, battery, OS,
     * network, management, location). Raw payload untouched so the UI
     * can read any field.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function getDevice(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()->get($this->v3('/devices/'.$deviceId.'.json')),
        );
    }

    /**
     * Reboot a device (v1 PUT, empty JSON body).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function reboot(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put($this->v1('/devices/'.$deviceId.'/reboot.json'), []),
        );
    }

    /**
     * Power a device off. Scalefusion exposes shutdown only through
     * the generic actions endpoint — the action_type is HARD-CODED
     * here so this method is the only shape of that endpoint this
     * app can produce.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function shutdown(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->queryArrayUrl('/devices/actions.json', 'device_ids', [$deviceId]), [
                    'body' => $this->formBody([
                        'action_type' => 'shutdown',
                        'wipe_sd_card' => false,
                    ]),
                ]),
        );
    }

    /**
     * Ring a device's locate alarm — the "beep" (v1 POST, empty JSON
     * body).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendAlarm(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->v1('/devices/'.$deviceId.'/send_alarm.json'), []),
        );
    }

    /**
     * Broadcast an on-screen MDM message to a device. Distinct from
     * P-G6 in-app messaging: this renders at the OS level via the MDM
     * agent, even when the POS app is closed.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function broadcastMessage(int|string $deviceId, string $senderName, string $messageBody, bool $keepRinging = true, bool $showAsDialog = true): array
    {
        $body = $this->formBody([
            'device_ids' => [$deviceId],
            'sender_name' => trim($senderName),
            'message_body' => trim($messageBody),
            'keep_ringing' => $keepRinging,
            'show_as_dialog' => $showAsDialog,
        ]);

        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/broadcast_message.json'), ['body' => $body]),
        );
    }

    // --- shared helpers (byte-identical to the admin client) ---

    /**
     * Run a Scalefusion HTTP call + normalise the outcome. Connection
     * failures degrade to ok=false/status=503 instead of throwing, so
     * callers never need their own try/catch.
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $request
     * @return array{ok: bool, status: int, data: mixed}
     */
    private function callScalefusion(callable $request): array
    {
        if (! config('services.scalefusion.token')) {
            return ['ok' => false, 'status' => 0, 'data' => ['message' => 'Scalefusion is not configured.']];
        }

        try {
            $response = $request();

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json() ?? $response->body(),
            ];
        } catch (ConnectionException $e) {
            Log::warning('Scalefusion unreachable', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 503, 'data' => ['message' => 'Scalefusion unreachable']];
        } catch (\Throwable $e) {
            Log::warning('Scalefusion request failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => ['message' => 'Scalefusion request failed']];
        }
    }

    private function scalefusionClient(): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = (int) config('services.scalefusion.http_timeout_seconds', 12);

        return Http::timeout($timeout)
            ->retry(2, 250, null, false)
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Token '.config('services.scalefusion.token'),
            ]);
    }

    private function v3(string $path): string
    {
        return rtrim((string) config('services.scalefusion.base_v3'), '/').$path;
    }

    private function v1(string $path): string
    {
        return rtrim((string) config('services.scalefusion.base_v1'), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function formBody(array $fields): string
    {
        $pairs = [];

        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = [$key.'[]', $item];
                }

                continue;
            }

            $pairs[] = [$key, $value];
        }

        return collect($pairs)
            ->map(fn (array $field) => rawurlencode($field[0]).'='.rawurlencode($this->formValue($field[1])))
            ->implode('&');
    }

    private function formValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param  list<int|string>  $values
     */
    private function queryArrayUrl(string $path, string $key, array $values): string
    {
        $query = collect($values)
            ->map(fn ($value) => rawurlencode($key.'[]').'='.rawurlencode((string) $value))
            ->implode('&');

        return $query === '' ? $this->v1($path) : $this->v1($path).'?'.$query;
    }
}

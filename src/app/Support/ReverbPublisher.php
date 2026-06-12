<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * P-G6 — best-effort live nudges to POS devices over the Reverb server
 * pos_api runs, WITHOUT pulling broadcasting machinery into this app: a
 * hand-rolled client for Reverb's pusher-compatible REST events API
 * (POST /apps/{id}/events with the HMAC query signature).
 *
 * Devices subscribe to private-branch.{id} and run a debounced config
 * delta sync on ANY event that lands there — so publishing a
 * 'message.created' nudge makes every till at the branch pull the new
 * announcement within seconds. Delivery is ADVISORY ONLY (the
 * SyncEventDispatcher policy): unset config = silent no-op, every error
 * is swallowed, and offline devices catch up through the regular config
 * sync regardless.
 *
 * Config: services.reverb.{app_id,key,secret,host,port,scheme} — the
 * SAME app credentials pos_api uses, host = the reverb container on the
 * shared charity_net network (prod: re-run the deploy one-shot after
 * setting them; config is cached there).
 */
final class ReverbPublisher
{
    /** Pusher's events API accepts at most 100 channels per call. */
    private const CHANNELS_PER_CALL = 90;

    /**
     * Publish [$event] to the private branch channels of [$branchIds].
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $data
     */
    public function publishToBranches(array $branchIds, string $event, array $data): void
    {
        $cfg = (array) config('services.reverb', []);
        $appId = (string) ($cfg['app_id'] ?? '');
        $key = (string) ($cfg['key'] ?? '');
        $secret = (string) ($cfg['secret'] ?? '');
        $host = (string) ($cfg['host'] ?? '');
        if ($appId === '' || $key === '' || $secret === '' || $host === '') {
            return; // Not wired up — devices heal via config sync.
        }

        $channels = array_values(array_unique(array_map(
            static fn (int $id): string => 'private-branch.'.$id,
            array_map(intval(...), $branchIds),
        )));
        if ($channels === []) {
            return;
        }

        $scheme = (string) ($cfg['scheme'] ?? 'http');
        $port = (int) ($cfg['port'] ?? 8080);
        $path = '/apps/'.$appId.'/events';

        try {
            foreach (array_chunk($channels, self::CHANNELS_PER_CALL) as $chunk) {
                $body = json_encode([
                    'name' => $event,
                    'channels' => $chunk,
                    'data' => json_encode($data, JSON_THROW_ON_ERROR),
                ], JSON_THROW_ON_ERROR);

                // Pusher REST auth: HMAC-SHA256 over
                // "POST\n{path}\n{sorted query string}".
                $query = [
                    'auth_key' => $key,
                    'auth_timestamp' => (string) now()->getTimestamp(),
                    'auth_version' => '1.0',
                    'body_md5' => md5($body),
                ];
                ksort($query);
                $queryString = http_build_query($query);
                $signature = hash_hmac('sha256', "POST\n{$path}\n{$queryString}", $secret);

                Http::timeout(2)
                    ->withBody($body, 'application/json')
                    ->post("{$scheme}://{$host}:{$port}{$path}?{$queryString}&auth_signature={$signature}");
            }
        } catch (Throwable) {
            // Advisory only — never fail the committed domain write.
        }
    }
}

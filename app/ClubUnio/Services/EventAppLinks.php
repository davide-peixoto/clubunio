<?php

namespace App\ClubUnio\Services;

class EventAppLinks
{
    public function profiles(string $host): array
    {
        // Hostnames contain dots; use direct keys rather than Laravel dot notation.
        $ids = ((array) config('clubunio-event-links.host_profiles'))[strtolower($host)] ?? null;
        abort_unless(is_array($ids), 404);
        return array_values(array_filter(array_map(fn ($id) => is_string($id) ? config('clubunio-event-links.profiles.'.$id) : null, $ids), 'is_array'));
    }

    public function apple(string $host): array
    {
        $profiles = $this->profiles($host);
        $team = (string) config('clubunio-event-links.apple_team');
        $details = [];
        if (preg_match('/\A[A-Z0-9]{10}\z/D', $team)) {
            foreach ($profiles as $profile) {
                $details[] = ['appIDs' => [$team.'.'.$profile['ios']],
                    'components' => [['/' => '/event-check-in/*']]];
            }
        }
        return ['applinks' => ['apps' => [], 'details' => $details]];
    }

    public function android(string $host): array
    {
        $result = [];
        foreach ($this->profiles($host) as $profile) {
            $key = $profile['android'] === 'com.my.clubunio.core' ? 'core' : 'svs_test';
            $fingerprints = [];
            foreach ((array) config('clubunio-event-links.android_fingerprints.'.$key, []) as $fingerprint) {
                $hex = strtoupper(str_replace(':', '', trim((string) $fingerprint)));
                if (preg_match('/\A[0-9A-F]{64}\z/D', $hex)) $fingerprints[] = implode(':', str_split($hex, 2));
            }
            if ($fingerprints) $result[] = ['relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => ['namespace' => 'android_app', 'package_name' => $profile['android'],
                    'sha256_cert_fingerprints' => array_values(array_unique($fingerprints))]];
        }
        return $result;
    }
}

<?php

$profiles = [
    'core' => ['ios' => 'com.my.clubunio.core', 'android' => 'com.my.clubunio.core', 'scheme' => 'com-my-clubunio-core-event', 'label' => 'ClubUnio'],
    'svs_test' => ['ios' => 'com.my.sportvereinSissach.test', 'android' => 'com.my.sportvereinsissach.test', 'scheme' => 'com-my-sportvereinsissach-test-event', 'label' => 'My SV Sissach Test'],
];
$hosts = [
    'dev.clubunio.ch' => ['core'], 'stage.clubunio.com' => ['core'],
    'dev-clubunio.svsissach.ch' => ['svs_test', 'core'],
    'stage-clubunio.svsissach.ch' => ['svs_test', 'core'],
];
$custom = json_decode((string) env('CLUBUNIO_EVENT_LINK_HOSTS', ''), true);
if (is_array($custom)) $hosts = $custom;
$schemes = [];
foreach ($hosts as $host => $ids) {
    if (! is_string($host) || ! preg_match('/\A[a-z0-9.-]+\z/D', $host) || ! is_array($ids)) continue;
    foreach ($ids as $id) if (isset($profiles[$id])) $schemes[$host][] = array_intersect_key($profiles[$id], array_flip(['scheme', 'label']));
}
return [
    'profiles' => $profiles, 'host_profiles' => $hosts, 'app_schemes' => $schemes,
    'apple_team' => env('CLUBUNIO_APP_LINKS_APPLE_TEAM_ID', ''),
    'android_fingerprints' => [
        'core' => array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_APP_LINKS_ANDROID_CORE_SHA256', '')))),
        'svs_test' => array_filter(array_map('trim', explode(',', (string) env('CLUBUNIO_APP_LINKS_ANDROID_SVS_SHA256', '')))),
    ],
];

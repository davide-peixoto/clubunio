<?php
declare(strict_types=1);

const CAPTCHA_REQUIRED = false;
const CAPTCHA_SITE_KEY = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$locale = ($_POST['locale'] ?? '') === 'en' ? 'en' : 'de';
$finish = static function (string $status) use ($locale): never {
    header("Location: /$locale/?inquiry=$status#inquiry", true, 303);
    exit;
};

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 10000
    || (isset($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'], ['https://clubunio.com', 'https://www.clubunio.com'], true))) {
    $finish('invalid');
}

$fields = [];
foreach (['club' => 120, 'name' => 120, 'email' => 254, 'message' => 1200, 'members' => 7, 'plan' => 16, 'kind' => 8] as $key => $limit) {
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || strlen($value) > 4 * $limit || preg_match('//u', $value) !== 1
        || preg_match_all('/./us', $value) > $limit) {
        $finish('invalid');
    }
    $fields[$key] = trim($value);
}
$interests = $_POST['interests'] ?? [];
$allowed = require __DIR__.'/inquiry-options.php';
if (!$fields['club'] || !$fields['name'] || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)
    || !in_array($fields['kind'], ['demo', 'quote', 'both'], true)
    || !in_array($fields['plan'], ['', 'start', 'verein', 'club', 'large'], true)
    || ($fields['members'] !== '' && !filter_var($fields['members'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]))
    || !is_array($interests) || count($interests) > 7) {
    $finish('invalid');
}
foreach ($interests as $interest) {
    if (!is_string($interest) || !in_array($interest, $allowed[$locale], true)) {
        $finish('invalid');
    }
}

$bucket = sys_get_temp_dir().'/clubunio-inquiry-'.hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '').':'.intdiv(time(), 60));
$rate = @fopen($bucket, 'c+');
if (!$rate || !flock($rate, LOCK_EX)) {
    $finish('failure');
}
$count = (int) stream_get_contents($rate);
if ($count >= 5) {
    fclose($rate);
    $finish('failure');
}
rewind($rate);
ftruncate($rate, 0);
fwrite($rate, (string) ($count + 1));
fclose($rate);

if (CAPTCHA_REQUIRED) {
    $apiKey = getenv('CLUBUNIO_RECAPTCHA_API_KEY');
    $project = getenv('CLUBUNIO_RECAPTCHA_PROJECT_ID');
    $token = $_POST['captcha_token'] ?? '';
    if (!$apiKey || !$project || !CAPTCHA_SITE_KEY || !is_string($token) || $token === ''
        || strlen($token) > 4096 || !preg_match('/^[A-Za-z0-9_-]+$/D', $token) || !function_exists('curl_init')) {
        $finish('captcha');
    }

    $endpoint = 'https://recaptchaenterprise.googleapis.com/v1/projects/'.rawurlencode($project).'/assessments';
    $verification = curl_init($endpoint.'?'.http_build_query(['key' => $apiKey]));
    curl_setopt_array($verification, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['event' => [
            'token' => $token,
            'siteKey' => CAPTCHA_SITE_KEY,
            'expectedAction' => 'inquiry',
        ]], JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $response = curl_exec($verification);
    $proof = is_string($response) ? json_decode($response, true) : null;
    $properties = $proof['tokenProperties'] ?? [];
    $score = $proof['riskAnalysis']['score'] ?? null;
    if (curl_getinfo($verification, CURLINFO_RESPONSE_CODE) !== 200
        || ($properties['valid'] ?? false) !== true || ($properties['action'] ?? '') !== 'inquiry'
        || !in_array($properties['hostname'] ?? '', ['clubunio.com', 'www.clubunio.com'], true)
        || !is_numeric($score) || $score < 0.5) {
        $finish('captcha');
    }
}

$host = getenv('CLUBUNIO_MARKETING_SMTP_HOST');
$from = getenv('CLUBUNIO_MARKETING_MAIL_FROM');
$port = (int) (getenv('CLUBUNIO_MARKETING_SMTP_PORT') ?: 465);
$user = getenv('CLUBUNIO_MARKETING_SMTP_USERNAME');
$password = getenv('CLUBUNIO_MARKETING_SMTP_PASSWORD');
if (!$host || !preg_match('/^[a-z0-9.-]+$/iD', $host) || !filter_var($from, FILTER_VALIDATE_EMAIL)
    || !$user || !$password || $port < 1 || $port > 65535 || !function_exists('curl_init')) {
    $finish('failure');
}

$recipient = 'info@clubunio.com';
$body = "Language: $locale\r\n";
foreach ($fields as $key => $value) {
    $body .= "$key: $value\r\n";
}
$body .= 'interests: '.implode(', ', $interests)."\r\n";

$stream = fopen('php://temp', 'w+');
fwrite($stream, "From: <$from>\r\nTo: <$recipient>\r\nReply-To: <{$fields['email']}>\r\n"
    ."Subject: ClubUnio website enquiry\r\nMIME-Version: 1.0\r\n"
    ."Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    .chunk_split(base64_encode($body)));
$size = ftell($stream);
rewind($stream);

$smtp = curl_init("smtps://$host:$port");
curl_setopt_array($smtp, [
    CURLOPT_USERNAME => $user,
    CURLOPT_PASSWORD => $password,
    CURLOPT_MAIL_FROM => $from,
    CURLOPT_MAIL_RCPT => [$recipient],
    CURLOPT_UPLOAD => true,
    CURLOPT_INFILE => $stream,
    CURLOPT_INFILESIZE => $size,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_RETURNTRANSFER => true,
]);
$sent = curl_exec($smtp) !== false;
fclose($stream);
$finish($sent ? 'success' : 'failure');

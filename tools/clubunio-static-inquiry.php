<?php
declare(strict_types=1);

const CAPTCHA_REQUIRED = false;

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

$captchaSecret = getenv('CLUBUNIO_RECAPTCHA_SECRET');
if (CAPTCHA_REQUIRED || ($captchaSecret !== false && $captchaSecret !== '')) {
    $token = $_POST['captcha_token'] ?? '';
    if (!$captchaSecret || !is_string($token) || $token === '' || strlen($token) > 4096 || !function_exists('curl_init')) {
        $finish('captcha');
    }

    $verification = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($verification, [
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $captchaSecret, 'response' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $response = curl_exec($verification);
    $proof = is_string($response) ? json_decode($response, true) : null;
    if (($proof['success'] ?? false) !== true
        || ($proof['action'] ?? '') !== 'inquiry'
        || !in_array($proof['hostname'] ?? '', ['clubunio.com', 'www.clubunio.com'], true)
        || !is_numeric($proof['score'] ?? null) || $proof['score'] < 0.5) {
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

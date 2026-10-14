<?php

// One-time local credential transfer; never prints or modifies source values.
require dirname(__DIR__).'/vendor/autoload.php';
$target = dirname(__DIR__).'/.env.clubora-integrations.local';
if (is_file($target)) {
    fwrite(STDERR, "Integration configuration already exists; preserved.\n");
    exit(1);
}
$source = dirname(__DIR__, 2).'/application/.env.prod.local';
$v = \Dotenv\Dotenv::parse(file_get_contents($source));
foreach (['SMTP_SERVER', 'SMTP_SERVER_USERNAME', 'SMTP_SERVER_PASSWORD', 'AZURE_BLOB_ACCOUNT_NAME', 'AZURE_BLOB_ACCOUNT_KEY'] as $key) {
    if (empty($v[$key])) {
        throw new RuntimeException('Required source parameter missing: '.$key);
    }
}
$values = ['TEST_MAIL_ENABLED' => '1', 'SMTP_HOST' => $v['SMTP_SERVER'], 'SMTP_PORT' => $v['SMTP_SERVER_PORT'] ?? '587',
    'SMTP_SCHEME' => ($v['SMTP_SECURE_OPTION'] ?? '') === 'ssl' ? 'smtps' : 'smtp',
    'SMTP_USERNAME' => $v['SMTP_SERVER_USERNAME'], 'SMTP_PASSWORD' => $v['SMTP_SERVER_PASSWORD'],
    'SMTP_FROM' => $v['SENDER_EMAIL'] ?? 'noreply@svsissach.ch',
    'AZURE_READ_ENABLED' => '1', 'AZURE_ACCOUNT' => $v['AZURE_BLOB_ACCOUNT_NAME'], 'AZURE_KEY' => $v['AZURE_BLOB_ACCOUNT_KEY']];
$text = '';
foreach ($values as $key => $value) {
    $text .= $key.'='.json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}
file_put_contents($target, $text, LOCK_EX);
chmod($target, 0600);
echo "Private integration configuration created. No messages sent.\n";

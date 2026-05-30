#!/bin/sh
set -e

write_wechat_pay_env() {
php <<'PHP'
<?php
$host = getenv('DB_HOST') ?: getenv('DATABASE_HOSTNAME') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: getenv('DATABASE_HOSTPORT') ?: '3306';
$db = getenv('DB_DATABASE') ?: getenv('DATABASE_DATABASE') ?: 'crmeb31';
$user = getenv('DB_USERNAME') ?: getenv('DATABASE_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: getenv('DATABASE_PASSWORD') ?: 'root';
$prefix = getenv('DB_PREFIX') ?: getenv('DATABASE_PREFIX') ?: 'eb_';
$map = [
    'WECHAT_PAY_MCHID' => 'pay_weixin_mchid',
    'WECHAT_PAY_KEY_V3' => 'pay_weixin_key_v3',
    'WECHAT_PAY_SERIAL_NO' => 'pay_weixin_serial_no',
    'WECHAT_PAY_NOTIFY_URL' => 'fmcg_wechat_pay_notify_url',
    'WECHAT_PAY_PROFIT_SHARING_NOTIFY_URL' => 'fmcg_wechat_profit_sharing_notify_url',
    'WECHAT_PAY_TRANSFER_NOTIFY_URL' => 'fmcg_wechat_transfer_notify_url',
    'SITE_URL' => 'site_url',
];
$values = [];
foreach ($map as $env => $name) {
    $value = getenv($env);
    if ($value !== false && $value !== '') {
        $values[$name] = $value;
    }
}
$pem = [
    'WECHAT_PAY_CERT' => ['pay_weixin_client_cert', 'wechat_pay_cert'],
    'WECHAT_PAY_CERT_CONTENT' => ['pay_weixin_client_cert', 'wechat_pay_cert'],
    'WECHAT_PAY_PRIVATE_KEY' => ['pay_weixin_client_key', 'wechat_pay_private_key'],
    'WECHAT_PAY_PRIVATE_KEY_CONTENT' => ['pay_weixin_client_key', 'wechat_pay_private_key'],
];
foreach (['WECHAT_PAY_CERT_PATH' => 'pay_weixin_client_cert', 'WECHAT_PAY_PRIVATE_KEY_PATH' => 'pay_weixin_client_key'] as $env => $name) {
    $path = getenv($env);
    if ($path !== false && $path !== '' && is_file($path)) {
        $pem[$env] = [$name, $name === 'pay_weixin_client_cert' ? 'wechat_pay_cert' : 'wechat_pay_private_key'];
    }
}
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    foreach ($values as $name => $value) {
        $stmt = $pdo->prepare("UPDATE `{$prefix}system_config` SET `value` = :value WHERE `menu_name` = :name");
        $stmt->execute([':value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ':name' => $name]);
    }
    foreach ($pem as $env => [$name, $path]) {
        $content = getenv($env);
        if ($content === false || $content === '') {
            continue;
        }
        if (substr($env, -5) === '_PATH' && is_file($content)) {
            $content = file_get_contents($content);
        }
        $stmt = $pdo->prepare("SELECT `id` FROM `{$prefix}system_pem` WHERE `name` = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $stmt = $pdo->prepare("UPDATE `{$prefix}system_pem` SET `type` = 'wechat', `path` = :path, `content` = :content WHERE `id` = :id");
            $stmt->execute([':path' => $path, ':content' => $content, ':id' => $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}system_pem` (`type`, `name`, `path`, `content`, `add_time`) VALUES ('wechat', :name, :path, :content, :time)");
            $stmt->execute([':name' => $name, ':path' => $path, ':content' => $content, ':time' => time()]);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "skip WECHAT_PAY env bridge: " . $e->getMessage() . PHP_EOL);
}
PHP
}

write_wechat_pay_env
php-fpm -D
nginx -g "daemon off;"

<?php
/**
 * CRMEB 自动安装脚本 - 在 Docker 容器内通过 CLI 执行
 * 用法: php /var/www/public/install/auto_install.php
 */

define('APP_DIR',  rtrim(dirname(__DIR__, 2), '/') . '/');   // /var/www/
define('SITE_DIR', rtrim(dirname(__DIR__), '/')   . '/');   // /var/www/public/

$lockFile = SITE_DIR . 'install.lock';

if (file_exists($lockFile)) {
    echo "[INSTALL] Already installed (install.lock found), skipping.\n";
    exit(0);
}

// ── 读取环境变量 ──────────────────────────────────────────
$dbHost   = getenv('MYSQL_HOST_IP')  ?: '127.0.0.1';
$dbPort   = getenv('MYSQL_PORT')     ?: '3306';
$dbUser   = getenv('MYSQL_USER')     ?: 'root';
$dbPwd    = getenv('MYSQL_PASSWORD') ?: '123456';
$dbName   = strtolower(getenv('MYSQL_DATABASE') ?: 'crmeb');
$dbPrefix = 'eb_';

$rbHost   = getenv('REDIS_HOST_IP')  ?: '127.0.0.1';
$rbPort   = getenv('REDIS_PORT')     ?: '6379';
$rbPwd    = getenv('REDIS_PASSWORD') ?: '';
$rbSelect = getenv('REDIS_DATABASE') ?: '0';

// 管理员账号，可通过环境变量覆盖
$adminUser = getenv('CRMEB_ADMIN_USER') ?: 'admin';
$adminPwd  = getenv('CRMEB_ADMIN_PWD')  ?: 'crmeb.com';

// ── 等待 MySQL 就绪 ───────────────────────────────────────
echo "[INSTALL] Waiting for MySQL at {$dbHost}:{$dbPort}...\n";
$conn    = null;
$maxTry  = 40;
for ($i = 1; $i <= $maxTry; $i++) {
    $conn = @mysqli_connect($dbHost, $dbUser, $dbPwd, null, (int)$dbPort);
    if ($conn) {
        echo "[INSTALL] MySQL connected.\n";
        break;
    }
    echo "[INSTALL] Not ready yet, retry {$i}/{$maxTry} in 3s...\n";
    sleep(3);
}
if (!$conn) {
    echo "[INSTALL] ERROR: Cannot connect to MySQL after {$maxTry} retries!\n";
    exit(1);
}

// ── 建库 ─────────────────────────────────────────────────
mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8");
if (!mysqli_select_db($conn, $dbName)) {
    echo "[INSTALL] ERROR: Cannot select database '{$dbName}': " . mysqli_error($conn) . "\n";
    exit(1);
}
mysqli_set_charset($conn, 'utf8');

// ── 检查是否已存在表（重复执行保护）──────────────────────
$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE table_schema='{$dbName}'");
$row = mysqli_fetch_assoc($res);
if ((int)$row['c'] > 0) {
    echo "[INSTALL] Database '{$dbName}' already has tables, skipping SQL import.\n";
} else {
    // ── 导入 SQL ─────────────────────────────────────────
    $sqlFile = SITE_DIR . 'install/crmeb.sql';
    if (!file_exists($sqlFile)) {
        echo "[INSTALL] ERROR: SQL file not found: {$sqlFile}\n";
        exit(1);
    }
    echo "[INSTALL] Importing SQL from {$sqlFile} ...\n";
    $sqldata   = file_get_contents($sqlFile);
    $sqlFormat = sql_split($sqldata, $dbPrefix);
    unset($sqldata);
    $total = count($sqlFormat);
    echo "[INSTALL] Total SQL statements: {$total}\n";

    foreach ($sqlFormat as $idx => $sql) {
        $sql = trim($sql);
        if ($sql === '') continue;

        if (stripos($sql, 'CREATE TABLE') !== false) {
            // 提取表名并先 DROP
            if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $sql, $m)) {
                mysqli_query($conn, "DROP TABLE IF EXISTS `{$m[1]}`");
            }
        }
        if (!mysqli_query($conn, $sql)) {
            $err = mysqli_error($conn);
            if ($err) echo "[INSTALL] Warning SQL[{$idx}]: {$err}\n";
        }
        if ($idx % 100 === 0) {
            echo "[INSTALL] Progress: {$idx}/{$total}\n";
        }
    }
    echo "[INSTALL] SQL import complete.\n";
}

// ── 写入 .env ─────────────────────────────────────────────
$envTemplate = SITE_DIR . 'install/.env';
if (!file_exists($envTemplate)) {
    echo "[INSTALL] ERROR: .env template not found: {$envTemplate}\n";
    exit(1);
}
echo "[INSTALL] Writing .env config...\n";
$unique    = uniqid();
$strConfig = file_get_contents($envTemplate);

$replaces = [
    '#DB_HOST#'         => $dbHost,
    '#DB_NAME#'         => $dbName,
    '#DB_USER#'         => $dbUser,
    '#DB_PWD#'          => $dbPwd,
    '#DB_PORT#'         => $dbPort,
    '#DB_PREFIX#'       => $dbPrefix,
    '#DB_CHARSET#'      => 'utf8',
    '#CACHE_TYPE#'      => 'redis',
    '#CACHE_PREFIX#'    => 'cache_'     . $unique . ':',
    '#CACHE_TAG_PREFIX#'=> 'cache_tag_' . $unique . ':',
    '#RB_HOST#'         => $rbHost,
    '#RB_PORT#'         => $rbPort,
    '#RB_PWD#'          => $rbPwd,
    '#RB_SELECT#'       => $rbSelect,
    '#QUEUE_NAME#'      => $unique,
];
foreach ($replaces as $placeholder => $value) {
    $strConfig = str_replace($placeholder, $value, $strConfig);
}

// 将 \r 换行符统一转为 \n（模板文件使用 \r 作为行分隔符）
$strConfig = str_replace("\r", "\n", $strConfig);

@chmod(APP_DIR . '.env', 0666);
if (file_put_contents(APP_DIR . '.env', $strConfig) === false) {
    echo "[INSTALL] ERROR: Cannot write " . APP_DIR . ".env\n";
    exit(1);
}
echo "[INSTALL] .env written to " . APP_DIR . ".env\n";

// ── 创建管理员账号 ────────────────────────────────────────
echo "[INSTALL] Creating admin user '{$adminUser}'...\n";
$pwdHash = password_hash($adminPwd, PASSWORD_BCRYPT);
$time    = time();
mysqli_query($conn, "TRUNCATE TABLE `{$dbPrefix}system_admin`");
$insertSql = "INSERT INTO `{$dbPrefix}system_admin`
    (`id`,`account`,`head_pic`,`pwd`,`real_name`,`roles`,`last_ip`,`last_time`,`add_time`,`login_count`,`level`,`status`,`is_del`)
    VALUES (1, '{$adminUser}', '/statics/system_images/admin_head_pic.png', '{$pwdHash}', 'admin', '1', '127.0.0.1', {$time}, {$time}, 0, 0, 1, 0)";
if (!mysqli_query($conn, $insertSql)) {
    echo "[INSTALL] Warning: Cannot insert admin: " . mysqli_error($conn) . "\n";
}

// ── 写入 install.lock ─────────────────────────────────────
echo "[INSTALL] Creating install.lock...\n";
if (!@touch($lockFile)) {
    // 目录不可写时用 file_put_contents
    @file_put_contents($lockFile, date('Y-m-d H:i:s'));
}

// ── 写入 .constant ────────────────────────────────────────
$serial   = substr(md5(uniqid(mt_rand(), true)), 0, 6);
$constant = "<?php\ndefine('INSTALL_DATE'," . time() . ");\ndefine('SERIALNUMBER','{$serial}');";
@chmod(APP_DIR . '.constant', 0666);
@file_put_contents(APP_DIR . '.constant', $constant);

mysqli_close($conn);

echo "\n======================================\n";
echo "  CRMEB Installation Complete!\n";
echo "======================================\n";
echo "  Shop URL : http://[host]:8011/\n";
echo "  Admin URL: http://[host]:8011/admin\n";
echo "  Username : {$adminUser}\n";
echo "  Password : {$adminPwd}\n";
echo "======================================\n\n";
exit(0);

// ── Helper Functions ──────────────────────────────────────

function sql_split(string $sql, string $tablepre): array
{
    if ($tablepre !== 'tp_') {
        $sql = str_replace('tp_', $tablepre, $sql);
    }
    $sql = preg_replace(
        "/TYPE=(InnoDB|MyISAM|MEMORY)( DEFAULT CHARSET=[^; ]+)?/",
        "ENGINE=\\1 DEFAULT CHARSET=utf8",
        $sql
    );
    $sql = str_replace("\r", "\n", $sql);

    $ret    = [];
    $num    = 0;
    $chunks = explode(";\n", trim($sql));
    unset($sql);

    foreach ($chunks as $chunk) {
        $ret[$num] = '';
        $lines     = array_filter(explode("\n", trim($chunk)));
        foreach ($lines as $line) {
            $first = substr($line, 0, 1);
            if ($first !== '#' && $first !== '-') {
                $ret[$num] .= $line;
            }
        }
        $num++;
    }
    return $ret;
}

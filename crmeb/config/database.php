<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\facade\Env;

$env = static function (string $upper, string $thinkKey, $default = null) {
    $value = getenv($upper);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return Env::get($thinkKey, $default);
};

return [
    // 默认使用的数据库连接配置
    'default'         => $env('DB_DRIVER', 'database.driver', 'mysql'),

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            // 数据库类型
            'type'            => $env('DB_TYPE', 'database.type', 'mysql'),
            // 服务器地址
            'hostname'        => $env('DB_HOST', 'database.hostname', '127.0.0.1'),
            // 数据库名
            'database'        => $env('DB_DATABASE', 'database.database', 'crmeb31'),
            // 用户名
            'username'        => $env('DB_USERNAME', 'database.username', 'root'),
            // 密码
            'password'        => $env('DB_PASSWORD', 'database.password', 'root'),
            // 端口
            'hostport'        => $env('DB_PORT', 'database.hostport', '3306'),
            // 连接dsn
            'dsn'             => '',
            // 数据库连接参数
            // 微信云托管 MySQL 8.0 默认开启 ONLY_FULL_GROUP_BY，
            // CRMEB 部分 SQL 的 ORDER BY 列不在 GROUP BY 中，需要移除该模式
            'params'          => [
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ],
            // 数据库编码默认采用utf8
            'charset'         => $env('DB_CHARSET', 'database.charset', 'utf8'),
            // 数据库表前缀
            'prefix'          => $env('DB_PREFIX', 'database.prefix', 'eb_'),
            // 数据库调试模式
            'debug'           => $env('DB_DEBUG', 'database.debug', true),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
            // 是否需要进行SQL性能分析
            'sql_explain'     => false,
            // Builder类
            'builder'         => '',
            // Query类
            'query'           => '',
            // 是否需要断线重连
            'break_reconnect' => true,
        ],

        // 更多的数据库配置信息
    ],

    // 自定义时间查询规则
    'time_query_rule' => [],
    // 自动写入时间戳字段
    'auto_timestamp'  => 'timestamp',
    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',
    //数据分页配置
    'page' => [
        //页码key
        'pageKey' => 'page',
        //每页截取key
        'limitKey' => 'limit',
        //每页截取最大值
        'limitMax' => 100,
        //默认条数
        'defaultLimit' => 10,
    ]
];

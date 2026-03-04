<?php

/**
 * 功能测试用最小 Yii 控制台应用，仅用于提供 Yii::$app 与 params
 */

$base = dirname(__DIR__);
$vendor = $base . '/vendor';
if (!is_dir($vendor)) {
    // 组件被 composer 安装到项目 vendor 下时的路径
    $vendor = dirname(dirname($base)) . '/vendor';
}
if (!is_dir($vendor)) {
    fwrite(STDERR, "请先在组件目录执行 composer install，或在已接入本组件的项目中执行: php yii script/masked-log-test\n");
    exit(1);
}
require $vendor . '/autoload.php';
require $vendor . '/yiisoft/yii2/Yii.php';

$config = [
    'id'         => 'masked-log-test',
    'basePath'   => __DIR__,
    'params'     => [
        'logMask' => [
            'enabled'   => true,
            'mask_keys' => ['password', 'pwd', 'token', 'secret'],
            'mask_vars' => ['_POST.password', '_POST.token', '_GET.secret'],
        ],
    ],
];

new \yii\console\Application($config);

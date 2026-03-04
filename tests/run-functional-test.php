<?php

/**
 * 脱敏日志功能测试入口（可在组件目录下独立运行：php tests/run-functional-test.php）
 *
 * 场景 1：接口请求参数（表单/JSON）
 * 场景 2：接口响应（XML/JSON）
 * 场景 3：程序 error 日志
 */

require __DIR__ . '/bootstrap.php';

use liwenyu\maskedlog\FunctionalTest;

$logFile = dirname(__DIR__) . '/tests/output/masked-log-test.log';
$test = new FunctionalTest($logFile);
$result = $test->run();

foreach ($result['messages'] as $msg) {
    echo $msg . "\n";
}
exit($result['passed'] ? 0 : 1);

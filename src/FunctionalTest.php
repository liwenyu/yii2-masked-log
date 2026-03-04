<?php

namespace liwenyu\maskedlog;

use ReflectionMethod;
use yii\log\Logger;

/**
 * 脱敏日志功能测试：覆盖请求参数(表单/JSON)、响应(XML/JSON)、error 日志三种场景
 *
 * 不依赖具体应用，仅需 Yii::$app 与 params['logMask'] 已配置（或直接在 target 配置中指定）。
 */
class FunctionalTest
{
    /** @var string 测试用的日志文件路径 */
    private $log_file;

    /** @var string[] 测试过程中的提示信息 */
    private $messages = [];

    public function __construct(string $logFile)
    {
        $this->log_file = $logFile;
    }

    /**
     * 执行全部场景并校验：敏感内容为 ***，且不出现明文
     *
     * @return array ['passed' => bool, 'messages' => string[]]
     */
    public function run(): array
    {
        $this->messages = [];
        $dir = dirname($this->log_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (file_exists($this->log_file)) {
            @unlink($this->log_file);
        }

        $target = $this->createTarget();
        $timestamp = microtime(true);

        // 场景 1：接口请求参数（表单 / JSON 格式）
        $this->runRequestScenario($target, $timestamp);
        $timestamp += 0.001;

        // 场景 2：接口响应（XML / JSON）
        $this->runResponseScenario($target, $timestamp);
        $timestamp += 0.001;

        // 场景 3：程序 error 日志
        $this->runErrorScenario($target, $timestamp);

        // 注入上下文（模拟 logVars 写入），用于校验 _POST/_GET 脱敏
        $this->appendContextMessage($target, $timestamp);

        $target->export();

        return $this->assertAndReport();
    }

    private function createTarget(): MaskedFileTarget
    {
        $target = new MaskedFileTarget([
            'logFile'     => $this->log_file,
            'logVars'     => ['_GET', '_POST'],
            'maskEnabled' => true,
            'maskKeys'    => ['password', 'pwd', 'token', 'secret'],
            'maskVars'    => ['_POST.password', '_POST.token', '_GET.secret'],
        ]);
        $target->init();
        return $target;
    }

    /** 通过反射调用 getContextMessage，并把结果作为一条日志写入，以校验上下文脱敏 */
    private function appendContextMessage(MaskedFileTarget $target, float $timestamp): void
    {
        $m = new ReflectionMethod($target, 'getContextMessage');
        $m->setAccessible(true);
        $context = $m->invoke($target);
        $target->messages[] = [$context, Logger::LEVEL_INFO, 'application', $timestamp, [], 0];
    }

    /** 场景 1：请求参数为表单或 JSON，日志中应脱敏 */
    private function runRequestScenario(MaskedFileTarget $target, float $timestamp): void
    {
        $GLOBALS['_POST'] = [
            'username' => 'test_user',
            'password' => 'form_password_123',
            'token'    => 'post_token_abc',
        ];
        $GLOBALS['_GET'] = ['secret' => 'get_secret_xyz'];

        // 模拟记录 JSON 请求体（解析后的数组）
        $target->messages[] = [
            [
                'type'   => 'request',
                'format' => 'json',
                'body'   => [
                    'user'     => 'api_user',
                    'password' => 'json_body_password',
                    'token'    => 'bearer_xxx',
                ],
            ],
            Logger::LEVEL_INFO,
            'Request',
            $timestamp,
            [],
            0,
        ];
        // 模拟记录表单请求参数（数组形式）
        $target->messages[] = [
            [
                'request' => 'form',
                'params'  => [
                    'username' => 'test_user',
                    'password' => 'form_password_123',
                ],
            ],
            Logger::LEVEL_INFO,
            'Request',
            $timestamp + 0.0001,
            [],
            0,
        ];
    }

    /** 场景 2：响应为 XML 或 JSON，其中敏感字段应脱敏 */
    private function runResponseScenario(MaskedFileTarget $target, float $timestamp): void
    {
        $target->messages[] = [
            [
                'type' => 'response',
                'format' => 'json',
                'data' => [
                    'code' => 0,
                    'token' => 'response_token_should_be_masked',
                    'user' => ['name' => 'ok', 'password' => 'resp_pwd_456'],
                ],
            ],
            Logger::LEVEL_INFO,
            'Response',
            $timestamp,
            [],
            0,
        ];
        $target->messages[] = [
            [
                'type'   => 'response',
                'format' => 'xml',
                'body'   => '<root><token>xml_token_789</token><secret>xml_secret</secret></root>',
                'parsed' => [
                    'token'  => 'xml_token_789',
                    'secret' => 'xml_secret',
                ],
            ],
            Logger::LEVEL_INFO,
            'Response',
            $timestamp + 0.0001,
            [],
            0,
        ];
    }

    /** 场景 3：程序 error 日志中含敏感信息应脱敏 */
    private function runErrorScenario(MaskedFileTarget $target, float $timestamp): void
    {
        $target->messages[] = [
            [
                'message' => 'Login failed',
                'password' => 'error_log_password_999',
                'trace' => 'some stack',
            ],
            Logger::LEVEL_ERROR,
            'application',
            $timestamp,
            [],
            0,
        ];
    }

    private function assertAndReport(): array
    {
        $raw = is_file($this->log_file) ? file_get_contents($this->log_file) : '';
        // 仅校验“以数组形式”记录的敏感字段会被脱敏；XML/JSON 原始字符串体内的明文不在此列
        $sensitive_plain = [
            'form_password_123',
            'post_token_abc',
            'get_secret_xyz',
            'json_body_password',
            'bearer_xxx',
            'response_token_should_be_masked',
            'resp_pwd_456',
            'error_log_password_999',
        ];
        $must_mask = '***';
        $passed = true;

        foreach ($sensitive_plain as $plain) {
            if (strpos($raw, $plain) !== false) {
                $this->messages[] = "失败：日志中仍出现明文敏感内容: {$plain}";
                $passed = false;
            }
        }
        if (strpos($raw, $must_mask) === false) {
            $this->messages[] = '失败：日志中未出现脱敏占位 ***';
            $passed = false;
        }
        if ($passed) {
            $this->messages[] = '通过：所有敏感内容已脱敏为 ***，且未发现明文泄露。';
        }
        return ['passed' => $passed, 'messages' => $this->messages];
    }
}

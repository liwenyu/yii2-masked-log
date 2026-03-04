<?php

namespace liwenyu\maskedlog;

use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\log\FileTarget;

/**
 * 带脱敏功能的文件日志 Target
 *
 * 在写入文件前对涉密字段进行脱敏（替换为 ***），支持通过 params 开关与可配置脱敏字段。
 *
 * 功能说明：
 * 1. 将涉密字段内容用 *** 代替，避免密码等直接写入日志
 * 2. 通过 params['logMask']['enabled'] 控制是否启用脱敏
 * 3. 通过 params['logMask']['mask_keys'] 定义参与脱敏的字段/参数名
 * 4. 通过 params['logMask']['mask_vars'] 定义上下文变量（如 _POST、_GET）中要脱敏的键
 *
 * 若 params 中未配置 logMask，组件仍可用，默认不进行任何脱敏（行为等同 FileTarget）。
 *
 * 配置示例（params）：
 * ```php
 * 'params' => [
 *     'logMask' => [
 *         'enabled' => true,
 *         'mask_keys' => ['password', 'pwd', 'token', 'secret', 'auth_key'],
 *         'mask_vars' => ['_POST.password', '_GET.token', '_SERVER.HTTP_AUTHORIZATION'],
 *     ],
 * ],
 * ```
 *
 * 将 log 的 targets 中 class 改为本类即可：
 * ```php
 * 'log' => [
 *     'targets' => [
 *         [
 *             'class' => 'liwenyu\maskedlog\MaskedFileTarget',
 *             'logFile' => '@runtime/logs/app.log',
 *             'levels' => ['error', 'warning', 'info'],
 *         ],
 *     ],
 * ],
 * ```
 */
class MaskedFileTarget extends FileTarget
{
    /**
     * 是否启用脱敏（由 params 或配置注入）。未配置 logMask 时默认 false，不做任何脱敏。
     * @var bool
     */
    public $maskEnabled = false;

    /**
     * 参与脱敏的字段/参数名（小写匹配），如 password、token
     * 日志消息为数组时，键名在该列表中的值会被替换为 ***
     * @var string[]
     */
    public $maskKeys = [];

    /**
     * {@inheritdoc}
     * 从 params['logMask'] 读取开关与脱敏字段配置；未配置时保持默认，不做任何脱敏。
     */
    public function init(): void
    {
        parent::init();

        if (Yii::$app === null || !isset(Yii::$app->params['logMask']) || !is_array(Yii::$app->params['logMask'])) {
            return;
        }

        $config = Yii::$app->params['logMask'];

        // 只有显式配置了 logMask 时才可能启用脱敏；enabled 未配置时默认为 true
        $this->maskEnabled = !array_key_exists('enabled', $config) || (bool) $config['enabled'];

        if (!empty($config['mask_keys']) && is_array($config['mask_keys'])) {
            $this->maskKeys = $this->normalizeMaskKeys(array_map('strtolower', $config['mask_keys']));
        }

        if ($this->maskEnabled && !empty($config['mask_vars']) && is_array($config['mask_vars'])) {
            $this->maskVars = array_merge($this->maskVars, $config['mask_vars']);
        }

        // 统一展开为 snake_case 与 camelCase 两种形式，便于同时匹配 user_password 与 userPassword
        if (!empty($this->maskKeys)) {
            $this->maskKeys = $this->normalizeMaskKeys(array_map('strtolower', $this->maskKeys));
        }
    }

    /**
     * 将 mask_keys 展开为小写 + 去下划线形式，使 user_password 能同时匹配 userPassword
     *
     * @param string[] $keys 已小写的键名列表
     * @return string[]
     */
    protected function normalizeMaskKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[] = $k;
            $noUnderscore = str_replace('_', '', $k);
            if ($noUnderscore !== $k) {
                $out[] = $noUnderscore;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 格式化单条日志消息，在写入前对消息内容中的涉密字段脱敏
     *
     * @param array $message 单条日志，结构见 Logger::messages
     * @return string
     */
    public function formatMessage($message): string
    {
        if ($this->maskEnabled && !empty($this->maskKeys)) {
            $message = $this->maskMessageContent($message);
        }

        return parent::formatMessage($message);
    }

    /**
     * 对消息内容进行脱敏：若为数组则递归替换指定键的值为 ***
     *
     * @param array $message 日志消息
     * @return array 脱敏后的消息（复制，不修改原数组）
     */
    protected function maskMessageContent(array $message): array
    {
        $text = $message[0];

        if (is_array($text)) {
            $message[0] = $this->maskArrayRecursive($text);
        } elseif (is_string($text) && $text !== '') {
            $message[0] = $this->maskString($text);
        }

        return $message;
    }

    /**
     * 对字符串形式的日志做脱敏：将 key=value 中 key 在 maskKeys 内的 value 替换为 ***
     * 用于 Params=user_name=xx&user_password=123456 这类拼接字符串
     *
     * @param string $text 原始日志字符串
     * @return string 脱敏后的字符串
     */
    protected function maskString(string $text): string
    {
        if (empty($this->maskKeys)) {
            return $text;
        }
        // 按键名长度降序，先匹配 user_password 再匹配 password，避免误替换
        $keys = $this->maskKeys;
        usort($keys, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($keys as $key) {
            $quoted = preg_quote($key, '/');
            $text = preg_replace(
                '/(\b' . $quoted . '\s*=\s*)([^&\s]*)/iu',
                '$1***',
                $text
            );
        }
        return $text;
    }

    /**
     * 静态方法：对字符串按 params['logMask']['mask_keys'] 做 key=value 脱敏
     * 用于直接写文件的日志（如 common\extend\Log）在写入前调用，与 FileTarget 脱敏规则一致
     *
     * @param string $text 原始字符串，如 Params=user_name=xx&user_password=123456
     * @return string 脱敏后的字符串
     */
    public static function maskLogString(string $text): string
    {
        if ($text === '' || Yii::$app === null || !isset(Yii::$app->params['logMask']) || !is_array(Yii::$app->params['logMask'])) {
            return $text;
        }
        $config = Yii::$app->params['logMask'];
        if (isset($config['enabled']) && !$config['enabled']) {
            return $text;
        }
        if (empty($config['mask_keys']) || !is_array($config['mask_keys'])) {
            return $text;
        }
        $keys = array_map('strtolower', $config['mask_keys']);
        $keys = static::normalizeMaskKeysStatic($keys);
        usort($keys, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($keys as $key) {
            $quoted = preg_quote($key, '/');
            $text = preg_replace('/(\b' . $quoted . '\s*=\s*)([^&\s]*)/iu', '$1***', $text);
        }
        return $text;
    }

    /**
     * @param string[] $keys 已小写的键名列表
     * @return string[]
     */
    private static function normalizeMaskKeysStatic(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[] = $k;
            $noUnderscore = str_replace('_', '', $k);
            if ($noUnderscore !== $k) {
                $out[] = $noUnderscore;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 递归遍历数组，将指定键名的值替换为 ***（键名不区分大小写）
     *
     * @param array $data 原始数据
     * @return array 脱敏后的数组
     */
    protected function maskArrayRecursive(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $keyLower = is_string($key) ? strtolower($key) : (string) $key;
            if (in_array($keyLower, $this->maskKeys, true)) {
                $out[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->maskArrayRecursive($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * 生成要写入的上下文信息；仅当启用脱敏时对 maskVars 中的项替换为 ***
     *
     * @return string
     */
    protected function getContextMessage(): string
    {
        $context = ArrayHelper::filter($GLOBALS, $this->logVars);

        if ($this->maskEnabled) {
            foreach ($this->maskVars as $var) {
                if (ArrayHelper::getValue($context, $var) !== null) {
                    ArrayHelper::setValue($context, $var, '***');
                }
            }
            // 按 mask_keys 对 _GET、_POST 等数组整体脱敏，避免漏配 mask_vars
            if (!empty($this->maskKeys)) {
                foreach ($context as $varName => $varValue) {
                    if (is_array($varValue)) {
                        $context[$varName] = $this->maskArrayRecursive($varValue);
                    }
                }
            }
        }

        $result = [];
        foreach ($context as $key => $value) {
            $result[] = "\${$key} = " . VarDumper::dumpAsString($value);
        }

        return implode("\n\n", $result);
    }
}

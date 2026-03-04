# yii2-masked-log

基于 Yii2 的日志脱敏组件，在写入文件前将涉密字段内容替换为 `***`，避免密码、token 等直接落盘。

## 功能

1. **涉密字段脱敏**：日志内容中的指定字段（如 `password`、`token`）统一替换为 `***`
2. **params 开关**：通过 `params['logMask']['enabled']` 控制是否启用脱敏，便于按环境开关
3. **可配置脱敏字段**：通过 `params['logMask']['mask_keys']` 与 `mask_vars` 定义参与脱敏的字段或参数

## 安装

```bash
composer require liwenyu/yii2-masked-log
```

或本地开发时在项目 `composer.json` 中：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/yii2-masked-log"
        }
    ],
    "require": {
        "liwenyu/yii2-masked-log": "@dev"
    }
}
```

## 配置

### 1. params 配置（推荐）

在应用 `params` 中增加 `logMask`，组件会主动读取：

```php
// common/config/params.php 或 main-local.php 中合并
'params' => [
    'logMask' => [
        'enabled'    => true,   // 是否启用脱敏，false 时不做任何脱敏
        'mask_keys'  => [       // 日志消息为数组时，这些键对应的值会被替换为 ***
            'password',
            'pwd',
            'token',
            'secret',
            'auth_key',
            'access_token',
        ],
        'mask_vars'  => [       // 上下文变量（如 _GET、_POST）中要脱敏的项，支持点分路径
            '_POST.password',
            '_GET.token',
            '_SERVER.HTTP_AUTHORIZATION',
            '_SERVER.PHP_AUTH_USER',
            '_SERVER.PHP_AUTH_PW',
        ],
    ],
],
```

- **enabled**：`true` 时启用脱敏，`false` 时与普通 `FileTarget` 行为一致  
- **mask_keys**：仅对「日志消息内容」生效。当使用 `Yii::info($array)` 且 `$array` 中有这些键时，对应值会变成 `***`，键名不区分大小写  
- **mask_vars**：对「上下文变量」（如 `logVars` 里的 `_GET`、`_POST`）生效，格式同 Yii2 自带的 `maskVars`（如 `_POST.password`）

### 2. 将 log 的 FileTarget 换成本组件

在日志组件里把需要脱敏的 target 的 `class` 改为 `MaskedFileTarget`，其余配置与 `yii\log\FileTarget` 一致：

```php
'log' => [
    'traceLevel' => YII_DEBUG ? 3 : 0,
    'targets' => [
        [
            'class'   => 'liwenyu\maskedlog\MaskedFileTarget',
            'logFile' => '@runtime/logs/app.log',
            'levels'  => ['error', 'warning', 'info'],
        ],
        [
            'class'      => 'liwenyu\maskedlog\MaskedFileTarget',
            'logFile'    => '@runtime/logs/request.log',
            'levels'     => ['info', 'trace'],
            'categories' => ['Request', 'Response'],
            'logVars'    => ['_GET', '_POST', '_SERVER'],
        ],
    ],
],
```

### 3. 仅用配置、不用 params（可选）

也可以直接在 target 配置里写死开关和字段，不依赖 params：

```php
[
    'class'        => 'liwenyu\maskedlog\MaskedFileTarget',
    'logFile'      => '@runtime/logs/app.log',
    'maskEnabled'  => true,
    'maskKeys'     => ['password', 'pwd', 'token'],
    'maskVars'     => ['_POST.password', '_GET.token'],
],
```

若同时存在 params 与 target 上的配置，**params 会覆盖** target 的 `maskEnabled` 和 `mask_keys`；`mask_vars` 会与 Yii2 默认的 `maskVars` 合并。

## 使用示例

```php
// 数组中的 password、token 会变成 *** 再写入日志
Yii::info([
    'username' => 'zhangsan',
    'password' => '123456',
    'token'    => 'secret-token-xxx',
], 'login');

// 嵌套数组中的指定键也会被脱敏
Yii::info([
    'action' => 'register',
    'data'   => [
        'name'     => 'lisi',
        'password' => 'abc123',
    ],
], 'user');
```

写入文件的内容中，上述 `password`、`token` 会显示为 `***`。

## 功能测试

在已接入本组件的 Yii2 项目中执行：

```bash
php yii script/masked-log-test
```

（需在项目中添加 `console/modules/script/controllers/MaskedLogTestController.php` 或等效命令并安装本组件。）

测试覆盖三种场景：

1. **接口请求参数**：表单 / JSON 请求体（含 `_POST`、请求数组中的 `password` / `token`）写入日志时脱敏  
2. **接口响应**：响应为 JSON 或 XML 时，以数组形式记录的 `token`、`password`、`secret` 等字段脱敏  
3. **程序 error 日志**：`Yii::error()` 中传入的数组里涉密字段脱敏  

也可在组件目录下独立运行（需先 `composer install`）：

```bash
cd /path/to/yii2-masked-log
composer install
php tests/run-functional-test.php
```

## 要求

- PHP >= 7.4  
- Yii2 ~2.0  

## License

MIT

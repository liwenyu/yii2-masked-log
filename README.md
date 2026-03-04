# yii2-masked-log

基于 Yii2 的日志脱敏组件，在写入文件前将涉密字段内容替换为 `***`，避免密码、token 等直接落盘。

## 功能

1. **涉密字段脱敏**：日志内容中的指定字段（如 `password`、`token`、`user_password`）统一替换为 `***`
2. **params 开关**：通过 `params['logMask']['enabled']` 控制是否启用脱敏，便于按环境开关
3. **可配置脱敏字段**：通过 `params['logMask']['mask_keys']` 与 `mask_vars` 定义参与脱敏的字段或参数
4. **三种脱敏范围**：
   - **数组日志**：`Yii::info($array)` 时，数组中与 `mask_keys` 同名的键会被脱敏（含嵌套）
   - **字符串日志**：`Params=user_name=xx&user_password=123456` 这类 `key=value` 字符串中，匹配的 value 会变为 `***`
   - **上下文变量**：error/warning 时输出的 `$_GET`、`$_POST` 等会按 `mask_keys` 与 `mask_vars` 脱敏
5. **命名兼容**：`mask_keys` 中配置 `user_password` 会同时匹配 `userPassword`（驼峰）
6. **直接写文件场景**：若项目用 `file_put_contents` 等直接写日志，可调用 `MaskedFileTarget::maskLogString($text)` 在写入前脱敏

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
- **mask_keys**：参与脱敏的字段名（不区分大小写，且 `user_password` 会自动匹配 `userPassword`）。对「数组日志」和「key=value 字符串」以及「上下文里的 _GET/_POST 等数组」均生效  
- **mask_vars**：对上下文变量做按路径脱敏的补充项，格式同 Yii2 的 `maskVars`（如 `_POST.password`、`_SERVER.HTTP_AUTHORIZATION`）。配置了 `mask_keys` 后，上下文中同名字段也会被脱敏，可按需再在 `mask_vars` 中补路径

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

### 直接写文件的日志（如 request/response 拼接字符串）

若项目中有用 `file_put_contents` 等直接写日志（不经过 Yii 的 log target），需在写入前对字符串做脱敏。可调用静态方法：

```php
use liwenyu\maskedlog\MaskedFileTarget;

$raw = 'POST /api/v2/users, Params=user_name=test&user_password=123456';
$safe = MaskedFileTarget::maskLogString($raw);
// $safe = 'POST /api/v2/users, Params=user_name=test&user_password=***'
file_put_contents($logFile, $safe . "\n", FILE_APPEND);
```

`maskLogString()` 会读取 `params['logMask']`（enabled、mask_keys），仅对字符串中的 `key=value` 形式做替换，与 FileTarget 脱敏规则一致。

### 404 / error 时上下文脱敏

404 或未捕获异常时，Yii 会把 `$_GET`、`$_POST` 等作为上下文写入 error 日志。要脱敏需满足：

1. 写入该日志的 target 使用 **MaskedFileTarget**（不要用 `yii\log\FileTarget`）
2. `params['logMask']['enabled']` 为 `true`，且 `mask_keys` 中包含需脱敏的键（如 `user_password`、`password`）

组件会对上下文中的 `_POST`、`_GET` 等数组按 `mask_keys` 做整体脱敏，无需在 `mask_vars` 里逐个写 `_POST.user_password`（按需可再补）。

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

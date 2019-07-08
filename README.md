# 微信支付

微信支付 http://gong.gg/


## 安装和配置
修改项目下的composer.json文件，并添加：  
```
    "shopxo/phalapi2-wechat": "dev-master"
```
然后执行```composer update```。  

## 注册
在/path/to/phalapi/config/di.php文件中，注册：  
```php
$di->wechat = function() {
    return new \PhalApi\Alipay\Lite();
};
```

## 使用
发起支付
```php
\PhalApi\DI()->wechat->Pay();
```
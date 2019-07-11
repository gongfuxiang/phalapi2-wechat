<?php
namespace PhalApi\Wechat;

use PhalApi\Exception;

/**
 * 微信支付
 * @author  Devil
 * @blog    http://gong.gg/
 * @version 1.0.0
 * @date    2019-07-04
 * @desc    description
 */
class Lite
{
    private $public_appid;
    private $miniapp_appid;
    private $app_appid;
    private $mch_id;
    private $key;
    private $apiclient_cert_dir;
    private $apiclient_key_dir;
    private $notify_url;
    private $pay_params;
    private $params;
    private $pay_appid;
    private $trade_type;

    /**
     * 构造方法
     * @author  Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-07-04
     * @desc    description
     * @param   [type]          $config [配置信息]
     */
    public function __construct($config)
    {
        $this->public_appid         = isset($config['public_appid']) ? $config['public_appid'] : [];
        $this->miniapp_appid        = isset($config['miniapp_appid']) ? $config['miniapp_appid'] : [];
        $this->app_appid            = isset($config['app_appid']) ? $config['app_appid'] : '';
        $this->mch_id               = isset($config['mch_id']) ? $config['mch_id'] : '';
        $this->key                  = isset($config['key']) ? $config['key'] : '';
        $this->apiclient_cert_dir   = isset($config['apiclient_cert_dir']) ? $config['apiclient_cert_dir'] : '';
        $this->apiclient_key_dir    = isset($config['apiclient_key_dir']) ? $config['apiclient_key_dir'] : '';
        $this->notify_url           = isset($config['notify_url']) ? $config['notify_url'] : '';
    }

    /**
     * 支付入口
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2018-09-19
     * @desc    description
     * @param   [array]           $params [输入参数]
     */
    public function Pay($params = [])
    {
        // 请求参数
        $this->params = $params;

        // 参数
        if(empty($this->params))
        {
            throw new Exception('参数不能为空', 400);
        }
        
        if(empty($this->params['client_type']))
        {
            throw new Exception('客户端类型有误', 401);
        }

        if(empty($this->params['sourcename']))
        {
            throw new Exception('项目标记有误', 402);
        }

        // 配置信息
        if(empty($this->mch_id) || empty($this->key) || empty($this->notify_url))
        {
            throw new Exception('支付缺少配置', 410);
        }

        // appid设置
        $this->SetPayAppId();

        // 交易类型
        $this->SetTradeType();

        // 额外校验
        $this->AdditionalParamsCheck();

        // 支付参数
        $this->SetPayParams();

        // 请求接口处理
        $result = $this->XmlToArray($this->HttpRequest('https://api.mch.weixin.qq.com/pay/unifiedorder', $this->ArrayToXml($this->pay_params)));
        if(!empty($result['return_code']) && $result['return_code'] == 'SUCCESS' && !empty($result['prepay_id']))
        {
            return $this->PayHandleReturn($result);
        }

        // 错误
        $msg = is_string($result) ? $result : (empty($result['return_msg']) ? '支付接口异常' : $result['return_msg']);
        if(!empty($result['err_code_des']))
        {
            $msg .= '-'.$result['err_code_des'];
        }
        throw new Exception($msg, 440);


        return $ret;
    }

    /**
     * 额外参数校验
     * @author  Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-07-08
     * @desc    description
     */
    private function AdditionalParamsCheck()
    {
        // H5+微信环境中
        if(in_array($this->trade_type, ['MWEB', 'JSAPI']))
        {
            // 同步返回地址判断
            if(empty($this->params['return_url']))
            {
                throw new Exception('同步返回地址有误', 440);
            }
        }

        // 小程序+微信环境中
        if(in_array($this->trade_type, ['JSAPI']))
        {
            // 同步返回地址判断
            if(empty($this->params['openid']))
            {
                throw new Exception('openid不能为空', 440);
            }
        }
    }

    /**
     * 支付返回处理
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-01-08
     * @desc    description
     * @param   [array]           $data         [支付返回数据]
     */
    private function PayHandleReturn($data = [])
    {
        switch($this->pay_params['trade_type'])
        {
            // web支付
            case 'NATIVE' :
                return $data['code_url'];
                break;

            // h5支付
            case 'MWEB' :
                $data['mweb_url'] .= '&redirect_url='.$this->params['return_url'];
                die(header('location:'.$data['mweb_url']));
                break;

            // 微信中/小程序支付
            case 'JSAPI' :
                $pay_data = array(
                    'appId'         => $this->pay_params['appid'],
                    'package'       => 'prepay_id='.$data['prepay_id'],
                    'nonceStr'      => md5(time().rand()),
                    'signType'      => $this->pay_params['sign_type'],
                    'timeStamp'     => (string) time(),
                );
                $pay_data['paySign'] = $this->GetSign($pay_data);

                // 微信环境中
                if($this->IsWeixinEnv())
                {
                    $this->PayHtml($pay_data);
                } else {
                    return $pay_data;
                }
                break;

            // APP支付
            case 'APP' :
                $pay_data = array(
                    'appid'         => $this->pay_params['appid'],
                    'partnerid'     => $this->pay_params['mch_id'],
                    'prepayid'      => $data['prepay_id'],
                    'package'       => 'Sign=WXPay',
                    'noncestr'      => md5(time().rand()),
                    'timestamp'     => (string) time(),
                );
                $pay_data['sign'] = $this->GetSign($pay_data);
                return $pay_data;
                break;
        }
        throw new Exception('接口错误', 470);
    }

     /**
     * 支付代码
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2019-05-25T00:07:52+0800
     * @param    [array]                   $pay_data     [支付信息]
     */
    private function PayHtml($pay_data)
    {
        exit('<html>
            <head>
                <meta http-equiv="content-type" content="text/html;charset=utf-8"/>
                <title>微信安全支付</title>
                <script type="text/javascript">
                    function onBridgeReady()
                    {
                       WeixinJSBridge.invoke(
                            \'getBrandWCPayRequest\', {
                                "appId":"'.$pay_data['appId'].'",
                                "timeStamp":"'.$pay_data['timeStamp'].'",
                                "nonceStr":"'.$pay_data['nonceStr'].'",
                                "package":"'.$pay_data['package'].'",     
                                "signType":"'.$pay_data['signType'].'",
                                "paySign":"'.$pay_data['paySign'].'"
                            },
                            function(res) {
                                window.location.href = "'.$this->params['return_url'].'";
                            }
                        ); 
                    }
                    if(typeof WeixinJSBridge == "undefined")
                    {
                       if( document.addEventListener )
                       {
                           document.addEventListener("WeixinJSBridgeReady", onBridgeReady, false);
                       } else if (document.attachEvent)
                       {
                           document.attachEvent("WeixinJSBridgeReady", onBridgeReady); 
                           document.attachEvent("onWeixinJSBridgeReady", onBridgeReady);
                       }
                    } else {
                       onBridgeReady();
                    }
                </script>
                </head>
            <body>
        </html>');
    }

    /**
     * 支付appid设置
     * @author  Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-07-08
     * @desc    description
     */
    private function SetPayAppId()
    {
        // appid匹配
        switch($this->params['client_type'])
        {
            case 'web' :
                if(is_array($this->public_appid) && array_key_exists($this->params['sourcename'], $this->public_appid))
                {
                    $this->pay_appid = $this->public_appid[$this->params['sourcename']];
                }
                break;

            case 'miniapp' :
                if(is_array($this->miniapp_appid) && array_key_exists($this->params['sourcename'], $this->miniapp_appid))
                {
                    $this->pay_appid = $this->miniapp_appid[$this->params['sourcename']];
                }
                break;

            // app
            case 'app' :
                $this->pay_appid = $this->app_appid;
                break;
        }
        if(empty($this->pay_appid))
        {
            throw new Exception('appid未匹配成功', 411);
        }
    }

    /**
     * 设置支付交易类型
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-01-08
     * @desc    description
     */
    private function SetTradeType()
    {
        // 类型匹配
        switch($this->params['client_type'])
        {
            case 'web' :
                if($this->IsMobile())
                {
                    if($this->IsWeixinEnv())
                    {
                        $this->trade_type = 'JSAPI';
                    } else {
                        $this->trade_type = 'MWEB';
                    }
                } else {
                    $this->trade_type = 'NATIVE';
                }
                break;

            case 'miniapp' :
                $this->trade_type = 'JSAPI';
                break;

            // app
            case 'app' :
                $this->trade_type = 'APP';
                break;
        }
        if(empty($this->trade_type))
        {
            throw new Exception('trade_type未匹配成功', 412);
        }
    }

    /**
     * 当前是否微信环境中
     * @author  Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-07-08
     * @desc    description
     */
    private function IsWeixinEnv()
    {
        return (!empty($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false);
    }

    /**
     * 设置支付参数
     * @author  Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-07-04
     * @desc    description
     */
    private function SetPayParams()
    {
        $this->pay_params = [
            'appid'             => $this->pay_appid,
            'mch_id'            => $this->mch_id,
            'body'              => $this->params['subject'],
            'nonce_str'         => md5(time().rand().$this->params['order_no']),
            'notify_url'        => $this->notify_url,
            'out_trade_no'      => $this->params['order_no'].$this->GetNumberCode(6),
            'spbill_create_ip'  => \PhalApi\Tool::getClientIp(),
            'total_fee'         => intval($this->params['total_amount']*100),
            'trade_type'        => $this->trade_type,
            'attach'            => empty($this->params['attach']) ? $this->params['order_no'] : $this->params['attach'],
            'sign_type'         => 'MD5',
        ];

        // 非APP有参数则使用使用openid
        if(!in_array($this->trade_type, ['APP']) && !empty($this->params['openid']))
        {
            $this->pay_params['openid'] = $this->params['openid'];
        }

        // 设置签名
        $this->pay_params['sign'] = $this->GetSign($this->pay_params);
    }

    /**
     * 支付回调处理
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2018-09-19
     * @desc    description
     * @param   [array]           $params [输入参数]
     */
    public function Respond($params = [])
    {
        $result = empty($GLOBALS['HTTP_RAW_POST_DATA']) ? $this->XmlToArray(file_get_contents('php://input')) : $this->XmlToArray($GLOBALS['HTTP_RAW_POST_DATA']);

        if(isset($result['result_code']) && $result['result_code'] == 'SUCCESS' && $result['sign'] == $this->GetSign($result))
        {
            return $this->ReturnData($result);
        }
        throw new Exception('处理异常错误', 400);
    }

    /**
     * [ReturnData 返回数据统一格式]
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2018-10-06T16:54:24+0800
     * @param    [array]                   $data [返回数据]
     */
    private function ReturnData($data)
    {
        // 参数处理
        $out_trade_no = substr($data['out_trade_no'], 0, strlen($data['out_trade_no'])-6);

        // 返回数据固定基础参数
        $data['trade_no']       = $data['transaction_id'];  // 支付平台 - 订单号
        $data['buyer_user']     = $data['openid'];          // 支付平台 - 用户
        $data['out_trade_no']   = $out_trade_no;            // 本系统发起支付的 - 订单号
        $data['subject']        = $data['attach'];          // 本系统发起支付的 - 商品名称
        $data['pay_amount']     = $data['total_fee']/100;   // 本系统发起支付的 - 总价
        return $data;
    }

    /**
     * 是否是手机访问
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  0.0.1
     * @datetime 2016-12-05T10:52:20+0800
     * @return  [boolean] [手机访问true, 则false]
     */
    private function IsMobile()
    {
        /* 如果有HTTP_X_WAP_PROFILE则一定是移动设备 */
        if(isset($_SERVER['HTTP_X_WAP_PROFILE'])) return true;
        
        /* 此条摘自TPM智能切换模板引擎，适合TPM开发 */
        if(isset($_SERVER['HTTP_CLIENT']) && 'PhoneClient' == $_SERVER['HTTP_CLIENT']) return true;
        
        /* 如果via信息含有wap则一定是移动设备,部分服务商会屏蔽该信息 */
        if(isset($_SERVER['HTTP_VIA']) && stristr($_SERVER['HTTP_VIA'], 'wap') !== false) return true;
        
        /* 判断手机发送的客户端标志,兼容性有待提高 */
        if(isset($_SERVER['HTTP_USER_AGENT']))
        {
            $clientkeywords = array(
                'nokia','sony','ericsson','mot','samsung','htc','sgh','lg','sharp','sie-','philips','panasonic','alcatel','lenovo','iphone','ipad','ipod','blackberry','meizu','android','netfront','symbian','ucweb','windowsce','palm','operamini','operamobi','openwave','nexusone','cldc','midp','wap','mobile'
            );
            /* 从HTTP_USER_AGENT中查找手机浏览器的关键字 */
            if(preg_match("/(" . implode('|', $clientkeywords) . ")/i", strtolower($_SERVER['HTTP_USER_AGENT']))) {
                return true;
            }
        }

        /* 协议法，因为有可能不准确，放到最后判断 */
        if(isset($_SERVER['HTTP_ACCEPT']))
        {
            /* 如果只支持wml并且不支持html那一定是移动设备 */
            /* 如果支持wml和html但是wml在html之前则是移动设备 */
            if((strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') !== false) && (strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === false || (strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') < strpos($_SERVER['HTTP_ACCEPT'], 'text/html')))) return true;
        }
        return false;
    }

    /**
     * 随机数生成生成
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  0.0.1
     * @datetime 2016-12-03T21:58:54+0800
     * @param    [int] $length [生成位数]
     * @return   [int]         [生成的随机数]
     */
    private function GetNumberCode($length = 6)
    {
        $code = '';
        for($i=0; $i<intval($length); $i++) $code .= rand(0, 9);
        return $code;
    }

    /**
     * 签名生成
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-01-07
     * @desc    description
     * @param   [array]           $params [输入参数]
     */
    private function GetSign($params = [])
    {
        ksort($params);
        $sign  = '';
        foreach($params as $k=>$v)
        {
            if($k != 'sign' && $v != '' && $v != null)
            {
                $sign .= "$k=$v&";
            }
        }
        return strtoupper(md5($sign.'key='.$this->key));
    }

    /**
     * 数组转xml
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-01-07
     * @desc    description
     * @param   [array]          $data [数组]
     */
    private function ArrayToXml($data)
    {
        $xml = '<xml>';
        foreach($data as $k=>$v)
        {
            $xml .= '<'.$k.'>'.$v.'</'.$k.'>';
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * xml转数组
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-01-07
     * @desc    description
     * @param   [string]          $xml [xm数据]
     */
    private function XmlToArray($xml)
    {
        if(!$this->XmlParser($xml))
        {
            return is_string($xml) ? $xml : '接口返回数据有误';
        }

        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }


    /**
     * 判断字符串是否为xml格式
     * @author   Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @date    2019-01-07
     * @desc    description
     * @param   [string]          $string [字符串]
     */
    private function XmlParser($string)
    {
        $xml_parser = xml_parser_create();
        if(!xml_parse($xml_parser, $string, true))
        {
          xml_parser_free($xml_parser);
          return false;
        } else {
          return (json_decode(json_encode(simplexml_load_string($string)),true));
        }
    }

    /**
     * 网络请求
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2017-09-25T09:10:46+0800
     * @param    [string]          $url         [请求url]
     * @param    [array]           $data        [发送数据]
     * @param    [boolean]         $use_cert    [是否需要使用证书]
     * @param    [int]             $second      [超时]
     * @return   [mixed]                        [请求返回数据]
     */
    private function HttpRequest($url, $data, $use_cert = false, $second = 30)
    {
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => $second,
        );

        if($use_cert == true)
        {
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            $options[CURLOPT_SSLCERTTYPE] = 'PEM';
            $options[CURLOPT_SSLCERT] = $this->apiclient_cert_dir;
            $options[CURLOPT_SSLKEYTYPE] = 'PEM';
            $options[CURLOPT_SSLKEY] = $this->apiclient_key_dir;
        }
 
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        //返回结果
        if($result)
        {
            curl_close($ch);
            return $result;
        } else { 
            $error = curl_errno($ch);
            curl_close($ch);
            return "curl出错，错误码:$error";
        }
    }
}
?>
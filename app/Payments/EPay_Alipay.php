<?php

namespace App\Payments;

/**
 * 支付宝易支付接口 - 专用版
 */
class EPay_Alipay {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '支付网关地址，例如：https://xxxx.com',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '商户ID',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '商户密钥',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        // 构造支付参数
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'type' => 'alipay', // 固定为支付宝支付
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        
        // 按照签名规则排序
        ksort($params);
        reset($params);
        
        // 生成签名字符串
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        
        // 计算MD5签名
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        
        // 返回完整支付URL
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        // 获取签名
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        
        // 排序参数
        ksort($params);
        reset($params);
        
        // 生成签名字符串
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        
        // 验证签名
        if ($sign !== md5($str)) {
            return false;
        }
        
        // 返回订单信息
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }
} 
<?php

/**
 * 虎皮椒支付网关
 * 支持微信支付和支付宝支付
 * API文档: https://www.xunhupay.com/doc/api/pay.html
 */
namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XunhuPay implements PaymentInterface
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * 获取支付网关配置表单
     */
    public function form(): array
    {
        return [
            'api_url' => [
                'label' => 'API接口地址',
                'description' => '虎皮椒支付API地址，默认: https://api.xunhupay.com/payment/do.html',
                'type' => 'input',
                'default' => 'https://api.xunhupay.com/payment/do.html'
            ],
            'appid' => [
                'label' => 'APPID',
                'description' => '虎皮椒分配的APPID（不是微信小程序APPID）',
                'type' => 'input',
            ],
            'appsecret' => [
                'label' => 'APPSECRET',
                'description' => '虎皮椒分配的密钥',
                'type' => 'input',
            ],
            'payment_type' => [
                'label' => '支付类型',
                'description' => '选择支付通道类型',
                'type' => 'select',
                'options' => [
                    'wechat' => '微信支付',
                    'alipay' => '支付宝支付'
                ]
            ],
            'wap_name' => [
                'label' => '网站名称',
                'description' => '店铺名称或网站名称，长度32字符以内（H5支付必填）',
                'type' => 'input',
            ],
            'wap_url' => [
                'label' => '网站域名',
                'description' => '您的网站域名，如: https://example.com（H5支付必填）',
                'type' => 'input',
            ]
        ];
    }

    /**
     * 发起支付
     */
    public function pay($order): array
    {
        try {
            // 构建请求参数
            $params = [
                'version' => '1.1',
                'appid' => $this->config['appid'],
                'trade_order_id' => $order['trade_no'],
                'total_fee' => sprintf('%.2f', $order['total_amount'] / 100), // 转换为元
                'title' => admin_setting('app_name', 'XBoard') . ' - 订阅服务',
                'time' => time(),
                'notify_url' => $order['notify_url'],
                'return_url' => $order['return_url'],
                'nonce_str' => $this->generateNonceStr(),
            ];

            // 添加备注信息
            if (isset($order['user_id'])) {
                $params['attach'] = 'user_id:' . $order['user_id'];
            }

            // 根据支付类型添加特定参数
            if ($this->config['payment_type'] === 'wechat') {
                $params['type'] = 'WAP'; // 微信H5支付
                $params['wap_url'] = $this->config['wap_url'];
                $params['wap_name'] = $this->config['wap_name'];
            }

            // 生成签名
            $params['hash'] = $this->generateSign($params);

            // 发送请求
            $response = Http::timeout(30)
                ->asForm()
                ->post($this->config['api_url'], $params);

            if (!$response->successful()) {
                throw new ApiException('虎皮椒支付接口请求失败: HTTP ' . $response->status());
            }

            $result = $response->json();

            // 检查返回结果
            if (!$result || !isset($result['errcode'])) {
                throw new ApiException('虎皮椒支付接口返回数据格式错误');
            }

            if ($result['errcode'] !== 0) {
                $errorMsg = $result['errmsg'] ?? '未知错误';
                throw new ApiException('虎皮椒支付失败: ' . $errorMsg);
            }

            // 验证返回签名
            if (!$this->verifyReturnSign($result)) {
                throw new ApiException('虎皮椒支付返回签名验证失败');
            }

            // 记录成功日志
            Log::info('虎皮椒支付发起成功', [
                'trade_no' => $order['trade_no'],
                'amount' => $order['total_amount'],
                'payment_type' => $this->config['payment_type']
            ]);

            // 返回支付URL
            return [
                'type' => 1, // 1: 跳转URL
                'data' => $result['url']
            ];

        } catch (\Exception $e) {
            Log::error('虎皮椒支付发起失败', [
                'trade_no' => $order['trade_no'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($e instanceof ApiException) {
                throw $e;
            }
            
            throw new ApiException('虎皮椒支付发起失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理支付回调通知
     */
    public function notify($params): array|bool
    {
        try {
            // 验证必要参数
            if (!isset($params['hash']) || !isset($params['trade_order_id'])) {
                Log::warning('虎皮椒支付回调参数不完整', $params);
                return false;
            }

            // 验证签名
            if (!$this->verifyNotifySign($params)) {
                Log::warning('虎皮椒支付回调签名验证失败', $params);
                return false;
            }

            // 检查订单状态
            if (!isset($params['status']) || $params['status'] !== 'OD') {
                Log::info('虎皮椒支付回调订单状态非已支付', [
                    'trade_order_id' => $params['trade_order_id'],
                    'status' => $params['status'] ?? 'unknown'
                ]);
                return false;
            }

            // 记录成功回调
            Log::info('虎皮椒支付回调成功', [
                'trade_order_id' => $params['trade_order_id'],
                'transaction_id' => $params['transaction_id'] ?? '',
                'total_fee' => $params['total_fee'] ?? 0
            ]);

            // 返回标准格式
            return [
                'trade_no' => $params['trade_order_id'],
                'callback_no' => $params['transaction_id'] ?? $params['open_order_id'] ?? '',
                'custom_result' => 'success' // 虎皮椒要求返回success
            ];

        } catch (\Exception $e) {
            Log::error('虎皮椒支付回调处理异常', [
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 生成签名
     */
    private function generateSign(array $params): string
    {
        // 移除hash参数
        unset($params['hash']);
        
        // 移除空值参数
        $params = array_filter($params, function($value) {
            return $value !== null && $value !== '';
        });

        // 按键名ASCII码排序
        ksort($params);

        // 构建签名字符串
        $signStr = http_build_query($params) . $this->config['appsecret'];

        return md5($signStr);
    }

    /**
     * 验证返回签名
     */
    private function verifyReturnSign(array $data): bool
    {
        if (!isset($data['hash'])) {
            return false;
        }

        $hash = $data['hash'];
        unset($data['hash']);

        $calculatedHash = $this->generateSign($data);
        
        return hash_equals($hash, $calculatedHash);
    }

    /**
     * 验证回调通知签名
     */
    private function verifyNotifySign(array $params): bool
    {
        if (!isset($params['hash'])) {
            return false;
        }

        $hash = $params['hash'];
        $verifyParams = $params;
        unset($verifyParams['hash']);

        $calculatedHash = $this->generateSign($verifyParams);
        
        return hash_equals($hash, $calculatedHash);
    }

    /**
     * 生成随机字符串
     */
    private function generateNonceStr(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }
}

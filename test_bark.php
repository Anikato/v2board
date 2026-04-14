<?php

/**
 * Bark 通知测试脚本
 * 用于诊断 Bark 通知功能
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Bark 通知诊断工具 ===\n\n";

// 1. 检查环境变量
echo "1. 检查环境变量配置\n";
echo "-------------------\n";
$barkUrl = config('bark.url');
$barkKey = config('bark.key');

echo "BARK_URL: " . ($barkUrl ?: '未配置') . "\n";
echo "BARK_KEY: " . ($barkKey ?: '未配置') . "\n";

if (empty($barkUrl) || empty($barkKey)) {
    echo "\n❌ 错误: Bark 配置未设置！\n";
    echo "请在 .env 文件中添加:\n";
    echo "BARK_URL=https://api.day.app\n";
    echo "BARK_KEY=你的设备Key\n\n";
    exit(1);
}

echo "✅ 配置已设置\n\n";

// 2. 检查 BarkService 类
echo "2. 检查 BarkService 类\n";
echo "-------------------\n";
if (class_exists('App\Services\BarkService')) {
    echo "✅ BarkService 类存在\n\n";
} else {
    echo "❌ 错误: BarkService 类不存在！\n\n";
    exit(1);
}

// 3. 测试发送通知
echo "3. 测试发送通知\n";
echo "-------------------\n";
echo "正在发送测试通知...\n";

try {
    $result = \App\Services\BarkService::send(
        '🧪 测试通知',
        '这是一条来自 V2Board 的测试通知',
        [
            'group' => 'V2Board测试',
            'sound' => 'bell'
        ]
    );
    
    if ($result) {
        echo "✅ 通知发送成功！\n";
        echo "请检查你的 iOS 设备是否收到通知\n\n";
    } else {
        echo "❌ 通知发送失败！\n";
        echo "请查看日志: storage/logs/laravel.log\n\n";
    }
} catch (\Exception $e) {
    echo "❌ 发送异常: " . $e->getMessage() . "\n\n";
}

// 4. 检查最近的订单
echo "4. 检查最近的订单\n";
echo "-------------------\n";
try {
    $order = \App\Models\Order::orderBy('id', 'desc')->first();
    if ($order) {
        echo "最新订单 ID: " . $order->id . "\n";
        echo "订单号: " . $order->trade_no . "\n";
        echo "用户 ID: " . $order->user_id . "\n";
        echo "套餐 ID: " . $order->plan_id . "\n";
        echo "金额: " . ($order->total_amount / 100) . " 元\n";
        echo "状态: " . $order->status . " (0=待支付, 1=开通中, 2=已取消, 3=已完成, 4=已折抵)\n";
        echo "创建时间: " . date('Y-m-d H:i:s', $order->created_at) . "\n\n";
        
        // 测试发送订单通知
        echo "5. 测试发送订单通知\n";
        echo "-------------------\n";
        echo "正在发送订单通知...\n";
        
        $result = \App\Services\BarkService::sendNewOrderNotification($order);
        if ($result) {
            echo "✅ 订单通知发送成功！\n\n";
        } else {
            echo "❌ 订单通知发送失败！\n\n";
        }
    } else {
        echo "没有找到订单记录\n\n";
    }
} catch (\Exception $e) {
    echo "❌ 查询订单异常: " . $e->getMessage() . "\n\n";
}

// 6. 检查日志
echo "6. 查看最近的 Bark 日志\n";
echo "-------------------\n";
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $lines = file($logFile);
    $barkLogs = array_filter($lines, function($line) {
        return strpos($line, 'Bark') !== false;
    });
    
    if (count($barkLogs) > 0) {
        echo "最近的 Bark 日志:\n";
        $recentLogs = array_slice($barkLogs, -5);
        foreach ($recentLogs as $log) {
            echo $log;
        }
    } else {
        echo "没有找到 Bark 相关日志\n";
    }
} else {
    echo "日志文件不存在\n";
}

echo "\n=== 诊断完成 ===\n";

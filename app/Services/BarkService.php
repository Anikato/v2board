<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BarkService
{
    /**
     * 发送 Bark 通知
     *
     * @param string $title 通知标题
     * @param string $body 通知内容
     * @param array $options 额外选项 (url, group, icon, sound 等)
     * @return bool
     */
    public static function send(string $title, string $body = '', array $options = []): bool
    {
        $barkUrl = config('bark.url');
        $barkKey = config('bark.key');

        // 如果未配置 Bark，直接返回 true（不影响主流程）
        if (empty($barkUrl) || empty($barkKey)) {
            Log::debug('Bark: Not configured, skipping notification', [
                'bark_url' => $barkUrl,
                'bark_key_set' => !empty($barkKey)
            ]);
            return true;
        }

        try {
            // 构建 Bark API URL
            $url = rtrim($barkUrl, '/') . '/' . $barkKey;

            // 准备请求参数
            $params = [
                'title' => $title,
                'body' => $body,
            ];

            // 合并额外选项
            if (!empty($options['url'])) {
                $params['url'] = $options['url'];
            }
            if (!empty($options['group'])) {
                $params['group'] = $options['group'];
            }
            if (!empty($options['icon'])) {
                $params['icon'] = $options['icon'];
            }
            if (!empty($options['sound'])) {
                $params['sound'] = $options['sound'];
            }
            if (!empty($options['badge'])) {
                $params['badge'] = $options['badge'];
            }
            if (!empty($options['level'])) {
                $params['level'] = $options['level'];
            }

            Log::info('Bark: Sending notification', [
                'url' => $url,
                'title' => $title,
                'params' => $params
            ]);

            // 发送 POST 请求
            $response = Http::timeout(5)->post($url, $params);

            if ($response->successful()) {
                Log::info('Bark notification sent successfully', [
                    'title' => $title,
                    'body' => $body,
                    'response' => $response->json()
                ]);
                return true;
            } else {
                Log::warning('Bark notification failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'title' => $title
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Bark notification error: ' . $e->getMessage(), [
                'title' => $title,
                'body' => $body,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * 发送新订单通知
     *
     * @param \App\Models\Order $order
     * @return bool
     */
    public static function sendNewOrderNotification($order): bool
    {
        try {
            // 确保加载关联数据
            if (!$order->relationLoaded('user')) {
                $order->load('user');
            }
            
            $user = $order->user;
            if (!$user) {
                Log::warning('Bark: Order user not found', ['order_id' => $order->id]);
                return false;
            }
            
            $plan = \App\Models\Plan::find($order->plan_id);
            if (!$plan) {
                Log::warning('Bark: Order plan not found', [
                    'order_id' => $order->id,
                    'plan_id' => $order->plan_id
                ]);
                // 即使没有套餐信息，也尝试发送通知
            }

            $title = '💰 新订单提醒';
            $body = sprintf(
                "用户: %s\n套餐: %s\n金额: ¥%.2f\n订单号: %s",
                $user->email ?? 'Unknown',
                $plan->name ?? 'Unknown',
                $order->total_amount / 100,
                $order->trade_no
            );

            $options = [
                'group' => 'V2Board订单',
                'sound' => 'bell',
                'url' => config('app.url') . '/admin/order/' . $order->id,
            ];

            Log::info('Bark: Sending new order notification', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'plan_id' => $order->plan_id
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendNewOrderNotification failed', [
                'order_id' => $order->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * 发送订单支付成功通知
     *
     * @param \App\Models\Order $order
     * @return bool
     */
    public static function sendOrderPaidNotification($order): bool
    {
        try {
            // 确保加载关联数据
            if (!$order->relationLoaded('user')) {
                $order->load('user');
            }
            
            $user = $order->user;
            if (!$user) {
                Log::warning('Bark: Order user not found for paid notification', ['order_id' => $order->id]);
                return false;
            }
            
            $plan = \App\Models\Plan::find($order->plan_id);
            if (!$plan) {
                Log::warning('Bark: Order plan not found for paid notification', [
                    'order_id' => $order->id,
                    'plan_id' => $order->plan_id
                ]);
            }

            $title = '✅ 订单支付成功';
            $body = sprintf(
                "用户: %s\n套餐: %s\n金额: ¥%.2f\n支付时间: %s",
                $user->email ?? 'Unknown',
                $plan->name ?? 'Unknown',
                $order->total_amount / 100,
                date('Y-m-d H:i:s', $order->paid_at)
            );

            $options = [
                'group' => 'V2Board订单',
                'sound' => 'payment',
                'level' => 'timeSensitive',
                'url' => config('app.url') . '/admin/order/' . $order->id,
            ];

            Log::info('Bark: Sending order paid notification', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'plan_id' => $order->plan_id
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendOrderPaidNotification failed', [
                'order_id' => $order->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * 发送订单取消通知
     *
     * @param \App\Models\Order $order
     * @return bool
     */
    public static function sendOrderCancelledNotification($order): bool
    {
        try {
            if (!$order->relationLoaded('user')) {
                $order->load('user');
            }
            
            $user = $order->user;
            if (!$user) {
                Log::warning('Bark: Order user not found for cancel notification', ['order_id' => $order->id]);
                return false;
            }
            
            $plan = \App\Models\Plan::find($order->plan_id);

            $title = '❌ 订单已取消';
            $body = sprintf(
                "用户: %s\n套餐: %s\n金额: ¥%.2f\n订单号: %s\n取消时间: %s",
                $user->email ?? 'Unknown',
                $plan->name ?? 'Unknown',
                $order->total_amount / 100,
                $order->trade_no,
                date('Y-m-d H:i:s')
            );

            $options = [
                'group' => 'V2Board订单',
                'sound' => 'minuet',
            ];

            Log::info('Bark: Sending order cancelled notification', [
                'order_id' => $order->id,
                'user_id' => $user->id
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendOrderCancelledNotification failed', [
                'order_id' => $order->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 发送订单完成通知
     *
     * @param \App\Models\Order $order
     * @return bool
     */
    public static function sendOrderCompletedNotification($order): bool
    {
        try {
            if (!$order->relationLoaded('user')) {
                $order->load('user');
            }
            
            $user = $order->user;
            if (!$user) {
                Log::warning('Bark: Order user not found for completed notification', ['order_id' => $order->id]);
                return false;
            }
            
            $plan = \App\Models\Plan::find($order->plan_id);

            $title = '🎉 订单已完成';
            $body = sprintf(
                "用户: %s\n套餐: %s\n金额: ¥%.2f\n订单号: %s\n完成时间: %s",
                $user->email ?? 'Unknown',
                $plan->name ?? 'Unknown',
                $order->total_amount / 100,
                $order->trade_no,
                date('Y-m-d H:i:s')
            );

            $options = [
                'group' => 'V2Board订单',
                'sound' => 'fanfare',
                'url' => config('app.url') . '/admin/order/' . $order->id,
            ];

            Log::info('Bark: Sending order completed notification', [
                'order_id' => $order->id,
                'user_id' => $user->id
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendOrderCompletedNotification failed', [
                'order_id' => $order->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 发送用户注册通知
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public static function sendUserRegisteredNotification($user): bool
    {
        try {
            $title = '👤 新用户注册';
            $body = sprintf(
                "邮箱: %s\n注册时间: %s\n邀请人ID: %s",
                $user->email ?? 'Unknown',
                date('Y-m-d H:i:s', $user->created_at),
                $user->invite_user_id ?? '无'
            );

            $options = [
                'group' => 'V2Board用户',
                'sound' => 'newmail',
                'url' => config('app.url') . '/admin/user/' . $user->id,
            ];

            Log::info('Bark: Sending user registered notification', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendUserRegisteredNotification failed', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 发送工单创建通知
     *
     * @param \App\Models\Ticket $ticket
     * @return bool
     */
    public static function sendTicketCreatedNotification($ticket): bool
    {
        try {
            $user = \App\Models\User::find($ticket->user_id);
            if (!$user) {
                Log::warning('Bark: Ticket user not found', ['ticket_id' => $ticket->id]);
                return false;
            }

            $title = '🎫 新工单提交';
            $body = sprintf(
                "用户: %s\n主题: %s\n级别: %s\n工单ID: #%d\n创建时间: %s",
                $user->email ?? 'Unknown',
                $ticket->subject ?? 'Unknown',
                $ticket->level == 0 ? '低' : ($ticket->level == 1 ? '中' : '高'),
                $ticket->id,
                date('Y-m-d H:i:s', $ticket->created_at)
            );

            $options = [
                'group' => 'V2Board工单',
                'sound' => 'alarm',
                'level' => $ticket->level >= 2 ? 'timeSensitive' : 'active',
                'url' => config('app.url') . '/admin/ticket/' . $ticket->id,
            ];

            Log::info('Bark: Sending ticket created notification', [
                'ticket_id' => $ticket->id,
                'user_id' => $user->id
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendTicketCreatedNotification failed', [
                'ticket_id' => $ticket->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 发送工单回复通知
     *
     * @param \App\Models\Ticket $ticket
     * @param \App\Models\TicketMessage $message
     * @return bool
     */
    public static function sendTicketRepliedNotification($ticket, $message): bool
    {
        try {
            $user = \App\Models\User::find($ticket->user_id);
            if (!$user) {
                Log::warning('Bark: Ticket user not found for reply', ['ticket_id' => $ticket->id]);
                return false;
            }

            $title = '💬 工单有新回复';
            $messagePreview = mb_substr($message->message, 0, 50);
            if (mb_strlen($message->message) > 50) {
                $messagePreview .= '...';
            }

            $body = sprintf(
                "用户: %s\n工单: %s\n回复内容: %s\n回复时间: %s",
                $user->email ?? 'Unknown',
                $ticket->subject ?? 'Unknown',
                $messagePreview,
                date('Y-m-d H:i:s', $message->created_at)
            );

            $options = [
                'group' => 'V2Board工单',
                'sound' => 'telegraph',
                'url' => config('app.url') . '/admin/ticket/' . $ticket->id,
            ];

            Log::info('Bark: Sending ticket replied notification', [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id
            ]);

            return self::send($title, $body, $options);
        } catch (\Exception $e) {
            Log::error('Bark: sendTicketRepliedNotification failed', [
                'ticket_id' => $ticket->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        if ($id) $payment = Payment::find($id)->toArray();
        if ($uuid) $payment = Payment::where('uuid', $uuid)->first()->toArray();
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // LOCAL: prefer v2board.app_url so subdirectory deploys get a stable host.
        $appUrl = config('v2board.app_url') ?: config('app.url');

        $parsedUrl = parse_url($appUrl);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $baseUrl .= ':' . $parsedUrl['port'];
        }

        // custom notify domain name
        $notifyUrl = $baseUrl . "/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}";
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        // LOCAL default: app_url including path (subdirectory) + hash order page.
        $returnBaseUrl = rtrim($appUrl, '/');
        $defaultReturnUrl = $returnBaseUrl . '/#/order/' . $order['trade_no'];

        // Upstream: split frontend uses Origin / X-Forwarded-Host.
        // Only override when the host differs, so same-host subdirectory path is kept.
        $currentBase = $this->currentBase();
        if ($currentBase) {
            $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
            $currentHost = strtolower((string) parse_url($currentBase, PHP_URL_HOST));
            if ($currentHost !== '' && $currentHost !== $appHost) {
                $defaultReturnUrl = $currentBase . '/#/order/' . $order['trade_no'];
            }
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            // LOCAL: default theme omits return_url; x-user can pass one to return to checkout origin.
            'return_url' => $this->resolvePayReturnUrl($order, $appUrl, $defaultReturnUrl),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    /**
     * LOCAL: pick pay return_url from the frontend that started checkout.
     * Default theme does not send return_url → `{app_url}/#/order/{trade_no}`
     * (or split-frontend origin when currentBase host differs).
     * x-user sends its current origin `/order?trade_no=`. Host must match app_url,
     * optional user_frontend_url, or the request Origin / forwarded host.
     */
    private function resolvePayReturnUrl(array $order, $appUrl, $defaultReturnUrl)
    {
        $requested = $order['return_url'] ?? null;
        if (!is_string($requested) || $requested === '') {
            return $defaultReturnUrl;
        }

        $parts = parse_url($requested);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $defaultReturnUrl;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $defaultReturnUrl;
        }

        $host = strtolower($parts['host']);
        $allowed = [];
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $allowed[] = strtolower($appHost);
        }

        $extra = config('v2board.user_frontend_url');
        if (is_string($extra) && $extra !== '') {
            foreach (preg_split('/\s*,\s*/', $extra) as $url) {
                $extraHost = parse_url($url, PHP_URL_HOST);
                if (is_string($extraHost) && $extraHost !== '') {
                    $allowed[] = strtolower($extraHost);
                }
            }
        }

        $currentBase = $this->currentBase();
        if (is_string($currentBase) && $currentBase !== '') {
            $currentHost = parse_url($currentBase, PHP_URL_HOST);
            if (is_string($currentHost) && $currentHost !== '') {
                $allowed[] = strtolower($currentHost);
            }
        }

        if (!in_array($host, array_unique($allowed), true)) {
            return $defaultReturnUrl;
        }

        $marker = ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '') . '#' . ($parts['fragment'] ?? '');
        if (stripos($marker, 'order') === false && stripos($marker, 'trade_no') === false) {
            return $defaultReturnUrl;
        }

        return $requested;
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }

    private function currentBase()
    {
        $origin = request()->header('Origin');

        if ($origin && preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+$#i', $origin)) {
            return rtrim($origin, '/');
        }

        $host = request()->header('X-Forwarded-Host')
            ?: request()->header('X-Original-Host')
            ?: request()->header('Host');

        if ($host) {
            $host = trim(explode(',', $host)[0]);

            if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
                $scheme = request()->header('X-Forwarded-Proto')
                    ?: (request()->secure() ? 'https' : 'http');
                return $scheme . '://' . $host;
            }
        }

        return '';
    }
}

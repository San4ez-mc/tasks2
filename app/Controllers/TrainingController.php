<?php
/**
 * Контролер навчання "Впровадження системи" (WayForPay)
 */

namespace App\Controllers;

use App\Models\Database;

class TrainingController
{
    private const TABLE = 'training_orders';

    // =========================================================
    // Ініціювати оплату навчання
    // =========================================================
    public function pay(): void
    {
        $user = get_user();
        if (!$user) {
            redirect('/auth/login');
        }

        $plan_key = (string) post_param('plan', '');
        $plans    = TRAINING_PLANS;

        if (!isset($plans[$plan_key])) {
            flash('error', 'Невідомий тарифний план навчання.');
            redirect('/account/settings#training');
        }

        $this->ensure_table();

        $plan       = $plans[$plan_key];
        $price      = (float) $plan['price_usd'];
        $amount_str = number_format($price, 2, '.', '');
        $currency   = 'USD';
        $order_ref  = 'train_' . (int) $user['id'] . '_' . $plan_key . '_' . time();
        $order_date = time();
        $product_name = 'FINEKO Впровадження системи: ' . $plan['name'] . ' (' . $plan['participants'] . ')';

        $signature = $this->wfp_sign(
            WFP_MERCHANT_LOGIN,
            WFP_MERCHANT_DOMAIN,
            $order_ref,
            $order_date,
            $amount_str,
            $currency,
            [$product_name],
            [1],
            [$amount_str]
        );

        $callback_url = rtrim(APP_URL, '/') . '/training/callback';
        $return_url   = rtrim(APP_URL, '/') . '/account/settings?train_paid=1#training';
        $cancel_url   = rtrim(APP_URL, '/') . '/account/settings?train_cancel=1#training';

        // Зберегти замовлення
        $db = new Database();
        $db->insert(self::TABLE, [
            'user_id'       => (int) $user['id'],
            'plan'          => $plan_key,
            'amount_usd'    => $price,
            'status'        => 'pending',
            'wfp_order_ref' => $order_ref,
        ]);

        $fields = [
            'merchantAccount'               => WFP_MERCHANT_LOGIN,
            'merchantAuthType'              => 'SimpleSignature',
            'merchantDomainName'            => WFP_MERCHANT_DOMAIN,
            'merchantTransactionSecureType' => 'AUTO_RETURN',
            'orderReference'                => $order_ref,
            'orderDate'                     => $order_date,
            'amount'                        => $amount_str,
            'currency'                      => $currency,
            'productName[]'                 => $product_name,
            'productCount[]'                => 1,
            'productPrice[]'                => $amount_str,
            'merchantSignature'             => $signature,
            'language'                      => 'UA',
            'returnUrl'                     => $return_url,
            'cancelUrl'                     => $cancel_url,
            'serviceUrl'                    => $callback_url,
        ];

        // Debug-режим: показати поля перед відправкою (тільки для адміна)
        if (isset($_GET['debug']) && APP_DEBUG) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "WFP_PAY_URL: " . WFP_PAY_URL . "\n\n";
            foreach ($fields as $k => $v) {
                echo "$k = $v\n";
            }
            echo "\nSignature string: " . implode(';', array_merge(
                [WFP_MERCHANT_LOGIN, WFP_MERCHANT_DOMAIN, $order_ref, $order_date, $amount_str, $currency],
                [$product_name], [1], [$amount_str]
            )) . "\n";
            exit();
        }

        echo $this->render_form(WFP_PAY_URL, $fields);
        exit();
    }

    // =========================================================
    // WayForPay IPN callback
    // =========================================================
    public function callback(): void
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid payload']);
            exit();
        }

        $order_ref = (string) ($data['orderReference'] ?? '');
        $status    = (string) ($data['transactionStatus'] ?? '');

        $incoming_sig = (string) ($data['merchantSignature'] ?? '');
        $expected_sig = $this->wfp_sign_callback($data);

        if (!hash_equals($expected_sig, $incoming_sig)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'invalid signature']);
            exit();
        }

        if ($status === 'Approved') {
            $this->ensure_table();
            $db  = new Database();
            $row = $db->query('SELECT * FROM ' . self::TABLE . ' WHERE wfp_order_ref = :ref LIMIT 1')
                ->bind(':ref', $order_ref)
                ->fetch();

            if ($row) {
                $db->query('UPDATE ' . self::TABLE . ' SET status = :st, paid_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->bind(':st', 'paid')
                    ->bind(':id', (int) $row['id'])
                    ->execute();

                // Сповістити адміна
                $plans  = TRAINING_PLANS;
                $pk     = (string) ($row['plan'] ?? '');
                $pname  = $plans[$pk]['name'] ?? $pk;
                $amount = number_format((float) ($row['amount_usd'] ?? 0), 2);
                notify_admin_telegram(
                    "🎓 <b>Нова оплата навчання!</b>\n" .
                    "Тариф: <b>{$pname}</b>\n" .
                    "Сума: <b>\${$amount}</b>\n" .
                    "Order: {$order_ref}"
                );
            }
        }

        $time    = time();
        $res_sig = hash_hmac('md5', WFP_MERCHANT_LOGIN . ';' . $order_ref . ';accept;' . $time, WFP_MERCHANT_SECRET);

        header('Content-Type: application/json');
        echo json_encode([
            'orderReference' => $order_ref,
            'status'         => 'accept',
            'time'           => $time,
            'signature'      => $res_sig,
        ]);
        exit();
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function wfp_sign(
        string $merchant,
        string $domain,
        string $orderRef,
        int    $orderDate,
        string $amount,
        string $currency,
        array  $productNames,
        array  $productCounts,
        array  $productPrices
    ): string {
        $parts = [$merchant, $domain, $orderRef, $orderDate, $amount, $currency];
        foreach ($productNames  as $v) $parts[] = $v;
        foreach ($productCounts as $v) $parts[] = $v;
        foreach ($productPrices as $v) $parts[] = $v;
        return hash_hmac('md5', implode(';', $parts), WFP_MERCHANT_SECRET);
    }

    private function wfp_sign_callback(array $data): string
    {
        $parts = [
            WFP_MERCHANT_LOGIN,
            $data['orderReference']    ?? '',
            $data['amount']            ?? '',
            $data['currency']          ?? '',
            $data['authCode']          ?? '',
            $data['cardPan']           ?? '',
            $data['transactionStatus'] ?? '',
            $data['reasonCode']        ?? '',
        ];
        return hash_hmac('md5', implode(';', $parts), WFP_MERCHANT_SECRET);
    }

    private function render_form(string $action, array $fields): string
    {
        $html  = '<!DOCTYPE html><html><body>';
        $html .= '<form id="wfp" method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">';
        }
        $html .= '</form><script>document.getElementById("wfp").submit();</script></body></html>';
        return $html;
    }

    public function ensure_table(): void
    {
        $db = new Database();
        $db->query('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            plan          VARCHAR(20) NOT NULL,
            amount_usd    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status        VARCHAR(20) NOT NULL DEFAULT \'pending\',
            paid_at       DATETIME NULL,
            wfp_order_ref VARCHAR(255) NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_training_user   (user_id),
            KEY idx_training_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();
    }
}

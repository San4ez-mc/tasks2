<?php
/**
 * Контролер підписок (WayForPay)
 */

namespace App\Controllers;

use App\Models\Database;
use App\Models\Company;

class SubscriptionController
{
    private const TABLE = 'company_subscriptions';

    // =========================================================
    // ПУБЛІЧНИЙ: initiate payment
    // =========================================================
    public function pay(): void
    {
        $user = get_user();
        if (!$user) {
            redirect('/auth/login');
        }

        $company_id = (int) ($_SESSION['company_id'] ?? 0);
        if ($company_id <= 0) {
            flash('error', 'Активну компанію не знайдено.');
            redirect('/account/settings');
        }

        // Тільки owner може оплачувати
        if (!$this->is_owner($user['id'], $company_id)) {
            flash('error', 'Тільки власник компанії може керувати підпискою.');
            redirect('/account/settings');
        }

        $plan_key = (string) post_param('plan', '');
        $plans = SUBSCRIPTION_PLANS;

        if (!isset($plans[$plan_key])) {
            flash('error', 'Невідомий тарифний план.');
            redirect('/account/settings');
        }

        $this->ensure_table();

        $plan = $plans[$plan_key];
        $price = (float) $plan['price_usd'];
        // Format amount consistently: WayForPay requires decimal format
        $amount_str = number_format($price, 2, '.', '');
        $currency = 'USD';
        $order_ref = 'sub_' . $company_id . '_' . $plan_key . '_' . time();
        $order_date = time();
        // Keep product name ASCII-safe (no em dash)
        $product_name = 'FINEKO ' . $plan['name'] . ': ' . $plan['description'];
        $product_count = 1;

        $signature = $this->wfp_sign_payment(
            WFP_MERCHANT_LOGIN,
            WFP_MERCHANT_DOMAIN,
            $order_ref,
            $order_date,
            $amount_str,
            $currency,
            [$product_name],
            [$product_count],
            [$amount_str]
        );

        $callback_url = rtrim(APP_URL, '/') . '/account/subscription/callback';
        $return_url = rtrim(APP_URL, '/') . '/account/settings?sub_paid=1';
        $cancel_url = rtrim(APP_URL, '/') . '/account/settings?sub_cancel=1';

        // Зберегти pending-замовлення
        $this->upsert_subscription($company_id, [
            'plan' => $plan_key,
            'status' => 'pending',
            'member_limit' => $plan['member_limit'],
            'ai_bot_enabled' => $plan['ai_bot'] ? 1 : 0,
            'price_usd' => $price,
            'wfp_order_ref' => $order_ref,
        ]);

        // Рендерити auto-submit форму до WayForPay
        $fields = [
            'merchantAccount' => WFP_MERCHANT_LOGIN,
            'merchantAuthType' => 'SimpleSignature',
            'merchantDomainName' => WFP_MERCHANT_DOMAIN,
            'merchantTransactionSecureType' => 'AUTO_RETURN',
            'orderReference' => $order_ref,
            'orderDate' => $order_date,
            'amount' => $amount_str,
            'currency' => $currency,
            'productName[]' => $product_name,
            'productCount[]' => $product_count,
            'productPrice[]' => $amount_str,
            'merchantSignature' => $signature,
            'language' => 'UA',
            'returnUrl' => $return_url,
            'cancelUrl' => $cancel_url,
            'serviceUrl' => $callback_url,
        ];

        echo $this->render_pay_form(WFP_PAY_URL, $fields);
        exit();
    }

    // =========================================================
    // ПУБЛІЧНИЙ: WayForPay IPN callback
    // =========================================================
    public function callback(): void
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid payload']);
            exit();
        }

        $order_ref = (string) ($data['orderReference'] ?? '');
        $status = (string) ($data['transactionStatus'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);
        $currency = (string) ($data['currency'] ?? 'USD');

        // Перевірити підпис
        $incoming_sig = (string) ($data['merchantSignature'] ?? '');
        $expected_sig = $this->wfp_sign_callback($data);

        if (!hash_equals($expected_sig, $incoming_sig)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'invalid signature']);
            exit();
        }

        if ($status === 'Approved') {
            $this->ensure_table();
            $db = new Database();
            $sub = $db->query('SELECT * FROM ' . self::TABLE . ' WHERE wfp_order_ref = :ref LIMIT 1')
                ->bind(':ref', $order_ref)
                ->fetch();

            if ($sub) {
                $company_id = (int) $sub['company_id'];
                $db->query('UPDATE ' . self::TABLE . ' SET status = :st, paid_at = UTC_TIMESTAMP(), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 31 DAY), cancelled_at = NULL WHERE id = :id')
                    ->bind(':st', 'active')
                    ->bind(':id', (int) $sub['id'])
                    ->execute();

                // Сповістити адміна
                $plans   = SUBSCRIPTION_PLANS;
                $pk      = (string) ($sub['plan'] ?? '');
                $pname   = $plans[$pk]['name'] ?? $pk;
                $amount  = number_format((float) ($sub['price_usd'] ?? 0), 2);
                notify_admin_telegram(
                    "💳 <b>Нова оплата тарифу!</b>\n" .
                    "Компанія ID: <b>{$company_id}</b>\n" .
                    "Тариф: <b>{$pname}</b>\n" .
                    "Сума: <b>\${$amount}</b>\n" .
                    "Order: {$order_ref}"
                );
            }
        }

        // Відповідь WayForPay вимагає підтвердження
        $time = time();
        $res_sig = hash_hmac(
            'md5',
            WFP_MERCHANT_LOGIN . ';' . $order_ref . ';accept;' . $time,
            WFP_MERCHANT_SECRET
        );

        header('Content-Type: application/json');
        echo json_encode([
            'orderReference' => $order_ref,
            'status' => 'accept',
            'time' => $time,
            'signature' => $res_sig,
        ]);
        exit();
    }

    // =========================================================
    // ПУБЛІЧНИЙ: скасування підписки
    // =========================================================
    public function cancel(): void
    {
        $user = get_user();
        if (!$user) {
            redirect('/auth/login');
        }

        $company_id = (int) ($_SESSION['company_id'] ?? 0);
        if ($company_id <= 0) {
            flash('error', 'Активну компанію не знайдено.');
            redirect('/account/settings');
        }

        if (!$this->is_owner($user['id'], $company_id)) {
            flash('error', 'Тільки власник компанії може керувати підпискою.');
            redirect('/account/settings');
        }

        $this->ensure_table();
        $db = new Database();
        $db->query('UPDATE ' . self::TABLE . ' SET status = :st, cancelled_at = UTC_TIMESTAMP() WHERE company_id = :cid')
            ->bind(':st', 'cancelled')
            ->bind(':cid', $company_id)
            ->execute();

        flash('success', 'Підписку скасовано. Доступ зберігається до кінця оплаченого періоду.');
        redirect('/account/settings');
    }

    // =========================================================
    // ПУБЛІЧНИЙ: збереження списку членів після downgrade
    // =========================================================
    public function downgradeSave(): void
    {
        $user = get_user();
        if (!$user) {
            redirect('/auth/login');
        }

        $company_id = (int) ($_SESSION['company_id'] ?? 0);
        if ($company_id <= 0) {
            flash('error', 'Активну компанію не знайдено.');
            redirect('/account/settings');
        }

        if (!$this->is_owner($user['id'], $company_id)) {
            flash('error', 'Тільки власник може виконати цю дію.');
            redirect('/account/settings');
        }

        $keep_ids_raw = $_POST['keep_members'] ?? [];
        if (!is_array($keep_ids_raw)) {
            $keep_ids_raw = [];
        }

        $keep_ids = array_map('intval', $keep_ids_raw);
        $user_id = (int) $user['id'];

        // Owner завжди залишається
        if (!in_array($user_id, $keep_ids, true)) {
            $keep_ids[] = $user_id;
        }

        $limit = SUBSCRIPTION_FREE_MEMBER_LIMIT;
        if (count($keep_ids) > $limit) {
            flash('error', 'Можна залишити не більше ' . $limit . ' учасників на безкоштовному плані.');
            redirect('/account/settings');
        }

        $company_model = new Company();
        $all_employees = $company_model->get_employees($company_id);

        foreach ($all_employees as $emp) {
            $member_user_id = (int) ($emp['user_id'] ?? 0);
            if (!in_array($member_user_id, $keep_ids, true)) {
                $company_model->remove_employee($company_id, $member_user_id);
            }
        }

        // Скасувати підписку
        $this->ensure_table();
        $db = new Database();
        $db->query('UPDATE ' . self::TABLE . ' SET status = :st, cancelled_at = UTC_TIMESTAMP() WHERE company_id = :cid')
            ->bind(':st', 'cancelled')
            ->bind(':cid', $company_id)
            ->execute();

        flash('success', 'Підписку скасовано. Доступ збережено для ' . count($keep_ids) . ' учасників.');
        redirect('/account/settings');
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function is_owner(int $user_id, int $company_id): bool
    {
        $db = new Database();
        $row = $db->query("SELECT role FROM company_members WHERE company_id = :cid AND user_id = :uid LIMIT 1")
            ->bind(':cid', $company_id)
            ->bind(':uid', $user_id)
            ->fetch();

        return $row && strtolower(trim((string) ($row['role'] ?? ''))) === 'owner';
    }

    private function upsert_subscription(int $company_id, array $data): void
    {
        $db = new Database();
        $exists = $db->query('SELECT id FROM ' . self::TABLE . ' WHERE company_id = :cid LIMIT 1')
            ->bind(':cid', $company_id)
            ->fetch();

        if ($exists) {
            $set_parts = [];
            foreach ($data as $k => $v) {
                $set_parts[] = $k . ' = :' . $k;
            }
            $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $set_parts) . ' WHERE company_id = :company_id';
            $data['company_id'] = $company_id;
            $stmt = $db->query($sql);
            foreach ($data as $k => $v) {
                $stmt->bind(':' . $k, $v);
            }
            $stmt->execute();
        } else {
            $data['company_id'] = $company_id;
            $db->insert(self::TABLE, $data);
        }
    }

    private function wfp_sign_payment(
        string $merchant,
        string $domain,
        string $orderRef,
        int $orderDate,
        string $amount,
        string $currency,
        array $productNames,
        array $productCounts,
        array $productPrices
    ): string {
        $parts = [$merchant, $domain, $orderRef, $orderDate, $amount, $currency];
        foreach ($productNames as $v)
            $parts[] = $v;
        foreach ($productCounts as $v)
            $parts[] = $v;
        foreach ($productPrices as $v)
            $parts[] = $v;

        return hash_hmac('md5', implode(';', $parts), WFP_MERCHANT_SECRET);
    }

    private function wfp_sign_callback(array $data): string
    {
        $parts = [
            WFP_MERCHANT_LOGIN,
            $data['orderReference'] ?? '',
            $data['amount'] ?? '',
            $data['currency'] ?? '',
            $data['authCode'] ?? '',
            $data['cardPan'] ?? '',
            $data['transactionStatus'] ?? '',
            $data['reasonCode'] ?? '',
        ];

        return hash_hmac('md5', implode(';', $parts), WFP_MERCHANT_SECRET);
    }

    private function render_pay_form(string $action, array $fields): string
    {
        $html = '<!DOCTYPE html><html><body>';
        $html .= '<form id="wfp" method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("wfp").submit();</script>';
        $html .= '</body></html>';

        return $html;
    }

    public function ensure_table(): void
    {
        $db = new Database();
        $db->query('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            plan VARCHAR(20) NOT NULL DEFAULT \'free\',
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            member_limit INT NOT NULL DEFAULT 10,
            ai_bot_enabled TINYINT(1) NOT NULL DEFAULT 0,
            price_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_at DATETIME NULL,
            expires_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            wfp_order_ref VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_sub (company_id),
            KEY idx_sub_status (status),
            KEY idx_sub_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();
    }
}

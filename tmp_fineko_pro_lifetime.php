<?php
require 'config.php';
$db = new \App\Models\Database();
$company = $db->query("SELECT id, name FROM companies WHERE LOWER(name) = 'fineko' OR LOWER(name) LIKE '%fineko%' LIMIT 1")->fetch();
if (!$company) {
    echo "Not found\n";
    exit(1);
}
$cid = (int) $company['id'];
echo "Found: [{$cid}] {$company['name']}\n";
$plan_info = SUBSCRIPTION_PLANS['pro'];
$existing = $db->query('SELECT id FROM company_subscriptions WHERE company_id = :cid LIMIT 1')->bind(':cid', $cid)->fetch();
if ($existing) {
    $db->query('UPDATE company_subscriptions SET plan=:p, status=:s, member_limit=:ml, ai_bot_enabled=:ai, price_usd=0.00, paid_at=UTC_TIMESTAMP(), expires_at=\'2099-12-31 23:59:59\', cancelled_at=NULL, wfp_order_ref=\'gift_lifetime\' WHERE company_id=:cid')
        ->bind(':p', 'pro')->bind(':s', 'active')->bind(':ml', $plan_info['member_limit'])->bind(':ai', 1)->bind(':cid', $cid)->execute();
    echo "Updated\n";
} else {
    $db->insert('company_subscriptions', [
        'company_id' => $cid,
        'plan' => 'pro',
        'status' => 'active',
        'member_limit' => $plan_info['member_limit'],
        'ai_bot_enabled' => 1,
        'price_usd' => 0.00,
        'paid_at' => date('Y-m-d H:i:s'),
        'expires_at' => '2099-12-31 23:59:59',
        'cancelled_at' => null,
        'wfp_order_ref' => 'gift_lifetime',
    ]);
    echo "Inserted\n";
}
echo "Done — FINEKO Pro lifetime until 2099-12-31\n";

<?php
require __DIR__ . '/config.php';
require __DIR__ . '/app/Models/Database.php';

$db = new App\Models\Database();

// 1. Igor's exact user record
echo "=== Igor user 91 ===\n";
$u = $db->query("SELECT * FROM users WHERE id = 91")->fetch();
foreach ($u as $k => $v) {
    if ($v !== null && $v !== '') echo "  $k: $v\n";
}

// 2. Igor's companies via company_members
echo "\n=== Igor's company_members ===\n";
$cms = $db->query("SELECT cm.*, c.name as company_name FROM company_members cm LEFT JOIN companies c ON c.id = cm.company_id WHERE cm.user_id = 91")->fetchAll();
foreach ($cms as $cm) {
    echo "  cm_id={$cm['id']} company_id={$cm['company_id']} name=[{$cm['company_name']}] role={$cm['role']}\n";
}
if (empty($cms)) echo "  NO MEMBERSHIPS!\n";

// 3. Check if company 39 exists
echo "\n=== Company 39 ===\n";
$c = $db->query("SELECT * FROM companies WHERE id = 39")->fetch();
if ($c) {
    foreach ($c as $k => $v) {
        if ($v !== null && $v !== '') echo "  $k: $v\n";
    }
} else {
    echo "  Company 39 DOES NOT EXIST!\n";
}

// 4. Check what findCompaniesByUser would return
echo "\n=== findCompaniesByUser(91) simulation ===\n";
$result = $db->query("SELECT c.id, c.name FROM companies c JOIN company_members cm ON cm.company_id = c.id WHERE cm.user_id = 91 ORDER BY c.name ASC")->fetchAll();
foreach ($result as $r) {
    echo "  id={$r['id']} name=[{$r['name']}]\n";
}
if (empty($result)) echo "  EMPTY - this is why bot shows onboarding!\n";

// 5. Check storeOnboardingDraft path
echo "\n=== Onboarding draft files ===\n";
$draftDir = __DIR__ . '/storage/onboarding_drafts';
if (is_dir($draftDir)) {
    $files = glob($draftDir . '/*');
    foreach ($files as $f) {
        echo "  " . basename($f) . ": " . file_get_contents($f) . "\n";
    }
} else {
    echo "  Draft dir does not exist: $draftDir\n";
}

// 6. Check error_logs  
echo "\n=== Recent error_logs ===\n";
try {
    $cols = $db->query("SHOW COLUMNS FROM error_logs")->fetchAll();
    echo "Columns: ";
    foreach ($cols as $c) echo $c['Field'] . ', ';
    echo "\n";
    
    $errs = $db->query("SELECT * FROM error_logs ORDER BY id DESC LIMIT 10")->fetchAll();
    foreach ($errs as $e) {
        echo str_repeat('-', 60) . "\n";
        foreach ($e as $k => $v) {
            if ($v !== null && $v !== '') echo "  $k: " . mb_substr((string)$v, 0, 300) . "\n";
        }
    }
    if (empty($errs)) echo "  No errors\n";
} catch (Exception $ex) {
    echo "  " . $ex->getMessage() . "\n";
}

// 7. Check AI model config
echo "\n=== AI model used ===\n";
$configFiles = glob(__DIR__ . '/app/Services/*.php');
foreach ($configFiles as $f) {
    $content = file_get_contents($f);
    if (preg_match_all('/claude[^\'"]+/i', $content, $matches)) {
        echo "  " . basename($f) . ": " . implode(', ', array_unique($matches[0])) . "\n";
    }
    if (preg_match_all('/model.*?[\'"]([^"\']+)[\'"]/i', $content, $matches)) {
        foreach ($matches[1] as $m) {
            if (stripos($m, 'claude') !== false || stripos($m, 'gpt') !== false || stripos($m, 'whisper') !== false) {
                echo "  " . basename($f) . " model: $m\n";
            }
        }
    }
}

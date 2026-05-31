<?php
/**
 * Combined Setup + Seed Script
 * Creates business, admin user, default accounts, then seeds dummy data.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$db = Database::getInstance();

echo "🔧 AutoBooks Pro — Setup & Seed\n";
echo "═══════════════════════════════════════\n\n";

// ---- STEP 1: Create Business ----
echo "1️⃣  Creating business...\n";
$businessId = Database::uuid();
$db->insert('businesses', [
    'id' => $businessId,
    'name' => 'Car Mela Auto',
    'gstin' => '24AABCC1234D1Z5',
    'address' => 'Plot 45, Ring Road, Surat - 395002, Gujarat',
    'phone' => '9876543210',
    'email' => 'info@carmela.com',
    'fy_start_month' => 4,
]);
echo "  ✅ Business: Car Mela Auto\n\n";

// ---- STEP 2: Create Admin User ----
echo "2️⃣  Creating admin user...\n";
$userId = Database::uuid();
$db->insert('users', [
    'id' => $userId,
    'business_id' => $businessId,
    'username' => 'admin',
    'password_hash' => password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]),
    'full_name' => 'Harshil Vekariya',
    'email' => 'harshil@carmela.com',
    'role' => 'ADMIN',
]);
echo "  ✅ Admin: harshil / admin123\n\n";

// ---- STEP 3: Setup Default Accounts ----
echo "3️⃣  Setting up chart of accounts...\n";
$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $businessId;
$_SESSION['role'] = 'ADMIN';
$_SESSION['full_name'] = 'Harshil Vekariya';

$engine = new AccountingEngine($businessId, $userId);
$engine->setupDefaultAccounts();
echo "  ✅ Default accounts created (Cash, Bank, GST, Income, Expense)\n\n";

// ---- STEP 4: Create Financial Year ----
echo "4️⃣  Creating financial year...\n";
$fy = getCurrentFY();
$db->insert('financial_years', [
    'id' => Database::uuid(),
    'business_id' => $businessId,
    'year_label' => getFYLabel($fy),
    'start_date' => $fy . '-04-01',
    'end_date' => ($fy + 1) . '-03-31',
    'is_active' => 1,
]);
echo "  ✅ FY " . getFYLabel($fy) . "\n\n";

echo "═══════════════════════════════════════\n";
echo "✅ SETUP COMPLETE — Now seeding data...\n";
echo "═══════════════════════════════════════\n\n";

// ---- Now run seeder ----
include __DIR__ . '/seed.php';

<?php
/**
 * AutoBooks Pro — Realistic Dummy Data Seeder
 * Run: php database/seed.php
 * 
 * Creates realistic car trading business data:
 * - 2 Partners (Rajesh & Vikram)
 * - 3 Employees (Driver, Mechanic, Salesman)
 * - 6 Cars (mix of sold, in-stock, pending payment)
 * - 30+ journal entries covering all major transaction types
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$db = Database::getInstance();

// Get business & user IDs
$business = $db->fetch("SELECT id FROM businesses LIMIT 1");
$user = $db->fetch("SELECT id FROM users LIMIT 1");
if (!$business || !$user) { die("Run setup first!\n"); }

$businessId = $business['id'];
$userId = $user['id'];

// Start session for Auth functions
$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $businessId;
$_SESSION['role'] = 'ADMIN';
$_SESSION['full_name'] = 'Admin';

$engine = new AccountingEngine($businessId, $userId);

echo "🚗 AutoBooks Pro — Seeding realistic data...\n\n";

// ============================================================
// 1. SEED INITIAL CASH & BANK BALANCE (Opening Balance)
// ============================================================
echo "💰 Setting up opening balances...\n";

$cashAccount = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND entity_id IS NULL", [$businessId]);
$bankAccount = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'BANK' AND entity_id IS NULL", [$businessId]);
$gstAccount  = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'GST' AND entity_id IS NULL", [$businessId]);

// Give business ₹50,00,000 cash and ₹2,00,00,000 in bank
$db->query("UPDATE accounts SET current_balance = 5000000, current_balance_type = 'DR' WHERE id = ?", [$cashAccount['id']]);
$db->query("UPDATE accounts SET current_balance = 20000000, current_balance_type = 'DR' WHERE id = ?", [$bankAccount['id']]);
$db->query("UPDATE accounts SET current_balance = 150000, current_balance_type = 'DR' WHERE id = ?", [$gstAccount['id']]);

echo "  ✅ Cash: ₹50,00,000 | Bank: ₹2,00,00,000 | GST: ₹1,50,000\n\n";

// ============================================================
// 2. CREATE PARTNERS
// ============================================================
echo "👥 Creating partners...\n";

$partnerId1 = Database::uuid();
$partnerCapital1 = $engine->createAccount('PTR-RAJESH-CAP', 'Rajesh Patel - Capital A/c', 'EQUITY', 'Partner Capital', 'PARTNER', $partnerId1);
$partnerCurrent1 = $engine->createAccount('PTR-RAJESH-CUR', 'Rajesh Patel - Current A/c', 'EQUITY', 'Partner Current', 'PARTNER', $partnerId1);
$db->insert('partners', [
    'id' => $partnerId1, 'business_id' => $businessId,
    'name' => 'Rajesh Patel', 'phone' => '9876543210', 'email' => 'rajesh@carmela.com',
    'pan' => 'ABCPD1234E', 'profit_share_pct' => 60.00,
    'capital_account_id' => $partnerCapital1, 'current_account_id' => $partnerCurrent1,
    'joined_date' => '2024-04-01',
]);

$partnerId2 = Database::uuid();
$partnerCapital2 = $engine->createAccount('PTR-VIKRAM-CAP', 'Vikram Shah - Capital A/c', 'EQUITY', 'Partner Capital', 'PARTNER', $partnerId2);
$partnerCurrent2 = $engine->createAccount('PTR-VIKRAM-CUR', 'Vikram Shah - Current A/c', 'EQUITY', 'Partner Current', 'PARTNER', $partnerId2);
$db->insert('partners', [
    'id' => $partnerId2, 'business_id' => $businessId,
    'name' => 'Vikram Shah', 'phone' => '9876501234', 'email' => 'vikram@carmela.com',
    'pan' => 'XYZPS5678F', 'profit_share_pct' => 40.00,
    'capital_account_id' => $partnerCapital2, 'current_account_id' => $partnerCurrent2,
    'joined_date' => '2024-04-01',
]);

echo "  ✅ Rajesh Patel (60%) & Vikram Shah (40%)\n\n";

// ============================================================
// 3. CREATE EMPLOYEES
// ============================================================
echo "👷 Creating employees...\n";

$employees = [
    ['name' => 'Suresh Kumar', 'role' => 'Driver', 'salary' => 18000, 'phone' => '9988776655'],
    ['name' => 'Manoj Yadav', 'role' => 'Mechanic', 'salary' => 22000, 'phone' => '9977665544'],
    ['name' => 'Anil Sharma', 'role' => 'Showroom Salesman', 'salary' => 15000, 'phone' => '9966554433'],
];

$empIds = [];
foreach ($employees as $emp) {
    $empId = Database::uuid();
    $advCode = 'ADV-' . strtoupper(substr($emp['name'], 0, 6));
    $advAccountId = $engine->createAccount($advCode, $emp['name'] . ' - Advance A/c', 'ASSET', 'Employee Advances', 'EMPLOYEE', $empId);
    $db->insert('employees', [
        'id' => $empId, 'business_id' => $businessId,
        'name' => $emp['name'], 'phone' => $emp['phone'],
        'role' => $emp['role'], 'monthly_salary' => $emp['salary'],
        'advance_account_id' => $advAccountId, 'join_date' => '2024-06-01',
    ]);
    $empIds[$emp['name']] = $empId;
    echo "  ✅ {$emp['name']} ({$emp['role']}) — ₹" . number_format($emp['salary']) . "/month\n";
}
echo "\n";

// ============================================================
// 4. PARTNER INVESTMENTS
// ============================================================
echo "💼 Recording partner investments...\n";

$engine->partnerInvest($partnerId1, 800000, '2025-10-01', $bankAccount['id'], 'Rajesh Patel - Initial capital investment');
echo "  ✅ Rajesh Patel invested ₹8,00,000 (Bank)\n";

$engine->partnerInvest($partnerId2, 500000, '2025-10-01', $bankAccount['id'], 'Vikram Shah - Initial capital investment');
echo "  ✅ Vikram Shah invested ₹5,00,000 (Bank)\n";

$engine->partnerInvest($partnerId1, 200000, '2026-01-15', $cashAccount['id'], 'Rajesh Patel - Additional capital for Fortuner deal');
echo "  ✅ Rajesh Patel invested ₹2,00,000 more (Cash)\n\n";

// ============================================================
// 5. CREATE CARS & PURCHASE ENTRIES
// ============================================================
echo "🚗 Adding cars & purchase entries...\n";

$cars = [
    ['reg' => 'GJ05MX1840', 'make' => 'Maruti', 'model' => 'Swift VXI', 'year' => 2021, 'color' => 'White', 'price' => 420000, 'date' => '2025-11-05', 'status' => 'SOLD'],
    ['reg' => 'GJ01AB7890', 'make' => 'Hyundai', 'model' => 'Creta SX', 'year' => 2022, 'color' => 'Red', 'price' => 1050000, 'date' => '2025-12-10', 'status' => 'IN_STOCK'],
    ['reg' => 'GJ03CD4567', 'make' => 'Honda', 'model' => 'City ZX', 'year' => 2020, 'color' => 'Silver', 'price' => 680000, 'date' => '2026-01-08', 'status' => 'SOLD'],
    ['reg' => 'GJ05EF2345', 'make' => 'Tata', 'model' => 'Nexon XZ+', 'year' => 2023, 'color' => 'Blue', 'price' => 890000, 'date' => '2026-01-22', 'status' => 'IN_STOCK'],
    ['reg' => 'GJ07GH6789', 'make' => 'Toyota', 'model' => 'Fortuner 4x4', 'year' => 2021, 'color' => 'Black', 'price' => 2800000, 'date' => '2026-02-15', 'status' => 'PENDING_PAYMENT'],
    ['reg' => 'GJ09JK1234', 'make' => 'Maruti', 'model' => 'Baleno Alpha', 'year' => 2022, 'color' => 'Grey', 'price' => 550000, 'date' => '2026-03-01', 'status' => 'IN_STOCK'],
];

$carIds = [];
foreach ($cars as $car) {
    $carId = Database::uuid();
    $carCode = 'CAR-' . str_replace(' ', '', $car['reg']);
    $carAccountId = $engine->createAccount($carCode, "Car A/c - {$car['reg']}", 'ASSET', 'Inventory', 'CAR', $carId);

    $db->insert('cars', [
        'id' => $carId, 'business_id' => $businessId,
        'registration_no' => $car['reg'], 'make' => $car['make'],
        'model' => $car['model'], 'year' => $car['year'], 'color' => $car['color'],
        'purchase_date' => $car['date'], 'purchase_price' => $car['price'],
        'account_id' => $carAccountId, 'status' => 'IN_STOCK',
    ]);

    // Alternate payment between cash and bank
    $payFrom = ($car['price'] > 800000) ? $bankAccount['id'] : $cashAccount['id'];
    $narration = "Purchased {$car['make']} {$car['model']} ({$car['reg']}) - {$car['color']}";
    $engine->carPurchase($carId, $car['price'], $car['date'], $payFrom, $narration);

    $carIds[$car['reg']] = $carId;
    $payLabel = ($car['price'] > 800000) ? 'Bank' : 'Cash';
    echo "  ✅ {$car['reg']} — {$car['make']} {$car['model']} — ₹" . number_format($car['price']) . " (via $payLabel)\n";
}
echo "\n";

// ============================================================
// 6. CAR EXPENSES (Repairs, RTO, Insurance)
// ============================================================
echo "🔧 Adding car expenses...\n";

// Swift VXI expenses
$engine->carExpense($carIds['GJ05MX1840'], 12000, '2025-11-08', $cashAccount['id'], 'Denting & Painting', 'Full body denting and fresh paint - Swift VXI');
$engine->carExpense($carIds['GJ05MX1840'], 8500, '2025-11-10', $cashAccount['id'], 'Service & Oil Change', 'Engine oil change, filter replacement, AC service');
echo "  ✅ GJ05MX1840 (Swift): Denting ₹12,000 + Service ₹8,500\n";

// Creta SX expenses
$engine->carExpense($carIds['GJ01AB7890'], 25000, '2025-12-15', $cashAccount['id'], 'Insurance Renewal', 'Comprehensive insurance renewal for 1 year');
$engine->carExpense($carIds['GJ01AB7890'], 15000, '2025-12-18', $cashAccount['id'], 'Alloy Wheels', 'New set of 4 alloy wheels installed');
echo "  ✅ GJ01AB7890 (Creta): Insurance ₹25,000 + Alloy Wheels ₹15,000\n";

// Honda City expenses
$engine->carExpense($carIds['GJ03CD4567'], 18000, '2026-01-12', $cashAccount['id'], 'Clutch Replacement', 'Full clutch plate and bearing replacement');
$engine->carExpense($carIds['GJ03CD4567'], 6500, '2026-01-14', $cashAccount['id'], 'Interior Cleaning', 'Deep interior cleaning and seat cover replacement');
echo "  ✅ GJ03CD4567 (City): Clutch ₹18,000 + Interior ₹6,500\n";

// Fortuner expenses
$engine->carExpense($carIds['GJ07GH6789'], 35000, '2026-02-18', $bankAccount['id'], 'RTO Transfer', 'RTO ownership transfer and road tax');
$engine->carExpense($carIds['GJ07GH6789'], 45000, '2026-02-20', $bankAccount['id'], 'Full Detailing', 'Premium ceramic coating and full car detailing');
echo "  ✅ GJ07GH6789 (Fortuner): RTO ₹35,000 + Detailing ₹45,000\n\n";

// ============================================================
// 7. CAR SALES
// ============================================================
echo "💰 Selling cars...\n";

// Sell Swift VXI — full payment, nice profit
$engine->carSale($carIds['GJ05MX1840'], 510000, '2025-12-01', $cashAccount['id'], 'Sold Swift VXI to Pankaj Mehta', 'Pankaj Mehta', 510000);
echo "  ✅ GJ05MX1840 (Swift) SOLD to Pankaj Mehta — ₹5,10,000 (Full Cash)\n";

// Sell Honda City — partial payment, buyer owes balance
$engine->carSale($carIds['GJ03CD4567'], 780000, '2026-02-05', $bankAccount['id'], 'Sold Honda City ZX to Deepak Verma - partial payment', 'Deepak Verma', 600000);
echo "  ✅ GJ03CD4567 (City) SOLD to Deepak Verma — ₹7,80,000 (₹6,00,000 received, ₹1,80,000 due)\n";

// Sell Fortuner — partial payment, big buyer
$engine->carSale($carIds['GJ07GH6789'], 3250000, '2026-03-10', $bankAccount['id'], 'Sold Toyota Fortuner to Karan Industries Pvt Ltd', 'Karan Industries', 2500000);
echo "  ✅ GJ07GH6789 (Fortuner) SOLD to Karan Industries — ₹32,50,000 (₹25,00,000 received, ₹7,50,000 due)\n\n";

// ============================================================
// 8. GENERAL EXPENSES
// ============================================================
echo "📝 Recording general expenses...\n";

$engine->generalExpense(15000, '2025-11-01', $cashAccount['id'], 'Office Rent', 'Showroom rent for November 2025');
$engine->generalExpense(15000, '2025-12-01', $cashAccount['id'], 'Office Rent', 'Showroom rent for December 2025');
$engine->generalExpense(15000, '2026-01-01', $cashAccount['id'], 'Office Rent', 'Showroom rent for January 2026');
$engine->generalExpense(15000, '2026-02-01', $cashAccount['id'], 'Office Rent', 'Showroom rent for February 2026');
$engine->generalExpense(15000, '2026-03-01', $cashAccount['id'], 'Office Rent', 'Showroom rent for March 2026');
echo "  ✅ Office rent — ₹15,000/month × 5 months\n";

$engine->generalExpense(3500, '2026-01-05', $cashAccount['id'], 'Electricity Bill', 'Showroom electricity bill - January');
$engine->generalExpense(8000, '2026-02-10', $cashAccount['id'], 'Petrol/Diesel', 'Fuel for test drives and deliveries');
$engine->generalExpense(5000, '2026-03-05', $cashAccount['id'], 'Stationery & Printing', 'Invoice books, letterheads, visiting cards');
echo "  ✅ Electricity ₹3,500 + Fuel ₹8,000 + Stationery ₹5,000\n\n";

// ============================================================
// 9. SALARY PAYMENTS
// ============================================================
echo "💵 Processing salaries...\n";

// Feb salaries
$engine->salaryPayment($empIds['Suresh Kumar'], 18000, 0, '2026-02-28', $cashAccount['id'], 2, 2026);
$engine->salaryPayment($empIds['Manoj Yadav'], 22000, 0, '2026-02-28', $cashAccount['id'], 2, 2026);
$engine->salaryPayment($empIds['Anil Sharma'], 15000, 0, '2026-02-28', $cashAccount['id'], 2, 2026);
echo "  ✅ February 2026 salaries paid: Suresh ₹18K + Manoj ₹22K + Anil ₹15K\n";

// ============================================================
// 10. EMPLOYEE ADVANCE
// ============================================================
echo "🏧 Processing employee advances...\n";

$engine->employeeAdvance($empIds['Suresh Kumar'], 10000, '2026-03-05', $cashAccount['id'], 'Advance to Suresh Kumar - family medical emergency');
echo "  ✅ Suresh Kumar — ₹10,000 advance (medical emergency)\n";

$engine->employeeAdvance($empIds['Manoj Yadav'], 5000, '2026-03-08', $cashAccount['id'], 'Advance to Manoj Yadav - house rent');
echo "  ✅ Manoj Yadav — ₹5,000 advance (house rent)\n";

// March salaries with advance recovery
$engine->salaryPayment($empIds['Suresh Kumar'], 18000, 5000, '2026-03-15', $cashAccount['id'], 3, 2026);
$engine->salaryPayment($empIds['Manoj Yadav'], 22000, 5000, '2026-03-15', $cashAccount['id'], 3, 2026);
$engine->salaryPayment($empIds['Anil Sharma'], 15000, 0, '2026-03-15', $cashAccount['id'], 3, 2026);
echo "  ✅ March 2026 salaries with advance recovery: Suresh (₹18K-₹5K) + Manoj (₹22K-₹5K) + Anil ₹15K\n\n";

// ============================================================
// 11. LOAN GIVEN & RECEIVED
// ============================================================
echo "🤝 Processing loans...\n";

$engine->loanGiven('Bhavesh Prajapati', 50000, '2026-01-20', $cashAccount['id'], 'Short-term loan to Bhavesh Prajapati for car repair shop');
echo "  ✅ Loan given to Bhavesh Prajapati — ₹50,000 (Cash)\n";

// Partial repayment
$party = $db->fetch("SELECT id FROM debtors_creditors WHERE business_id = ? AND name = 'Bhavesh Prajapati'", [$businessId]);
if ($party) {
    $engine->loanReceived($party['id'], 20000, '2026-02-25', $cashAccount['id'], 'Partial repayment from Bhavesh Prajapati');
    echo "  ✅ Bhavesh repaid ₹20,000 (₹30,000 still outstanding)\n";
}

$engine->loanTaken('Nilesh Auto Finance', 300000, '2026-02-01', $bankAccount['id'], 'Short-term finance for Fortuner purchase from Nilesh Auto Finance');
echo "  ✅ Loan taken from Nilesh Auto Finance — ₹3,00,000 (Bank)\n";

$partyCreditor = $db->fetch("SELECT id FROM debtors_creditors WHERE business_id = ? AND name = 'Nilesh Auto Finance'", [$businessId]);
if ($partyCreditor) {
    $engine->loanRepaid($partyCreditor['id'], 100000, '2026-03-10', $bankAccount['id'], 'Monthly instalment to Nilesh Auto Finance');
    echo "  ✅ Repaid ₹1,00,000 to Nilesh Auto Finance (₹2,00,000 remaining)\n";
}
echo "\n";

// ============================================================
// 12. CONTRA TRANSFERS
// ============================================================
echo "🔄 Recording contra transfers...\n";

$engine->contraTransfer($bankAccount['id'], $cashAccount['id'], 100000, '2026-01-10', 'Withdrew ₹1,00,000 from bank for showroom cash needs');
echo "  ✅ Bank → Cash: ₹1,00,000 (for daily expenses)\n";

$engine->contraTransfer($cashAccount['id'], $bankAccount['id'], 200000, '2026-03-12', 'Deposited ₹2,00,000 cash from car sales into bank');
echo "  ✅ Cash → Bank: ₹2,00,000 (car sale deposits)\n\n";

// ============================================================
// 13. PARTNER WITHDRAWAL
// ============================================================
echo "💸 Partner withdrawal...\n";

$engine->partnerWithdraw($partnerId2, 50000, '2026-03-01', $cashAccount['id'], 'Vikram Shah - Personal withdrawal');
echo "  ✅ Vikram Shah withdrew ₹50,000 (Cash)\n\n";

// ============================================================
// 14. GST PAYMENT
// ============================================================
echo "🧾 GST payment...\n";

$engine->gstPayment(45000, '2026-03-15', 'GST payment for Q4 FY2025-26 via GST portal');
echo "  ✅ GST paid ₹45,000 from GST Bank A/c\n\n";

// ============================================================
// DONE!
// ============================================================
$totalEntries = $db->fetch("SELECT COUNT(*) as cnt FROM journal_entries WHERE business_id = ?", [$businessId]);
$totalCars = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ?", [$businessId]);
$totalPartners = $db->fetch("SELECT COUNT(*) as cnt FROM partners WHERE business_id = ?", [$businessId]);
$totalEmployees = $db->fetch("SELECT COUNT(*) as cnt FROM employees WHERE business_id = ?", [$businessId]);

echo "═══════════════════════════════════════\n";
echo "✅ SEEDING COMPLETE!\n";
echo "═══════════════════════════════════════\n";
echo "📊 Journal Entries: {$totalEntries['cnt']}\n";
echo "🚗 Cars: {$totalCars['cnt']}\n";
echo "👥 Partners: {$totalPartners['cnt']}\n";
echo "👷 Employees: {$totalEmployees['cnt']}\n";
echo "═══════════════════════════════════════\n";
echo "\n🌐 Open http://localhost:8000 to see your data!\n";

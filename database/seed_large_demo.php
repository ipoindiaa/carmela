<?php
/**
 * AutoBooks Pro - Large realistic demo data seeder.
 *
 * Run from CLI only:
 *   php database/seed_large_demo.php --yes
 *
 * This intentionally posts through AccountingEngine so journal entries,
 * account balances, car status, receivables, partner records, and reports
 * look like real operating data instead of raw table fixtures.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!in_array('--yes', $argv, true)) {
    exit("Refusing to seed without confirmation.\nRun: php database/seed_large_demo.php --yes\n");
}

set_time_limit(0);
date_default_timezone_set('Asia/Kolkata');
mt_srand(31052026);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$marker = '[REAL-DEMO-20260531]';
$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE role = 'ADMIN' ORDER BY created_at LIMIT 1")
    ?: $db->fetch("SELECT * FROM users ORDER BY created_at LIMIT 1");

if (!$business || !$user) {
    exit("Run setup first. No business/admin user found.\n");
}

$businessId = $business['id'];
$userId = $user['id'];

$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $businessId;
$_SESSION['username'] = $user['username'] ?? 'admin';
$_SESSION['full_name'] = $user['full_name'] ?? 'Admin';
$_SESSION['role'] = 'ADMIN';
$_SESSION['business_name'] = $business['name'] ?? 'Demo Business';

$existing = $db->fetch(
    "SELECT COUNT(*) AS cnt FROM journal_entries WHERE business_id = ? AND narration LIKE ?",
    [$businessId, $marker . '%']
);
if (($existing['cnt'] ?? 0) > 0 && !in_array('--allow-repeat', $argv, true)) {
    exit("Large demo data already exists for this business. Use --allow-repeat only if you intentionally want duplicates.\n");
}

$engine = new AccountingEngine($businessId, $userId);
$originalPeriodLock = $business['period_lock_date'] ?? null;
$db->query("UPDATE businesses SET period_lock_date = NULL WHERE id = ?", [$businessId]);

function out($message) {
    echo $message . PHP_EOL;
}

function amountInr($amount) {
    return 'Rs. ' . number_format((float) $amount, 2);
}

function pick(array $values) {
    return $values[array_rand($values)];
}

function getAccountByType(Database $db, $businessId, $type) {
    $account = $db->fetch(
        "SELECT * FROM accounts WHERE business_id = ? AND entity_type = ? AND is_active = 1 ORDER BY created_at LIMIT 1",
        [$businessId, $type]
    );
    if (!$account) {
        throw new Exception("Missing $type account. Run setup/default accounts first.");
    }
    return $account;
}

function getOrCreateAccount(Database $db, AccountingEngine $engine, $businessId, $code, $name, $group, $subGroup, $entityType, $entityId = null) {
    $account = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = ?", [$businessId, $code]);
    if ($account) {
        return $account['id'];
    }
    return $engine->createAccount($code, $name, $group, $subGroup, $entityType, $entityId);
}

function createPartner(Database $db, AccountingEngine $engine, $businessId, $name, $phone, $email, $share, $date) {
    $existing = $db->fetch("SELECT * FROM partners WHERE business_id = ? AND name = ?", [$businessId, $name]);
    if ($existing) {
        return $existing;
    }

    $id = Database::uuid();
    $slug = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $name), 0, 8));
    $capital = getOrCreateAccount($db, $engine, $businessId, "P{$slug}C", "$name - Capital A/c", 'EQUITY', 'Partner Capital', 'PARTNER', $id);
    $current = getOrCreateAccount($db, $engine, $businessId, "P{$slug}R", "$name - Current A/c", 'EQUITY', 'Partner Current', 'PARTNER', $id);
    $db->insert('partners', [
        'id' => $id,
        'business_id' => $businessId,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'pan' => strtoupper(substr(md5($name), 0, 10)),
        'profit_share_pct' => $share,
        'capital_account_id' => $capital,
        'current_account_id' => $current,
        'joined_date' => $date,
    ]);
    return $db->fetch("SELECT * FROM partners WHERE id = ?", [$id]);
}

function createEmployee(Database $db, AccountingEngine $engine, $businessId, $name, $role, $salary, $phone, $date) {
    $existing = $db->fetch("SELECT * FROM employees WHERE business_id = ? AND name = ?", [$businessId, $name]);
    if ($existing) {
        return $existing;
    }

    $id = Database::uuid();
    $slug = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $name), 0, 8));
    $advance = getOrCreateAccount($db, $engine, $businessId, "ADV{$slug}", "$name - Advance A/c", 'ASSET', 'Employee Advances', 'EMPLOYEE', $id);
    $db->insert('employees', [
        'id' => $id,
        'business_id' => $businessId,
        'name' => $name,
        'phone' => $phone,
        'role' => $role,
        'monthly_salary' => $salary,
        'advance_account_id' => $advance,
        'join_date' => $date,
    ]);
    return $db->fetch("SELECT * FROM employees WHERE id = ?", [$id]);
}

function createCar(Database $db, AccountingEngine $engine, $businessId, array $car) {
    $existing = $db->fetch("SELECT * FROM cars WHERE business_id = ? AND registration_no = ?", [$businessId, $car['reg']]);
    if ($existing) {
        return $existing;
    }

    $id = Database::uuid();
    $accountId = getOrCreateAccount(
        $db,
        $engine,
        $businessId,
        'CAR-' . str_replace(' ', '', $car['reg']),
        'Car A/c - ' . $car['reg'],
        'ASSET',
        'Inventory',
        'CAR',
        $id
    );

    $db->insert('cars', [
        'id' => $id,
        'business_id' => $businessId,
        'registration_no' => $car['reg'],
        'make' => $car['make'],
        'model' => $car['model'],
        'year' => $car['year'],
        'color' => $car['color'],
        'purchase_date' => $car['purchase_date'],
        'purchase_price' => $car['price'],
        'status' => 'IN_STOCK',
        'account_id' => $accountId,
    ]);

    return $db->fetch("SELECT * FROM cars WHERE id = ?", [$id]);
}

try {
    out("Seeding large realistic demo data for {$business['name']}...");

    $cash = getAccountByType($db, $businessId, 'CASH');
    $bank = getAccountByType($db, $businessId, 'BANK');

    getOrCreateAccount($db, $engine, $businessId, 'SAL-EXP', 'Salary Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL');
    getOrCreateAccount($db, $engine, $businessId, 'CAR-REV', 'Car Sales Revenue', 'INCOME', 'Direct Income', 'GENERAL');
    getOrCreateAccount($db, $engine, $businessId, 'PNL', 'Profit & Loss Account', 'INCOME', 'P&L', 'GENERAL');
    $openingCapital = getOrCreateAccount($db, $engine, $businessId, 'DEMO-CAP-2026', 'Demo Opening Capital', 'EQUITY', 'Owner Capital', 'GENERAL');

    $engine->postJournalEntry('OPENING_BALANCE', '2026-01-01', "$marker Opening balances for realistic demo operations", [
        ['account_id' => $cash['id'], 'amount' => 12500000, 'type' => 'DR', 'narration' => 'Opening showroom cash'],
        ['account_id' => $bank['id'], 'amount' => 72000000, 'type' => 'DR', 'narration' => 'Opening bank balance'],
        ['account_id' => $openingCapital, 'amount' => 84500000, 'type' => 'CR', 'narration' => 'Demo capital introduced'],
    ]);
    out("  Opening balances posted.");

    $partnerRows = [
        ['Rajesh Patel', '9825011122', 'rajesh.patel@example.com', 25],
        ['Vikram Shah', '9825022233', 'vikram.shah@example.com', 20],
        ['Mehul Desai', '9825033344', 'mehul.desai@example.com', 18],
        ['Jignesh Parmar', '9825044455', 'jignesh.parmar@example.com', 15],
        ['Chirag Mehta', '9825055566', 'chirag.mehta@example.com', 12],
        ['Hiren Trivedi', '9825066677', 'hiren.trivedi@example.com', 10],
    ];
    $partners = [];
    foreach ($partnerRows as $row) {
        $partners[] = createPartner($db, $engine, $businessId, $row[0], $row[1], $row[2], $row[3], '2026-01-01');
    }

    foreach ($partners as $i => $partner) {
        $amount = [2500000, 1800000, 1600000, 1400000, 1100000, 900000][$i];
        $account = $i % 2 === 0 ? $bank['id'] : $cash['id'];
        $engine->partnerInvest($partner['id'], $amount, '2026-01-02', $account, "$marker Partner capital received from {$partner['name']}");
    }
    out("  Partners and capital entries created: " . count($partners));

    $employeeRows = [
        ['Suresh Solanki', 'Driver', 24000],
        ['Manoj Rathod', 'Senior Mechanic', 32000],
        ['Anil Chauhan', 'Sales Executive', 26000],
        ['Pooja Shah', 'Accounts Assistant', 28000],
        ['Rafiq Mansuri', 'Yard Supervisor', 30000],
        ['Ketan Prajapati', 'Valuation Executive', 27000],
        ['Nilesh Parmar', 'Delivery Driver', 22000],
        ['Bhavna Patel', 'Front Desk', 23000],
        ['Imran Shaikh', 'Detailing Staff', 21000],
        ['Harsh Mehta', 'Purchase Coordinator', 29000],
    ];
    $employees = [];
    foreach ($employeeRows as $i => $row) {
        $employees[] = createEmployee($db, $engine, $businessId, $row[0], $row[1], $row[2], '97' . str_pad((string) (50000000 + $i * 112233), 8, '0', STR_PAD_LEFT), '2026-01-01');
    }
    out("  Employees created: " . count($employees));

    $makes = [
        ['Maruti', ['Swift VXI', 'Baleno Alpha', 'Ertiga ZXI', 'Brezza ZXI', 'Dzire VXI']],
        ['Hyundai', ['Creta SX', 'Venue SX', 'i20 Asta', 'Verna SX', 'Alcazar Prestige']],
        ['Honda', ['City ZX', 'Amaze VX', 'WR-V VX']],
        ['Tata', ['Nexon XZ+', 'Punch Accomplished', 'Harrier XZ', 'Tiago XZ']],
        ['Toyota', ['Fortuner 4x2', 'Innova Crysta', 'Glanza V']],
        ['Mahindra', ['XUV700 AX7', 'Scorpio N Z8', 'Thar LX']],
        ['Kia', ['Seltos HTX', 'Sonet GTX', 'Carens Luxury']],
    ];
    $colors = ['White', 'Silver', 'Grey', 'Black', 'Red', 'Blue', 'Brown'];
    $expenseCatalog = [
        ['Denting & Painting', 9000, 38000],
        ['Full Service', 4500, 18000],
        ['Tyre Replacement', 12000, 52000],
        ['Insurance Renewal', 16000, 76000],
        ['RTO Transfer', 7000, 42000],
        ['Interior Cleaning', 2500, 16000],
        ['Battery Replacement', 4500, 11000],
        ['Ceramic Coating', 11000, 65000],
    ];
    $buyers = [
        'Amber Motors', 'Banyan Traders', 'Cedar Logistics', 'Delta Travels', 'Eagle Finance',
        'Falcon Textiles', 'Galaxy Cars', 'Harbor Exports', 'Indigo Agency', 'Jupiter Motors',
        'Krypton Deals', 'Lotus Enterprise', 'Matrix Wheels', 'Nimbus Realty', 'Orchid Group',
        'Phoenix Auto', 'Quartz Traders', 'Ranger Logistics', 'Summit Finance', 'Tiger Travels',
        'Umbrella Mart', 'Vector Industries', 'Willow Motors', 'Xpress Couriers', 'Yamuna Cars',
        'Zenith Auto', 'Apex Wheels', 'Bright Motors', 'Crown Buyers', 'Dream Cars', 'Elite Wheels',
    ];
    $cars = [];

    for ($i = 1; $i <= 42; $i++) {
        $make = $makes[array_rand($makes)];
        $model = pick($make[1]);
        $purchaseDate = date('Y-m-d', strtotime('2026-01-05 +' . (($i - 1) * 3) . ' days'));
        $price = mt_rand(280000, 3400000);
        $price = round($price / 5000) * 5000;
        $car = [
            'reg' => sprintf('GJ%02dDE%04d', mt_rand(1, 12), 1000 + $i),
            'make' => $make[0],
            'model' => $model,
            'year' => mt_rand(2018, 2025),
            'color' => pick($colors),
            'purchase_date' => $purchaseDate,
            'price' => $price,
        ];
        $carRow = createCar($db, $engine, $businessId, $car);

        $partnerFunding = [];
        if ($i % 4 === 0) {
            $partner = $partners[$i % count($partners)];
            $partnerFunding[] = [
                'partner_id' => $partner['id'],
                'amount' => round(($price * mt_rand(20, 35) / 100) / 1000) * 1000,
                'profit_share_pct' => mt_rand(20, 40),
                'notes' => 'Demo car-wise partner funding',
            ];
        } elseif ($i % 9 === 0) {
            $partnerA = $partners[$i % count($partners)];
            $partnerB = $partners[($i + 2) % count($partners)];
            $partnerFunding[] = ['partner_id' => $partnerA['id'], 'amount' => round(($price * 0.20) / 1000) * 1000, 'profit_share_pct' => 25];
            $partnerFunding[] = ['partner_id' => $partnerB['id'], 'amount' => round(($price * 0.15) / 1000) * 1000, 'profit_share_pct' => 20];
        }

        $payAccount = $price > 850000 ? $bank['id'] : $cash['id'];
        $engine->carPurchase($carRow['id'], $price, $purchaseDate, $payAccount, "$marker Purchased {$car['make']} {$car['model']} ({$car['reg']})", $partnerFunding);

        $expenseTotal = 0;
        $expenseCount = mt_rand(1, 4);
        for ($e = 0; $e < $expenseCount; $e++) {
            $expense = pick($expenseCatalog);
            $expenseDate = date('Y-m-d', strtotime($purchaseDate . ' +' . mt_rand(2, 18) . ' days'));
            $expenseAmount = round(mt_rand($expense[1], $expense[2]) / 500) * 500;
            $engine->carExpense($carRow['id'], $expenseAmount, $expenseDate, $expenseAmount > 25000 ? $bank['id'] : $cash['id'], "$marker {$expense[0]} for {$car['reg']}");
            $expenseTotal += $expenseAmount;
        }

        $target = $i <= 24 ? 'SOLD' : ($i <= 31 ? 'PENDING_PAYMENT' : 'IN_STOCK');
        if ($target !== 'IN_STOCK') {
            $margin = $i % 11 === 0 ? -mt_rand(10000, 85000) : mt_rand(25000, 260000);
            $salePrice = max(150000, round(($price + $expenseTotal + $margin) / 1000) * 1000);
            $saleDate = date('Y-m-d', strtotime($purchaseDate . ' +' . mt_rand(22, 74) . ' days'));
            $buyer = $buyers[$i % count($buyers)] . ' ' . $i;
            $received = $target === 'SOLD' ? $salePrice : round(($salePrice * mt_rand(55, 82) / 100) / 1000) * 1000;
            $engine->carSale($carRow['id'], $salePrice, $saleDate, $salePrice > 900000 ? $bank['id'] : $cash['id'], "$marker Sold {$car['reg']} to $buyer", $buyer, $received);

            if ($target === 'PENDING_PAYMENT' && $received < $salePrice && $i % 2 === 0) {
                $party = $db->fetch("SELECT id FROM debtors_creditors WHERE business_id = ? AND name = ? AND type = 'BUYER'", [$businessId, $buyer]);
                if ($party) {
                    $installment = min($salePrice - $received, round(($salePrice - $received) * 0.35 / 1000) * 1000);
                    $engine->loanReceived($party['id'], $installment, date('Y-m-d', strtotime($saleDate . ' +12 days')), $bank['id'], "$marker Buyer installment received from $buyer");
                }
            }
        }

        $cars[] = $db->fetch("SELECT * FROM cars WHERE id = ?", [$carRow['id']]);
    }
    out("  Cars created with purchases, expenses, sales, pending balances: " . count($cars));

    $generalExpenses = [
        ['Showroom Rent', 85000], ['Electricity Bill', 18500], ['Digital Marketing', 42000],
        ['Tea & Refreshments', 7800], ['Office Supplies', 12600], ['Broker Commission', 55000],
        ['Transport Charges', 36000], ['Yard Cleaning', 9200], ['Security Charges', 28000],
        ['Software Subscription', 6500], ['Inspection Charges', 15500], ['Roadside Assistance', 11000],
    ];
    for ($month = 1; $month <= 5; $month++) {
        foreach ($generalExpenses as $idx => $expense) {
            if (($idx + $month) % 3 === 0) {
                continue;
            }
            $date = sprintf('2026-%02d-%02d', $month, min(26, 3 + $idx * 2));
            $amount = round(($expense[1] + mt_rand(-2500, 3500)) / 100) * 100;
            $engine->generalExpense($amount, $date, $amount > 30000 ? $bank['id'] : $cash['id'], "$marker {$expense[0]} for " . date('M Y', strtotime($date)));
        }
    }
    out("  General business expenses posted.");

    foreach ([1, 2, 3, 4, 5] as $month) {
        foreach ($employees as $idx => $employee) {
            if ($month === 2 && in_array($idx, [1, 4, 7], true)) {
                $engine->employeeAdvance($employee['id'], mt_rand(5000, 18000), sprintf('2026-%02d-08', $month), $cash['id'], "$marker Employee advance to {$employee['name']}");
            }
            $deduction = ($month >= 3 && in_array($idx, [1, 4, 7], true)) ? 3000 : 0;
            $engine->salaryPayment($employee['id'], (float) $employee['monthly_salary'], $deduction, sprintf('2026-%02d-28', $month), $idx % 3 === 0 ? $bank['id'] : $cash['id'], $month, 2026);
        }
    }
    out("  Salary and employee advance entries posted.");

    $loanDebtors = ['Bhavesh Prajapati', 'Jayesh Auto Care', 'Sagar Finance Friend', 'Om Motors Helper', 'Kunal Broker'];
    foreach ($loanDebtors as $i => $name) {
        $amount = 40000 + ($i * 25000);
        $engine->loanGiven($name, $amount, date('Y-m-d', strtotime('2026-02-03 +' . ($i * 9) . ' days')), $cash['id'], "$marker Short-term loan given to $name");
        $party = $db->fetch("SELECT id FROM debtors_creditors WHERE business_id = ? AND name = ? AND type = 'DEBTOR'", [$businessId, $name]);
        if ($party && $i % 2 === 0) {
            $engine->loanReceived($party['id'], round($amount * 0.45 / 1000) * 1000, date('Y-m-d', strtotime('2026-03-05 +' . ($i * 7) . ' days')), $bank['id'], "$marker Partial loan repayment from $name");
        }
    }

    $loanCreditors = ['Nilesh Auto Finance', 'Surat Vehicle Finance', 'Patel Private Finance', 'Shree Credit Co'];
    foreach ($loanCreditors as $i => $name) {
        $amount = 250000 + ($i * 125000);
        $engine->loanTaken($name, $amount, date('Y-m-d', strtotime('2026-01-18 +' . ($i * 18) . ' days')), $bank['id'], "$marker Short-term finance taken from $name");
        $party = $db->fetch("SELECT id FROM debtors_creditors WHERE business_id = ? AND name = ? AND type = 'CREDITOR'", [$businessId, $name]);
        if ($party) {
            $engine->loanRepaid($party['id'], round($amount * 0.30 / 1000) * 1000, date('Y-m-d', strtotime('2026-03-20 +' . ($i * 11) . ' days')), $bank['id'], "$marker Part repayment to $name");
        }
    }
    out("  Loan given/received/taken/repaid entries posted.");

    for ($i = 0; $i < 14; $i++) {
        $from = $i % 2 === 0 ? $bank['id'] : $cash['id'];
        $to = $i % 2 === 0 ? $cash['id'] : $bank['id'];
        $amount = mt_rand(50000, 450000);
        $engine->contraTransfer($from, $to, round($amount / 1000) * 1000, date('Y-m-d', strtotime('2026-01-12 +' . ($i * 8) . ' days')), "$marker Cash-bank transfer for showroom operations");
    }
    out("  Contra transfers posted.");

    $activeCarAccounts = $db->fetchAll(
        "SELECT a.id, c.registration_no
         FROM cars c
         JOIN accounts a ON a.id = c.account_id
         WHERE c.business_id = ? AND c.status IN ('IN_STOCK','PENDING_PAYMENT')
         ORDER BY c.registration_no
         LIMIT 18",
        [$businessId]
    );
    $expenseAccounts = [
        getOrCreateAccount($db, $engine, $businessId, 'DEMO-AUCT', 'Auction Yard Charges', 'EXPENSE', 'Direct Expenses', 'GENERAL'),
        getOrCreateAccount($db, $engine, $businessId, 'DEMO-MELA', 'Mela Purchase Expense', 'EXPENSE', 'Direct Expenses', 'GENERAL'),
        getOrCreateAccount($db, $engine, $businessId, 'DEMO-COMN', 'Common Allocation Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL'),
    ];
    for ($j = 0; $j < 12; $j++) {
        $lineCount = mt_rand(2, 5);
        $allocations = [];
        $total = 0;
        for ($l = 0; $l < $lineCount; $l++) {
            $useCar = !empty($activeCarAccounts) && ($l % 2 === 0);
            $accountId = $useCar
                ? $activeCarAccounts[($j + $l) % count($activeCarAccounts)]['id']
                : $expenseAccounts[($j + $l) % count($expenseAccounts)];
            $amount = round(mt_rand(12000, 98000) / 500) * 500;
            $allocations[] = ['account_id' => $accountId, 'amount' => $amount, 'narration' => 'Allocated split bill line'];
            $total += $amount;
        }
        $voucherTypes = ['GARAGE_BILL_SPLIT', 'AUCTION_PURCHASE_SPLIT', 'COMMON_EXPENSE_ALLOCATION', 'MIXED_FUNDING'];
        $engine->saveJournalVoucher(
            date('Y-m-d', strtotime('2026-02-06 +' . ($j * 9) . ' days')),
            "$marker Split bill JV #" . ($j + 1),
            $j % 3 === 0 ? $bank['id'] : $cash['id'],
            'CR',
            $total,
            $allocations,
            $voucherTypes[$j % count($voucherTypes)],
            'POSTED'
        );
    }
    out("  Journal voucher split entries posted.");

    foreach ($partners as $partner) {
        $payable = $db->fetch(
            "SELECT COALESCE(SUM(outstanding_amount), 0) AS total
             FROM partner_profit_settlements
             WHERE business_id = ? AND partner_id = ? AND direction = 'PAYABLE' AND status IN ('PENDING','PARTIAL')",
            [$businessId, $partner['id']]
        );
        $settleAmount = min(75000, (float) ($payable['total'] ?? 0));
        if ($settleAmount > 1000) {
            $engine->partnerSettlement($partner['id'], $settleAmount, '2026-05-20', $bank['id'], 'PAY', "$marker Partner profit settlement paid to {$partner['name']}");
        }
    }
    $engine->partnerWithdraw($partners[1]['id'], 125000, '2026-05-22', $cash['id'], "$marker Partner personal withdrawal - {$partners[1]['name']}");
    $engine->partnerWithdraw($partners[3]['id'], 85000, '2026-05-24', $bank['id'], "$marker Partner personal withdrawal - {$partners[3]['name']}");
    out("  Partner settlements and withdrawals posted.");

    $typeRows = $db->fetchAll(
        "SELECT transaction_type, COUNT(*) AS cnt
         FROM journal_entries
         WHERE business_id = ? AND status = 'POSTED'
         GROUP BY transaction_type
         ORDER BY transaction_type",
        [$businessId]
    );
    $imbalanced = $db->fetch(
        "SELECT COUNT(*) AS cnt
         FROM (
             SELECT je.id
             FROM journal_entries je
             JOIN journal_lines jl ON jl.journal_entry_id = je.id
             WHERE je.business_id = ? AND je.status = 'POSTED'
             GROUP BY je.id
             HAVING ABS(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE -jl.amount END)) > 0.01
         ) x",
        [$businessId]
    );

    $totals = [
        'journal_entries' => $db->fetch("SELECT COUNT(*) AS cnt FROM journal_entries WHERE business_id = ?", [$businessId])['cnt'] ?? 0,
        'cars' => $db->fetch("SELECT COUNT(*) AS cnt FROM cars WHERE business_id = ?", [$businessId])['cnt'] ?? 0,
        'partners' => $db->fetch("SELECT COUNT(*) AS cnt FROM partners WHERE business_id = ?", [$businessId])['cnt'] ?? 0,
        'employees' => $db->fetch("SELECT COUNT(*) AS cnt FROM employees WHERE business_id = ?", [$businessId])['cnt'] ?? 0,
        'parties' => $db->fetch("SELECT COUNT(*) AS cnt FROM debtors_creditors WHERE business_id = ?", [$businessId])['cnt'] ?? 0,
        'jv' => $db->fetch("SELECT COUNT(*) AS cnt FROM journal_vouchers WHERE business_id = ?", [$businessId])['cnt'] ?? 0,
    ];

    out("");
    out("DONE. Demo totals:");
    foreach ($totals as $label => $count) {
        out("  " . str_replace('_', ' ', ucfirst($label)) . ": $count");
    }
    out("  Imbalanced posted entries: " . ($imbalanced['cnt'] ?? 0));
    out("");
    out("Posted transaction types:");
    foreach ($typeRows as $row) {
        out("  {$row['transaction_type']}: {$row['cnt']}");
    }
} finally {
    $db->query("UPDATE businesses SET period_lock_date = ? WHERE id = ?", [$originalPeriodLock, $businessId]);
}

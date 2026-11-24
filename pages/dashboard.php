<?php

require_once __DIR__ . "/../includes/auth_check.php";
require_once __DIR__ . "/../config/database.php";

// Convert DECIMAL amounts into integer cents.
function toCents($amount) {
    $amount = (string)$amount;
    $negative = str_starts_with($amount, "-");
    $amount = ltrim($amount, "-");

    $parts = explode(".", $amount, 2);
    $whole = (int)$parts[0];
    $fraction = str_pad($parts[1] ?? "0", 2, "0");
    $cents = ($whole * 100) + (int)substr($fraction, 0, 2);

    return $negative ? -$cents : $cents;
}

function money($cents) {
    $sign = $cents < 0 ? "-" : "";
    return $sign . "₱" . number_format(abs($cents) / 100, 2);
}

// ---------- Fiscal year (calendar year: Jan 1 - Dec 31) ----------
$currentYear = (int)date("Y");

$yearsWithData = $pdo->query(
    "SELECT DISTINCT YEAR(transaction_date)
     FROM journal_entries
     WHERE status = 'posted'"
)->fetchAll(PDO::FETCH_COLUMN);

$fyOptions = array_unique(array_merge(
    [$currentYear, $currentYear - 1],
    array_map("intval", $yearsWithData)
));
rsort($fyOptions);

$fy = filter_input(INPUT_GET, "fy", FILTER_VALIDATE_INT);
if (!$fy || !in_array($fy, $fyOptions, true)) {
    $fy = $currentYear;
}

$fyStart = sprintf("%04d-01-01", $fy);
$fyEnd   = sprintf("%04d-12-31", $fy);

// Totals from POSTED journal entries only.
// total_*  = cumulative up to the end of the fiscal year
// fy_*     = activity inside the fiscal year only
$sql = "
    SELECT
        a.account_type,
        COALESCE(SUM(l.debit), 0)  AS total_debit,
        COALESCE(SUM(l.credit), 0) AS total_credit,
        COALESCE(SUM(CASE WHEN l.transaction_date >= ? THEN l.debit END), 0)  AS fy_debit,
        COALESCE(SUM(CASE WHEN l.transaction_date >= ? THEN l.credit END), 0) AS fy_credit
    FROM accounts a
    LEFT JOIN (
        SELECT jl.account_id, jl.debit, jl.credit, je.transaction_date
        FROM journal_entry_lines jl
        INNER JOIN journal_entries je
            ON je.id = jl.journal_entry_id
            AND je.status = 'posted'
            AND je.transaction_date <= ?
    ) l ON l.account_id = a.id
    GROUP BY a.account_type
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$fyStart, $fyStart, $fyEnd]);
$rows = $stmt->fetchAll();

$totals = [
    "asset" => 0,
    "liability" => 0,
    "equity" => 0,
    "income" => 0,   // fiscal-year activity
    "expense" => 0   // fiscal-year activity
];
$cumIncome = 0;
$cumExpense = 0;

foreach ($rows as $row) {
    $debit = toCents($row["total_debit"]);
    $credit = toCents($row["total_credit"]);
    $fyDebit = toCents($row["fy_debit"]);
    $fyCredit = toCents($row["fy_credit"]);

    switch ($row["account_type"]) {
        case "asset":
            $totals["asset"] = $debit - $credit;
            break;

        case "liability":
        case "equity":
            $totals[$row["account_type"]] = $credit - $debit;
            break;

        case "income":
            $cumIncome = $credit - $debit;
            $totals["income"] = $fyCredit - $fyDebit;
            break;

        case "expense":
            $cumExpense = $debit - $credit;
            $totals["expense"] = $fyDebit - $fyCredit;
            break;
    }
}

$netIncome = $totals["income"] - $totals["expense"];   // this fiscal year
$netIncomeToDate = $cumIncome - $cumExpense;           // all periods up to FY end

$assets = $totals["asset"];
$liabilities = $totals["liability"];
$equity = $totals["equity"];

// The equation uses cumulative net income, since income and expenses
// are not closed into equity at year end.
$equityIncludingIncome = $equity + $netIncomeToDate;

$balanceDifference = $assets - $liabilities - $equityIncludingIncome;
$isBalanced = ($balanceDifference === 0);
$balanceStatus = $isBalanced ? "Balanced" : "Not Balanced";

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM journal_entries
     WHERE status = 'posted' AND transaction_date BETWEEN ? AND ?"
);
$countStmt->execute([$fyStart, $fyEnd]);
$postedCount = (int)$countStmt->fetchColumn();

// Latest posted transactions in the fiscal year.
$listStmt = $pdo->prepare(
    "SELECT
        je.id,
        je.transaction_date,
        je.voucher_number,
        je.payee,
        je.description,
        COALESCE(SUM(l.debit), 0) AS amount
     FROM journal_entries je
     LEFT JOIN journal_entry_lines l ON l.journal_entry_id = je.id
     WHERE je.status = 'posted'
       AND je.transaction_date BETWEEN ? AND ?
     GROUP BY je.id
     ORDER BY je.transaction_date DESC, je.id DESC
     LIMIT 10"
);
$listStmt->execute([$fyStart, $fyEnd]);
$transactions = $listStmt->fetchAll();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$fullName = e($_SESSION["full_name"] ?? "User");
$role = e(ucwords(str_replace("_", " ", $_SESSION["role"] ?? "")));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Dashboard | LedgerFlow AIS</title>

        <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<?php $activePage = "dashboard"; require __DIR__ . "/../includes/sidebar.php"; ?>

<main class="main-content">
    <div class="page-inner">

        <div class="page-header">
            <h1>Dashboard</h1>
            <p>Welcome, <?= $fullName ?>. Role: <?= $role ?>.</p>
        </div>

        <form method="GET" class="filter-bar">
            <label for="fy">Fiscal Year</label>
            <select name="fy" id="fy" style="min-width: 140px;"
                    onchange="this.form.submit()">
                <?php foreach ($fyOptions as $year): ?>
                    <option value="<?= (int)$year ?>"
                        <?= $year === $fy ? "selected" : "" ?>>
                        FY <?= (int)$year ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn">Apply</button></noscript>
        </form>

        <div class="cards">
            <div class="card">
                <h3>Total Assets</h3>
                <div class="amount-large"><?= money($assets) ?></div>
            </div>

            <div class="card">
                <h3>Total Liabilities</h3>
                <div class="amount-large"><?= money($liabilities) ?></div>
            </div>

            <div class="card">
                <h3>Total Equity</h3>
                <div class="amount-large"><?= money($equity) ?></div>
            </div>

            <div class="card">
                <h3>Total Income</h3>
                <div class="amount-large"><?= money($totals["income"]) ?></div>
            </div>

            <div class="card">
                <h3>Total Expenses</h3>
                <div class="amount-large"><?= money($totals["expense"]) ?></div>
            </div>

            <div class="card">
                <h3>Net Income</h3>
                <div class="amount-large"><?= money($netIncome) ?></div>
            </div>
        </div>

        <p class="note">
            Assets, liabilities and equity are cumulative through
            Dec 31, <?= (int)$fy ?>. Income, expenses and net income cover
            FY <?= (int)$fy ?> only.
        </p>

        <div class="panel">
            <h3>Accounting Equation</h3>

            <p>Assets: <strong><?= money($assets) ?></strong></p>

            <p>
                Liabilities + Equity + Net Income to Date:
                <strong><?= money($liabilities + $equityIncludingIncome) ?></strong>
            </p>

            <h3 class="<?= $isBalanced ? 'balanced' : 'unbalanced' ?>">
                <?= e($balanceStatus) ?>
            </h3>

            <?php if (!$isBalanced): ?>
                <p>Difference: <?= money(abs($balanceDifference)) ?></p>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>Transaction List Details</h2>
            <p class="note">
                Latest posted entries for FY <?= (int)$fy ?>
                (<?= $postedCount ?> posted this fiscal year).
                Select a series number to open its voucher.
            </p>

            <?php if (empty($transactions)): ?>
                <p class="empty-message">No posted transactions in FY <?= (int)$fy ?>.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date (mo/day/yr)</th>
                            <th>Series No.</th>
                            <th>Payee</th>
                            <th>Particulars / Description</th>
                            <th class="amount">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td class="nowrap">
                                    <?= e(date("m/d/Y", strtotime($tx["transaction_date"]))) ?>
                                </td>
                                <td class="nowrap">
                                    <a href="journal_vouchers.php?id=<?= (int)$tx["id"] ?>">
                                        <?= e($tx["voucher_number"]) ?>
                                    </a>
                                </td>
                                <td><?= e($tx["payee"] ?? "") ?></td>
                                <td><?= e($tx["description"]) ?></td>
                                <td class="amount"><?= money(toCents($tx["amount"])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin: 16px 0 0;">
                <a href="journal_vouchers.php">View all vouchers</a>
            </p>
        </div>

    </div>
</main>

</body>
</html>
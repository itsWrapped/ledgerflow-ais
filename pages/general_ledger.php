<?php

require_once "../includes/auth_check.php";
require_once "../config/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Load active accounts
$stmt = $pdo->query("
    SELECT id, account_code, account_name, account_type, normal_balance
    FROM accounts
    WHERE is_active = 1
    ORDER BY account_code ASC
");

$accounts = $stmt->fetchAll();

// Selected account
$selected_account_id = filter_input(
    INPUT_GET,
    "account_id",
    FILTER_VALIDATE_INT
);

$selected_account = null;
$ledger_entries = [];
$opening_balance = 0;

if ($selected_account_id) {

    // Find selected account
    $stmt = $pdo->prepare("
        SELECT *
        FROM accounts
        WHERE id = ? AND is_active = 1
    ");

    $stmt->execute([$selected_account_id]);
    $selected_account = $stmt->fetch();

    if ($selected_account) {

        // Get all posted transactions for this account
        $stmt = $pdo->prepare("
            SELECT
                je.transaction_date,
                je.voucher_number,
                je.payee,
                je.description,
                jel.line_description,
                jel.debit,
                jel.credit
            FROM journal_entry_lines jel
            INNER JOIN journal_entries je
                ON je.id = jel.journal_entry_id
            WHERE
                jel.account_id = ?
                AND je.status = 'posted'
            ORDER BY
                je.transaction_date ASC,
                je.id ASC,
                jel.id ASC
        ");

        $stmt->execute([$selected_account_id]);
        $ledger_entries = $stmt->fetchAll();
    }
}

// CSV download of the selected account's ledger.
if ($selected_account && ($_GET["export"] ?? "") === "csv") {

    // Stop spreadsheet apps from running text that starts like a formula.
    $csvSafe = function ($value) {
        $value = (string)$value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    };

    $filename = "general_ledger_" .
        preg_replace('/[^A-Za-z0-9_-]/', '_', $selected_account["account_code"]) .
        "_" . date("Ymd") . ".csv";

    // Discard any stray whitespace output so the file starts cleanly.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen("php://output", "w");
    $put = function (array $row) use ($out) {
        fputcsv($out, $row, ",", '"', "");
    };

    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly

    $put(["LedgerFlow AIS - General Ledger"]);
    $put(["Account", $selected_account["account_code"] . " - " . $selected_account["account_name"]]);
    $put(["Account Type", ucfirst($selected_account["account_type"])]);
    $put(["Normal Balance", strtoupper($selected_account["normal_balance"])]);
    $put(["Generated", date("Y-m-d H:i")]);
    $put([]);
    $put(["Date", "Series No.", "Payee", "Description", "Debit", "Credit", "Balance", "Dr/Cr"]);

    $debitNormal = $selected_account["normal_balance"] === "debit";
    $running = 0;
    $sumDebit = 0;
    $sumCredit = 0;

    foreach ($ledger_entries as $row) {
        $d = (int) round((float)$row["debit"] * 100);
        $c = (int) round((float)$row["credit"] * 100);
        $sumDebit += $d;
        $sumCredit += $c;

        $running += $debitNormal ? ($d - $c) : ($c - $d);

        if ($debitNormal) {
            $side = $running < 0 ? "Cr" : "Dr";
        } else {
            $side = $running < 0 ? "Dr" : "Cr";
        }

        $put([
            $row["transaction_date"],
            $row["voucher_number"],
            $csvSafe($row["payee"] ?? ""),
            $csvSafe($row["line_description"] ?: $row["description"]),
            number_format($d / 100, 2, ".", ""),
            number_format($c / 100, 2, ".", ""),
            number_format(abs($running) / 100, 2, ".", ""),
            $side
        ]);
    }

    $put(["", "", "", "TOTAL",
        number_format($sumDebit / 100, 2, ".", ""),
        number_format($sumCredit / 100, 2, ".", ""), "", ""]);

    fclose($out);
    exit;
}

// Helper for safe HTML output
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Ledger | LedgerFlow AIS</title>


        <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<?php $activePage = "general_ledger"; require __DIR__ . "/../includes/sidebar.php"; ?>

<main class="main-content">
<div class="page-inner">

    <div class="page-header">
            <h1>General Ledger</h1>
            <p>View posted transactions and account balances.</p>
        </div>

    <div class="panel">

        <form method="GET" class="filter-bar">

            <label for="account_id">Select Account:</label>

            <select name="account_id" id="account_id" required>
                <option value="">-- Choose an Account --</option>

                <?php foreach ($accounts as $account): ?>
                    <option
                        value="<?= e($account['id']) ?>"
                        <?= ($selected_account_id == $account['id']) ? 'selected' : '' ?>
                    >
                        <?= e($account['account_code']) ?> -
                        <?= e($account['account_name']) ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <button type="submit" class="btn">View Ledger</button>

            <button
                type="button"
                class="btn btn-secondary no-print"
                onclick="window.print()"
            >
                Print / Save as PDF
            </button>

            <?php if ($selected_account): ?>
                <a class="btn btn-secondary no-print"
                   href="?account_id=<?= (int)$selected_account["id"] ?>&amp;export=csv">
                    Download CSV
                </a>
            <?php endif; ?>

        </form>

        <?php if ($selected_account): ?>

            <div class="info-box">
                <h3>
                    <?= e($selected_account['account_code']) ?> -
                    <?= e($selected_account['account_name']) ?>
                </h3>

                <p>
                    Account Type:
                    <?= e(ucfirst($selected_account['account_type'])) ?>
                </p>

                <p>
                    Normal Balance:
                    <?= e(strtoupper($selected_account['normal_balance'])) ?>
                </p>
            </div>

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Series No.</th>
                        <th>Payee</th>
                        <th>Description</th>
                        <th class="amount">Debit (₱)</th>
                        <th class="amount">Credit (₱)</th>
                        <th class="amount">Balance (₱)</th>
                    </tr>
                </thead>

                <tbody>

                    <?php
                    $running_balance_cents = 0;

                    foreach ($ledger_entries as $entry):

                        $debit_cents = (int) round((float)$entry['debit'] * 100);
                        $credit_cents = (int) round((float)$entry['credit'] * 100);

                        if ($selected_account['normal_balance'] === 'debit') {
                            $running_balance_cents += $debit_cents - $credit_cents;
                        } else {
                            $running_balance_cents += $credit_cents - $debit_cents;
                        }

                        $balance_side = $running_balance_cents < 0 ? 'Dr' : 'Cr';

                        if ($selected_account['normal_balance'] === 'debit') {
                            $balance_side = $running_balance_cents < 0 ? 'Cr' : 'Dr';
                        }

                        $display_balance = number_format(
                            abs($running_balance_cents) / 100,
                            2
                        );
                    ?>

                    <tr>
                        <td class="nowrap"><?= e($entry['transaction_date']) ?></td>

                        <td class="nowrap"><?= e($entry['voucher_number']) ?></td>

                        <td><?= e($entry['payee'] ?? '') ?></td>

                        <td>
                            <?= e($entry['line_description'] ?: $entry['description']) ?>
                        </td>

                        <td class="amount">
                            <?= number_format((float)$entry['debit'], 2) ?>
                        </td>

                        <td class="amount">
                            <?= number_format((float)$entry['credit'], 2) ?>
                        </td>

                        <td class="amount">
                            <?= $display_balance ?> <?= $balance_side ?>
                        </td>
                    </tr>

                    <?php endforeach; ?>

                    <?php if (empty($ledger_entries)): ?>
                        <tr>
                            <td colspan="7" class="empty-message">
                                No posted transactions found for this account.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>

            <div class="table-summary">
                Total Transactions: <?= count($ledger_entries) ?>
            </div>

        <?php elseif ($selected_account_id): ?>

            <p class="empty-message">
                Account not found or inactive.
            </p>

        <?php else: ?>

            <p class="empty-message">
                Select an account above to view its General Ledger.
            </p>

        <?php endif; ?>

    </div>

</div>
</main>

</body>
</html>

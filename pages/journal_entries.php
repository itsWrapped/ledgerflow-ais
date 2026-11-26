<?php

require_once __DIR__ . "/../includes/auth_check.php";
require_once __DIR__ . "/../config/database.php";

if (empty($_SESSION["journal_token"])) {
    $_SESSION["journal_token"] = bin2hex(random_bytes(32));
}


$message = "";
$messageType = "error";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Convert a valid monetary input into integer cents.
function amountToCents($value) {
    if (!is_string($value) && !is_numeric($value)) {
        return false;
    }

    $value = trim((string)$value);

    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $value)) {
        return false;
    }

    $parts = explode(".", $value, 2);
    $whole = (int)$parts[0];
    $decimal = str_pad($parts[1] ?? "0", 2, "0");

    return ($whole * 100) + (int)$decimal;
}

function displayMoney($cents) {
    return "₱" . number_format($cents / 100, 2);
}

// Retrieve active accounts.
$accountStmt = $pdo->query(
    "SELECT id, account_code, account_name, account_type
     FROM accounts
     WHERE is_active = 1
     ORDER BY account_code"
);

$accounts = $accountStmt->fetchAll();

// Process journal entry submission.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["journal_token"], $token)
    ) {
        $message = "Invalid request. Please refresh the page.";

    } else {

        $date = $_POST["transaction_date"] ?? "";
        $date = is_string($date) ? $date : "";

        $description = $_POST["description"] ?? "";
        $description = is_string($description) ? trim($description) : "";

        $payee = $_POST["payee"] ?? "";
        $payee = is_string($payee) ? trim($payee) : "";

        $accountIds = $_POST["account_id"] ?? [];
        $debits = $_POST["debit"] ?? [];
        $credits = $_POST["credit"] ?? [];

        // Re-index so $debits[$i] / $credits[$i] always line up.
        if (is_array($accountIds) && is_array($debits) && is_array($credits)) {
            $accountIds = array_values($accountIds);
            $debits = array_values($debits);
            $credits = array_values($credits);
        }

        $validDate = DateTime::createFromFormat("!Y-m-d", $date);
        $dateIsValid = $validDate &&
            $validDate->format("Y-m-d") === $date;

        $errors = [];

        if (!$dateIsValid) {
            $errors[] = "Please enter a valid transaction date.";
        }

        if ($payee === "" || mb_strlen($payee) > 150) {
            $errors[] = "Please enter the payee (up to 150 characters).";
        }

        if ($description === "") {
            $errors[] = "Please enter a transaction description.";
        }

        if (
            !is_array($accountIds) ||
            !is_array($debits) ||
            !is_array($credits) ||
            count($accountIds) < 2 ||
            count($accountIds) > 100 ||
            count($accountIds) !== count($debits) ||
            count($accountIds) !== count($credits)
        ) {
            $errors[] = "Please provide at least two valid journal lines.";
        }

        $lines = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $hasDebit = false;
        $hasCredit = false;

        if (empty($errors)) {

            // Confirm account IDs exist and are active.
            $validAccountIds = array_map(
                "intval",
                array_column($accounts, "id")
            );

            foreach ($accountIds as $i => $accountId) {

                $accountId = filter_var(
                    $accountId,
                    FILTER_VALIDATE_INT
                );

                $debit = amountToCents($debits[$i]);
                $credit = amountToCents($credits[$i]);

                if (
                    !$accountId ||
                    !in_array($accountId, $validAccountIds, true)
                ) {
                    $errors[] = "One or more selected accounts are invalid.";
                    break;
                }

                if ($debit === false || $credit === false) {
                    $errors[] = "Please enter valid non-negative amounts with no more than two decimal places.";
                    break;
                }

                // Each line must have exactly one positive amount.
                if (
                    ($debit > 0 && $credit > 0) ||
                    ($debit === 0 && $credit === 0)
                ) {
                    $errors[] = "Each journal line must contain either a debit or a credit amount.";
                    break;
                }

                $totalDebit += $debit;
                $totalCredit += $credit;

                if ($debit > 0) {
                    $hasDebit = true;
                }

                if ($credit > 0) {
                    $hasCredit = true;
                }

                $lines[] = [
                    "account_id" => $accountId,
                    "debit" => $debit,
                    "credit" => $credit
                ];
            }
        }

        if (empty($errors)) {

            if (!$hasDebit || !$hasCredit) {
                $errors[] = "An entry must contain at least one debit and one credit.";

            } elseif ($totalDebit !== $totalCredit) {
                $errors[] =
                    "Entry not balanced. Total Debits: " .
                    displayMoney($totalDebit) .
                    " | Total Credits: " .
                    displayMoney($totalCredit);
            }
        }

        if (!empty($errors)) {

            $message = implode(" ", $errors);

        } else {

            try {

                $pdo->beginTransaction();

                // Create the journal entry.
                $insertEntry = $pdo->prepare(
                    "INSERT INTO journal_entries
                    (transaction_date, payee, description, status, created_by)
                    VALUES (?, ?, ?, 'draft', ?)"
                );

                $insertEntry->execute([
                    $date,
                    $payee,
                    $description,
                    $_SESSION["user_id"]
                ]);

                $entryId = (int)$pdo->lastInsertId();

                // Insert debit and credit lines.
                $insertLine = $pdo->prepare(
                    "INSERT INTO journal_entry_lines
                    (journal_entry_id, account_id, debit, credit)
                    VALUES (?, ?, ?, ?)"
                );

                foreach ($lines as $line) {

                    $debitAmount = number_format(
                        $line["debit"] / 100,
                        2,
                        ".",
                        ""
                    );

                    $creditAmount = number_format(
                        $line["credit"] / 100,
                        2,
                        ".",
                        ""
                    );

                    $insertLine->execute([
                        $entryId,
                        $line["account_id"],
                        $debitAmount,
                        $creditAmount
                    ]);
                }

                // Series number: Month-Sequence-Year (e.g. 11-001-2026).
                // The sequence restarts each month; FOR UPDATE blocks two
                // simultaneous saves from taking the same number.
                $month = date("m", strtotime($date));
                $year = date("Y", strtotime($date));

                $seqStmt = $pdo->prepare(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(
                        SUBSTRING_INDEX(voucher_number, '-', 2), '-', -1
                     ) AS UNSIGNED)), 0)
                     FROM journal_entries
                     WHERE voucher_number LIKE ?
                     FOR UPDATE"
                );
                $seqStmt->execute([$month . "-%-" . $year]);

                $voucherNumber = sprintf(
                    "%s-%03d-%s",
                    $month,
                    (int)$seqStmt->fetchColumn() + 1,
                    $year
                );

                // Post the balanced entry.
                $postEntry = $pdo->prepare(
                    "UPDATE journal_entries
                     SET voucher_number = ?,
                         status = 'posted',
                         posted_by = ?,
                         posted_at = NOW()
                     WHERE id = ? AND status = 'draft'"
                );

                $postEntry->execute([
                    $voucherNumber,
                    $_SESSION["user_id"],
                    $entryId
                ]);

                if ($postEntry->rowCount() !== 1) {
                    throw new Exception("Unable to post journal entry.");
                }

                $pdo->commit();

                $_SESSION["journal_token"] =
                    bin2hex(random_bytes(32));

                header("Location: journal_entries.php?saved=" . $entryId);
                exit;

            } catch (Throwable $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($ex->getMessage());

                $message =
                    "Unable to save the journal entry. Please check your database and try again.";
            }
        }
    }
}

// After saving, show the new series number and the voucher button.
$savedEntry = null;
$savedId = filter_input(INPUT_GET, "saved", FILTER_VALIDATE_INT);

if ($savedId) {
    $savedStmt = $pdo->prepare(
        "SELECT je.id, je.voucher_number, je.payee,
                COALESCE(SUM(l.debit), 0) AS amount
         FROM journal_entries je
         LEFT JOIN journal_entry_lines l ON l.journal_entry_id = je.id
         WHERE je.id = ? AND je.status = 'posted'
         GROUP BY je.id"
    );
    $savedStmt->execute([$savedId]);
    $savedEntry = $savedStmt->fetch() ?: null;
}

// Retrieve recent posted entries.
$recentStmt = $pdo->query(
    "SELECT
        je.id,
        je.voucher_number,
        je.transaction_date,
        je.payee,
        je.description,
        COALESCE(SUM(l.debit), 0) AS total_debit
     FROM journal_entries je
     LEFT JOIN journal_entry_lines l
        ON l.journal_entry_id = je.id
     WHERE je.status = 'posted'
     GROUP BY je.id
     ORDER BY je.id DESC
     LIMIT 10"
);

$recentEntries = $recentStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Incoming Transaction | LedgerFlow AIS</title>

        <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<?php $activePage = "journal_entries"; require __DIR__ . "/../includes/sidebar.php"; ?>

<main class="main-content">
    <div class="page-inner">

        <div class="page-header">
            <h1>Incoming Transaction</h1>
            <p>Create and post journal entries.</p>
        </div>

        <?php if ($savedEntry): ?>
            <section class="panel">
                <div class="alert success">
                    Entry saved and posted. Series No.
                    <strong><?= e($savedEntry["voucher_number"]) ?></strong>
                </div>

                <p>
                    Payee: <strong><?= e($savedEntry["payee"] ?? "") ?></strong><br>
                    Amount: <strong>&#8369;<?= number_format((float)$savedEntry["amount"], 2) ?></strong>
                </p>

                <a class="btn"
                   href="journal_vouchers.php?id=<?= (int)$savedEntry["id"] ?>">
                    Generate Journal Entry Voucher
                </a>

                <a class="btn btn-secondary" href="journal_entries.php">
                    Enter Another Transaction
                </a>
            </section>
        <?php endif; ?>

        <section class="panel">

            <h2>Create Journal Entry</h2>

            <?php if ($message !== ""): ?>
                <div class="alert <?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="journalForm">

                <input type="hidden" name="csrf_token"
                    value="<?= e($_SESSION["journal_token"]) ?>">

                <div class="field">
                    <label for="processing_time">Date and Time of Processing</label>

                    <input
                        type="text"
                        id="processing_time"
                        value=""
                        readonly
                    >
                </div>

                <div class="field">
                    <label for="transaction_date">Transaction Date</label>

                    <input
                        type="date"
                        id="transaction_date"
                        name="transaction_date"
                        value="<?= date("Y-m-d") ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="payee">Payee</label>

                    <input
                        type="text"
                        id="payee"
                        name="payee"
                        maxlength="150"
                        placeholder="Who was paid or who paid us?"
                        required
                    >
                </div>

                <div class="field">
                    <label for="description">Transaction Description</label>

                    <textarea
                        id="description"
                        name="description"
                        placeholder="Describe the business transaction..."
                        required
                    ></textarea>
                </div>

                <h3>Journal Entry Lines</h3>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Debit (₱)</th>
                                <th>Credit (₱)</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody id="entryLines">
                            <?php for ($i = 0; $i < 2; $i++): ?>
                            <tr>
                                <td>
                                    <select name="account_id[]" required>
                                        <option value="">Select Account</option>

                                        <?php foreach ($accounts as $account): ?>
                                            <option value="<?= (int)$account["id"] ?>">
                                                <?= e(
                                                    $account["account_code"] .
                                                    " - " .
                                                    $account["account_name"]
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <input type="number" name="debit[]"
                                        min="0" step="0.01" value="0.00" required>
                                </td>

                                <td>
                                    <input type="number" name="credit[]"
                                        min="0" step="0.01" value="0.00" required>
                                </td>

                                <td>
                                    <button type="button"
                                        class="btn btn-danger btn-sm"
                                        onclick="removeLine(this)">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <p>
                    <button type="button" class="btn btn-success"
                        onclick="addLine()">
                        Add Account Line
                    </button>
                </p>

                <div class="totals">
                    <div>
                        Total Debit:
                        <strong id="totalDebit">₱0.00</strong>
                    </div>

                    <div>
                        Total Credit:
                        <strong id="totalCredit">₱0.00</strong>
                    </div>

                    <div id="balanceStatus" class="unbalanced">
                        Enter amounts to check balance.
                    </div>
                </div>

                <p>
                    <button type="submit" class="btn" id="postButton" disabled>
                        Save Entry
                    </button>
                </p>

            </form>

        </section>

        <section class="panel">

            <h2>Recent Posted Transactions</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Series No.</th>
                            <th>Date</th>
                            <th>Payee</th>
                            <th>Description</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($recentEntries)): ?>

                        <tr>
                            <td colspan="5">No posted transactions yet.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentEntries as $entry): ?>

                            <tr>
                                <td><?= e($entry["voucher_number"]) ?></td>
                                <td><?= e($entry["transaction_date"]) ?></td>
                                <td><?= e($entry["payee"] ?? "") ?></td>
                                <td><?= e($entry["description"]) ?></td>
                                <td>&#8369;<?= number_format((float)$entry["total_debit"], 2) ?></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </section>

    </div>
</main>

<script>

// Live "date and time of processing" (read-only).
function updateClock() {
    document.getElementById("processing_time").value =
        new Date().toLocaleString("en-US", {
            year: "numeric", month: "short", day: "numeric",
            hour: "numeric", minute: "2-digit", second: "2-digit"
        });
}
updateClock();
setInterval(updateClock, 1000);

function addLine() {

    const tbody = document.getElementById("entryLines");
    const firstRow = tbody.querySelector("tr");
    const newRow = firstRow.cloneNode(true);

    newRow.querySelector("select").selectedIndex = 0;

    newRow.querySelectorAll("input").forEach(input => {
        input.value = "0.00";
    });

    tbody.appendChild(newRow);
    updateTotals();
}

function removeLine(button) {

    const tbody = document.getElementById("entryLines");

    if (tbody.rows.length <= 2) {
        alert("At least two account lines are required.");
        return;
    }

    button.closest("tr").remove();
    updateTotals();
}

function toCents(value) {

    if (!/^\d+(\.\d{1,2})?$/.test(value)) {
        return 0;
    }

    const parts = value.split(".");
    return Number(parts[0]) * 100 +
        Number((parts[1] || "").padEnd(2, "0"));
}

function formatMoney(cents) {

    return "₱" + (cents / 100).toLocaleString(
        "en-PH",
        { minimumFractionDigits: 2, maximumFractionDigits: 2 }
    );
}

function updateTotals() {

    let debit = 0;
    let credit = 0;

    const rows = document.querySelectorAll("#entryLines tr");

    rows.forEach(row => {

        const inputs = row.querySelectorAll("input");

        debit += toCents(inputs[0].value);
        credit += toCents(inputs[1].value);

    });

    document.getElementById("totalDebit").textContent =
        formatMoney(debit);

    document.getElementById("totalCredit").textContent =
        formatMoney(credit);

    const status = document.getElementById("balanceStatus");
    const button = document.getElementById("postButton");

    const validLines = [...rows].every(row => {

        const account = row.querySelector("select").value;
        const inputs = row.querySelectorAll("input");

        const d = toCents(inputs[0].value);
        const c = toCents(inputs[1].value);

        return account !== "" &&
            ((d > 0 && c === 0) || (c > 0 && d === 0));
    });

    if (
        debit > 0 &&
        credit > 0 &&
        debit === credit &&
        validLines
    ) {

        status.textContent = "Debit and credit are balanced";
        status.className = "balanced";
        button.disabled = false;

    } else {

        status.textContent = "Entry is not balanced or has incomplete lines";
        status.className = "unbalanced";
        button.disabled = true;

    }
}

document.getElementById("entryLines").addEventListener(
    "input", updateTotals
);

document.getElementById("entryLines").addEventListener(
    "change", updateTotals
);

updateTotals();

</script>

</body>
</html>
<?php
require_once __DIR__ . "/../includes/auth_check.php";
require_once __DIR__ . "/../config/database.php";


function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Get selected voucher ID
$selectedId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

$selectedEntry = null;
$voucherLines = [];
$voucherError = null;

// Load selected posted journal entry
if ($selectedId !== null && $selectedId !== false) {

    if ($selectedId < 1) {
        $voucherError = "Invalid voucher reference.";
    } else {

        $entryStmt = $pdo->prepare("
            SELECT
                je.*,
                u.full_name AS creator_name
            FROM journal_entries je
            LEFT JOIN users u ON je.created_by = u.id
            WHERE je.id = :id
              AND je.status = 'posted'
            LIMIT 1
        ");

        $entryStmt->execute(["id" => $selectedId]);
        $selectedEntry = $entryStmt->fetch();

        if (!$selectedEntry) {
            $voucherError = "Voucher not found or the journal entry is not posted.";
        } else {

            $lineStmt = $pdo->prepare("
                SELECT
                    jel.*,
                    a.account_code,
                    a.account_name
                FROM journal_entry_lines jel
                INNER JOIN accounts a ON jel.account_id = a.id
                WHERE jel.journal_entry_id = :id
                ORDER BY jel.id ASC
            ");

            $lineStmt->execute(["id" => $selectedId]);
            $voucherLines = $lineStmt->fetchAll();
        }
    }
}

// Load recent posted journal entries
$listStmt = $pdo->query("
    SELECT
        je.id,
        je.voucher_number,
        je.transaction_date,
        je.payee,
        je.description,
        (
            SELECT COALESCE(SUM(l.debit), 0)
            FROM journal_entry_lines l
            WHERE l.journal_entry_id = je.id
        ) AS total_debit,
        (
            SELECT COALESCE(SUM(l.credit), 0)
            FROM journal_entry_lines l
            WHERE l.journal_entry_id = je.id
        ) AS total_credit
    FROM journal_entries je
    WHERE je.status = 'posted'
    ORDER BY je.transaction_date DESC, je.id DESC
");

$postedEntries = $listStmt->fetchAll();

$totalDebit = 0;
$totalCredit = 0;

foreach ($voucherLines as $line) {
    $totalDebit += (int) round((float)$line["debit"] * 100);
    $totalCredit += (int) round((float)$line["credit"] * 100);
}

$isBalanced = ($totalDebit === $totalCredit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Journal Vouchers | LedgerFlow AIS</title>

        <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<!-- SIDEBAR -->

<?php $activePage = "journal_vouchers"; require __DIR__ . "/../includes/sidebar.php"; ?>

<!-- MAIN CONTENT -->

<main class="main-content">
<div class="page-inner">

    <?php if ($selectedEntry): ?>

        <div class="page-header no-print">
            <h1>Journal Voucher</h1>
            <p>In the print window, choose Save as PDF as the destination.</p>
        </div>

        <div class="voucher-actions no-print">
            <a href="journal_vouchers.php" class="btn btn-secondary">Back to Voucher List</a>

            <button
                type="button"
                class="btn"
                onclick="printVoucher()"
            >
                Generate PDF
            </button>
        </div>

        <section class="panel voucher-print-area">

            <div class="voucher-header">
                <h2>LEDGERFLOW AIS</h2>
                <p>ACCOUNTING INFORMATION SYSTEM</p>
                <h3>JOURNAL ENTRY VOUCHER</h3>
            </div>

            <div class="voucher-details">
                <div>
                    <strong>Voucher Number:</strong><br>
                    <?= e($selectedEntry["voucher_number"]) ?>
                </div>

                <div>
                    <strong>Transaction Date:</strong><br>
                    <?= e(date("F d, Y", strtotime($selectedEntry["transaction_date"]))) ?>
                </div>

                <div>
                    <strong>Payee:</strong><br>
                    <?= e($selectedEntry["payee"] ?? "N/A") ?>
                </div>

                <div>
                    <strong>Prepared By:</strong><br>
                    <?= e($selectedEntry["creator_name"] ?? "N/A") ?>
                </div>

                <div>
                    <strong>Status:</strong><br>
                    <span class="balanced">POSTED</span>
                </div>
            </div>

            <div class="voucher-description">
                <strong>Description:</strong><br>
                <?= nl2br(e($selectedEntry["description"])) ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Account Code</th>
                        <th>Account Name</th>
                        <th>Description</th>
                        <th class="amount">Debit (₱)</th>
                        <th class="amount">Credit (₱)</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($voucherLines as $line): ?>

                        <tr>
                            <td><?= e($line["account_code"]) ?></td>

                            <td><?= e($line["account_name"]) ?></td>

                            <td><?= e($line["line_description"] ?? "") ?></td>

                            <td class="amount">
                                <?= number_format((float)$line["debit"], 2) ?>
                            </td>

                            <td class="amount">
                                <?= number_format((float)$line["credit"], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    <tr class="totals-row">
                        <td colspan="3">TOTAL</td>

                        <td class="amount">
                            <?= number_format($totalDebit / 100, 2) ?>
                        </td>

                        <td class="amount">
                            <?= number_format($totalCredit / 100, 2) ?>
                        </td>
                    </tr>

                </tbody>
            </table>

            <p style="margin-top: 18px;">
                <strong>Balance Status:</strong>

                <?php if ($isBalanced): ?>
                    <span class="balanced">BALANCED</span>
                <?php else: ?>
                    <span class="unbalanced">NOT BALANCED</span>
                <?php endif; ?>
            </p>

            <div class="signature-section">
                <div>
                    <div class="signature-line">
                        Prepared By
                    </div>
                </div>

                <div>
                    <div class="signature-line">
                        Approved By
                    </div>
                </div>
            </div>

        </section>

    <?php else: ?>

        <div class="page-header">
            <h1>Journal Vouchers</h1>
            <p>View and print vouchers from posted journal entries.</p>
        </div>

        <?php if ($voucherError): ?>
            <div class="panel">
                <p class="unbalanced"><?= e($voucherError) ?></p>
            </div>
        <?php endif; ?>

        <section class="panel entry-list">

            <h2>Posted Journal Entries</h2>

            <?php if (empty($postedEntries)): ?>

                <p class="empty-message">
                    No posted journal entries are available yet.
                    Create and post a journal entry first.
                </p>

            <?php else: ?>

                <table>
                    <thead>
                        <tr>
                            <th>Voucher Number</th>
                            <th>Date</th>
                            <th>Payee</th>
                            <th>Description</th>
                            <th class="amount">Total Debit</th>
                            <th class="amount">Total Credit</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($postedEntries as $entry): ?>

                            <tr>
                                <td><?= e($entry["voucher_number"]) ?></td>

                                <td>
                                    <?= e(date("M d, Y", strtotime($entry["transaction_date"]))) ?>
                                </td>

                                <td><?= e($entry["payee"] ?? "") ?></td>

                                <td><?= e($entry["description"]) ?></td>

                                <td class="amount">
                                    &#8369;<?= number_format((float)$entry["total_debit"], 2) ?>
                                </td>

                                <td class="amount">
                                    &#8369;<?= number_format((float)$entry["total_credit"], 2) ?>
                                </td>

                                <td>
                                    <a
                                        class="btn btn-sm"
                                        href="journal_vouchers.php?id=<?= (int)$entry["id"] ?>"
                                    >
                                        View Voucher
                                    </a>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>
                </table>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</div>
</main>

<?php if ($selectedEntry): ?>
<script>
// The browser uses the page title as the PDF file name,
// so the saved file is named after the series number.
function printVoucher() {
    const originalTitle = document.title;
    document.title = <?= json_encode($selectedEntry["voucher_number"]) ?>;
    window.print();
    document.title = originalTitle;
}
</script>
<?php endif; ?>

</body>
</html>
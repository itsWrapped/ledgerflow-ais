<?php

require_once __DIR__ . "/../includes/auth_check.php";
require_once __DIR__ . "/../config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function cents($amount) {
    return (int) round((float)$amount * 100);
}

function money($cents) {
    $sign = $cents < 0 ? "-" : "";
    return $sign . "₱" . number_format(abs($cents) / 100, 2);
}

$isAdmin = ($_SESSION["role"] ?? "") === "admin";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$types = ["asset", "liability", "equity", "income", "expense"];
$sides = ["debit", "credit"];

// One-time message shown after a redirect.
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

$errors = [];

$form = [
    "id" => 0,
    "account_code" => "",
    "account_name" => "",
    "account_type" => "asset",
    "normal_balance" => "debit"
];

// How many journal lines use an account, and its posted balance (debit - credit).
function accountUsage(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS line_count,
            COALESCE(SUM(CASE WHEN je.status = 'posted' THEN jl.debit END), 0)  AS debit_total,
            COALESCE(SUM(CASE WHEN je.status = 'posted' THEN jl.credit END), 0) AS credit_total
         FROM journal_entry_lines jl
         INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ?"
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();

    return [
        "lines" => (int)$row["line_count"],
        "net_debit" => cents($row["debit_total"]) - cents($row["credit_total"])
    ];
}

function fetchAccount(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ---------- Handle POST ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!$isAdmin) {
        http_response_code(403);
        die("Only administrators can change the chart of accounts.");
    }

    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $errors[] = "Invalid request. Please refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {

            $id = (int)($_POST["id"] ?? 0);
            $code = trim((string)($_POST["account_code"] ?? ""));
            $name = trim((string)($_POST["account_name"] ?? ""));
            $type = (string)($_POST["account_type"] ?? "");
            $side = (string)($_POST["normal_balance"] ?? "");

            $existing = $id > 0 ? fetchAccount($pdo, $id) : false;

            if ($id > 0 && !$existing) {
                $errors[] = "That account no longer exists.";
            }

            // Type and normal balance are locked once the account has been used.
            if ($existing && accountUsage($pdo, $id)["lines"] > 0) {
                $type = $existing["account_type"];
                $side = $existing["normal_balance"];
            }

            if (!preg_match('/^[A-Za-z0-9.\-]{2,20}$/', $code)) {
                $errors[] = "Account code must be 2-20 letters, numbers, dots or dashes.";
            }
            if ($name === "" || mb_strlen($name) > 150) {
                $errors[] = "Account name is required (up to 150 characters).";
            }
            if (!in_array($type, $types, true)) {
                $errors[] = "Please choose a valid account type.";
            }
            if (!in_array($side, $sides, true)) {
                $errors[] = "Please choose a valid normal balance.";
            }

            if (!$errors) {
                $dup = $pdo->prepare("SELECT id FROM accounts WHERE account_code = ? AND id <> ?");
                $dup->execute([$code, $id]);
                if ($dup->fetch()) {
                    $errors[] = "Account code " . $code . " is already in use.";
                }
            }

            if (!$errors) {
                try {
                    if ($id > 0) {
                        $pdo->prepare(
                            "UPDATE accounts
                             SET account_code = ?, account_name = ?,
                                 account_type = ?, normal_balance = ?
                             WHERE id = ?"
                        )->execute([$code, $name, $type, $side, $id]);
                        $_SESSION["flash"] = ["success", "Account " . $code . " was updated."];
                    } else {
                        $pdo->prepare(
                            "INSERT INTO accounts
                             (account_code, account_name, account_type, normal_balance, is_active)
                             VALUES (?, ?, ?, ?, 1)"
                        )->execute([$code, $name, $type, $side]);
                        $_SESSION["flash"] = ["success", "Account " . $code . " was added."];
                    }

                    header("Location: chart_of_accounts.php");
                    exit;
                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    $errors[] = "The account could not be saved. The code may already exist.";
                }
            }

            // Keep what the person typed so they can correct it.
            $form = [
                "id" => $id,
                "account_code" => $code,
                "account_name" => $name,
                "account_type" => $type,
                "normal_balance" => $side
            ];

        } elseif ($action === "toggle") {

            $id = (int)($_POST["id"] ?? 0);
            $makeActive = ($_POST["make_active"] ?? "") === "1" ? 1 : 0;
            $account = fetchAccount($pdo, $id);

            if (!$account) {
                $errors[] = "That account no longer exists.";
            } elseif ($makeActive === 0 && accountUsage($pdo, $id)["net_debit"] !== 0) {
                $errors[] = "Account " . $account["account_code"] .
                    " still has a balance, so it cannot be deactivated.";
            } else {
                $pdo->prepare("UPDATE accounts SET is_active = ? WHERE id = ?")
                    ->execute([$makeActive, $id]);

                $_SESSION["flash"] = [
                    "success",
                    "Account " . $account["account_code"] .
                    ($makeActive ? " was activated." : " was deactivated.")
                ];
                header("Location: chart_of_accounts.php");
                exit;
            }
        }
    }
}

// ---------- Edit mode (GET) ----------
$editId = filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT);
$editLocked = false;

if ($isAdmin && $editId && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $account = fetchAccount($pdo, $editId);
    if ($account) {
        $form = [
            "id" => (int)$account["id"],
            "account_code" => $account["account_code"],
            "account_name" => $account["account_name"],
            "account_type" => $account["account_type"],
            "normal_balance" => $account["normal_balance"]
        ];
    }
}

if ($form["id"] > 0) {
    $editLocked = accountUsage($pdo, (int)$form["id"])["lines"] > 0;
}

// ---------- Account list ----------
$accounts = $pdo->query(
    "SELECT
        a.*,
        COALESCE(u.line_count, 0)   AS line_count,
        COALESCE(u.debit_total, 0)  AS debit_total,
        COALESCE(u.credit_total, 0) AS credit_total
     FROM accounts a
     LEFT JOIN (
        SELECT
            jl.account_id,
            COUNT(*) AS line_count,
            SUM(CASE WHEN je.status = 'posted' THEN jl.debit END)  AS debit_total,
            SUM(CASE WHEN je.status = 'posted' THEN jl.credit END) AS credit_total
        FROM journal_entry_lines jl
        INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
        GROUP BY jl.account_id
     ) u ON u.account_id = a.id
     ORDER BY a.account_code ASC"
)->fetchAll();

$isEditing = $form["id"] > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Chart of Accounts | LedgerFlow AIS</title>

    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>

<?php $activePage = "chart_of_accounts"; require __DIR__ . "/../includes/sidebar.php"; ?>

<main class="main-content">
    <div class="page-inner">

        <div class="page-header">
            <h1>Chart of Accounts</h1>
            <p>
                The list of accounts used in journal entries.
                <?= $isAdmin ? "" : "You have view-only access." ?>
            </p>
        </div>

        <?php if ($flash): ?>
            <div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if ($isAdmin): ?>
            <div class="panel">
                <h2><?= $isEditing ? "Edit Account" : "Add Account" ?></h2>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)$form["id"] ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="account_code">Account Code</label>
                            <input type="text" id="account_code" name="account_code"
                                   maxlength="20" required
                                   value="<?= e($form["account_code"]) ?>">
                        </div>

                        <div class="field">
                            <label for="account_name">Account Name</label>
                            <input type="text" id="account_name" name="account_name"
                                   maxlength="150" required
                                   value="<?= e($form["account_name"]) ?>">
                        </div>

                        <div class="field">
                            <label for="account_type">Account Type</label>
                            <select id="account_type" name="account_type"
                                    <?= $editLocked ? "disabled" : "" ?>>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= e($type) ?>"
                                        <?= $form["account_type"] === $type ? "selected" : "" ?>>
                                        <?= e(ucfirst($type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="normal_balance">Normal Balance</label>
                            <select id="normal_balance" name="normal_balance"
                                    <?= $editLocked ? "disabled" : "" ?>>
                                <?php foreach ($sides as $side): ?>
                                    <option value="<?= e($side) ?>"
                                        <?= $form["normal_balance"] === $side ? "selected" : "" ?>>
                                        <?= e(ucfirst($side)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($editLocked): ?>
                        <p class="note">
                            Type and normal balance are locked because this account
                            is already used in journal entries.
                        </p>
                    <?php endif; ?>

                    <button type="submit" class="btn">
                        <?= $isEditing ? "Save Changes" : "Add Account" ?>
                    </button>

                    <?php if ($isEditing): ?>
                        <a href="chart_of_accounts.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2>All Accounts</h2>

            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Account Name</th>
                        <th>Type</th>
                        <th>Normal Balance</th>
                        <th class="amount">Posted Balance</th>
                        <th>Status</th>
                        <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account):
                        $net = cents($account["debit_total"]) - cents($account["credit_total"]);
                        $balance = $account["normal_balance"] === "debit" ? $net : -$net;
                        $active = (int)$account["is_active"] === 1;
                    ?>
                        <tr>
                            <td class="nowrap"><?= e($account["account_code"]) ?></td>
                            <td><?= e($account["account_name"]) ?></td>
                            <td><?= e(ucfirst($account["account_type"])) ?></td>
                            <td><?= e(ucfirst($account["normal_balance"])) ?></td>
                            <td class="amount"><?= money($balance) ?></td>
                            <td>
                                <span class="badge <?= $active ? "badge-ok" : "badge-off" ?>">
                                    <?= $active ? "Active" : "Inactive" ?>
                                </span>
                            </td>

                            <?php if ($isAdmin): ?>
                                <td class="nowrap">
                                    <a class="btn btn-secondary btn-sm"
                                       href="chart_of_accounts.php?edit=<?= (int)$account["id"] ?>">Edit</a>

                                    <form method="POST" style="display:inline;"
                                          <?= $active ? 'onsubmit="return confirm(\'Deactivate this account?\');"' : "" ?>>
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION["csrf_token"]) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$account["id"] ?>">
                                        <input type="hidden" name="make_active"
                                               value="<?= $active ? "0" : "1" ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $active ? "btn-danger" : "btn-success" ?>">
                                            <?= $active ? "Deactivate" : "Activate" ?>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="empty-message">
                                No accounts yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <p class="note" style="margin: 14px 0 0;">
                Accounts are never deleted, so past entries always keep their history.
                An account can only be deactivated when its posted balance is zero.
            </p>
        </div>

    </div>
</main>

<?php if ($isAdmin && !$editLocked): ?>
<script>
// Suggest the usual normal balance when the account type changes.
// (Contra accounts such as Accumulated Depreciation can still be changed by hand.)
const defaults = {
    asset: "debit", expense: "debit",
    liability: "credit", equity: "credit", income: "credit"
};
document.getElementById("account_type").addEventListener("change", function () {
    document.getElementById("normal_balance").value = defaults[this.value];
});
</script>
<?php endif; ?>

</body>
</html>

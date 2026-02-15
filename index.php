<?php
/**
 * ✅ FULL SINGLE-FILE index.php (Telegram Referral Bot + Web Verification)
 *
 * ✅ Dynamic Force-Join Manager (Admin can add/remove unlimited channels/groups without editing script)
 * ✅ Menu locked until Web Verification (after verify -> Stats/Withdraw unlock)
 * ✅ Referral: 1 referral = 1 point (credited only once per new user)
 * ✅ Withdraw: 500/1000/2000/4000 (points required from withdraw_points table)
 * ✅ Coupons: add bulk line-by-line per amount, stock, auto-assign unused coupon, mark used
 * ✅ Admin notify on each withdrawal
 * ✅ Redeems log: last 10
 *
 * ---------------- REQUIRED ENV VARS (Render) ----------------
 * BOT_TOKEN=123:ABC
 * DATABASE_URL=postgresql://user:pass@host:5432/dbname
 * ADMIN_IDS=123456789,987654321         (comma separated)
 * BOT_USERNAME=YourBotUsername          (without @)
 * SITE_URL=https://your-service.onrender.com    (no trailing slash)
 * -----------------------------------------------------------
 *
 * ---------------- SUPABASE SQL (run once) ----------------
 * CREATE TABLE IF NOT EXISTS users (
 *   id BIGINT PRIMARY KEY,
 *   username TEXT,
 *   points INT DEFAULT 0 CHECK (points >= 0),
 *   referrals INT DEFAULT 0 CHECK (referrals >= 0),
 *   referred_by BIGINT,
 *   verified BOOLEAN DEFAULT FALSE,
 *   verify_token TEXT,
 *   step TEXT,
 *   step_amount INT,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 *
 * CREATE TABLE IF NOT EXISTS coupons (
 *   id SERIAL PRIMARY KEY,
 *   code TEXT UNIQUE NOT NULL,
 *   amount INT NOT NULL CHECK (amount IN (500,1000,2000,4000)),
 *   is_used BOOLEAN DEFAULT FALSE,
 *   used_by BIGINT,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 * CREATE INDEX IF NOT EXISTS idx_coupons_amount_unused ON coupons (amount) WHERE is_used = FALSE;
 *
 * CREATE TABLE IF NOT EXISTS withdraw_points (
 *   amount INT PRIMARY KEY CHECK (amount IN (500,1000,2000,4000)),
 *   points INT NOT NULL CHECK (points > 0)
 * );
 * INSERT INTO withdraw_points (amount, points) VALUES
 * (500, 3),(1000, 10),(2000, 25),(4000, 40)
 * ON CONFLICT (amount) DO NOTHING;
 *
 * CREATE TABLE IF NOT EXISTS redeems (
 *   id SERIAL PRIMARY KEY,
 *   user_id BIGINT,
 *   coupon_code TEXT,
 *   amount INT,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 * CREATE INDEX IF NOT EXISTS idx_redeems_user ON redeems (user_id);
 *
 * -- ✅ Dynamic force-join list
 * CREATE TABLE IF NOT EXISTS force_join (
 *   id SERIAL PRIMARY KEY,
 *   chat_id TEXT NOT NULL,         -- @publicchannel OR -100xxxxxxxxxx
 *   invite_link TEXT,              -- https://t.me/... (optional but recommended)
 *   is_active BOOLEAN DEFAULT TRUE,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 * CREATE INDEX IF NOT EXISTS idx_force_join_active ON force_join (is_active);
 * -----------------------------------------------------------
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------- CONFIG ----------------
$BOT_TOKEN    = getenv("BOT_TOKEN") ?: "";
$DB_URL       = getenv("DATABASE_URL") ?: "";
$ADMIN_IDS_CSV= getenv("ADMIN_IDS") ?: "";
$BOT_USERNAME = getenv("BOT_USERNAME") ?: ""; // without @
$SITE_URL     = getenv("SITE_URL") ?: "";     // no trailing slash

if (!$BOT_TOKEN) { http_response_code(500); echo "BOT_TOKEN missing"; exit; }
if (!$DB_URL)    { http_response_code(500); echo "DATABASE_URL missing"; exit; }

$ADMIN_IDS = array_values(array_filter(array_map('trim', explode(',', $ADMIN_IDS_CSV))));

// ---------------- DB ----------------
function pdo_from_database_url(string $dbUrl): PDO {
  $parts = parse_url($dbUrl);
  if (!$parts || empty($parts['host']) || empty($parts['path'])) throw new Exception("Invalid DATABASE_URL");

  $user = $parts['user'] ?? '';
  $pass = $parts['pass'] ?? '';
  $host = $parts['host'];
  $port = $parts['port'] ?? 5432;
  $db   = ltrim($parts['path'], '/');

  $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}
$pdo = pdo_from_database_url($DB_URL);

// ---------------- TELEGRAM HELPERS ----------------
function tg_api(string $token, string $method, array $params = []): array {
  $url = "https://api.telegram.org/bot{$token}/{$method}";
  $ch = curl_init($url);
  $body = http_build_query($params);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
  ]);

  $res = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($res === false) return ["ok" => false, "error" => $err ?: "curl_error"];
  $json = json_decode($res, true);
  if (!is_array($json)) return ["ok" => false, "error" => "bad_json", "raw" => $res];
  return $json;
}

function sendMessage(string $token, int|string $chatId, string $text, array $opts = []): void {
  $params = array_merge([
    "chat_id" => $chatId,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true,
  ], $opts);
  tg_api($token, "sendMessage", $params);
}

function editMessageText(string $token, int|string $chatId, int $messageId, string $text, array $opts = []): void {
  $params = array_merge([
    "chat_id" => $chatId,
    "message_id" => $messageId,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true,
  ], $opts);
  tg_api($token, "editMessageText", $params);
}

function answerCallback(string $token, string $callbackId, string $text = "", bool $alert = false): void {
  tg_api($token, "answerCallbackQuery", [
    "callback_query_id" => $callbackId,
    "text" => $text,
    "show_alert" => $alert ? "true" : "false",
  ]);
}

function buildReplyKeyboard(array $rows, bool $resize = true): string {
  return json_encode([
    "keyboard" => $rows,
    "resize_keyboard" => $resize,
    "one_time_keyboard" => false
  ], JSON_UNESCAPED_UNICODE);
}

function buildInlineKeyboard(array $rows): string {
  return json_encode(["inline_keyboard" => $rows], JSON_UNESCAPED_UNICODE);
}

// ---------------- BOT HELPERS ----------------
function isAdmin(int $userId, array $adminIds): bool {
  return in_array((string)$userId, $adminIds, true);
}

function randToken(int $len = 40): string {
  $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $out = '';
  for ($i=0; $i<$len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
  return $out;
}

function getUser(PDO $pdo, int $userId): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE id = :id");
  $st->execute([":id" => $userId]);
  $u = $st->fetch();
  return $u ?: null;
}

function upsertUser(PDO $pdo, int $userId, ?string $username): array {
  $st = $pdo->prepare("
    INSERT INTO users (id, username)
    VALUES (:id, :username)
    ON CONFLICT (id) DO UPDATE SET username = EXCLUDED.username
    RETURNING *
  ");
  $st->execute([":id" => $userId, ":username" => $username]);
  return $st->fetch();
}

function setStep(PDO $pdo, int $userId, ?string $step, ?int $stepAmount = null): void {
  $st = $pdo->prepare("UPDATE users SET step = :step, step_amount = :amt WHERE id = :id");
  $st->execute([":step" => $step, ":amt" => $stepAmount, ":id" => $userId]);
}

function ensureVerifyToken(PDO $pdo, int $userId): string {
  $u = getUser($pdo, $userId);
  if (!$u) return "";
  if (!empty($u["verify_token"])) return (string)$u["verify_token"];
  $t = randToken(40);
  $pdo->prepare("UPDATE users SET verify_token = :t WHERE id = :id")->execute([":t"=>$t,":id"=>$userId]);
  return $t;
}

function webVerifyUrl(string $siteUrl, string $token): string {
  $siteUrl = rtrim($siteUrl, "/");
  return $siteUrl . "/index.php?v=" . urlencode($token);
}

function formatMoneyOption(int $amount): string {
  return "{$amount} OFF ON {$amount}";
}

function userMenu(bool $isAdmin, bool $verified): string {
  if (!$verified) {
    $rows = [
      ["✅ Verify"],
      ["🔗 Referral Link"]
    ];
    if ($isAdmin) $rows[] = ["🛠 Admin Panel"];
    return buildReplyKeyboard($rows);
  }

  $rows = [
    ["📊 Stats", "🔗 Referral Link"],
    ["🎁 Withdraw"]
  ];
  if ($isAdmin) $rows[] = ["🛠 Admin Panel"];
  return buildReplyKeyboard($rows);
}

function adminMenu(): string {
  $rows = [
    ["➕ Add Coupon", "📦 Stock"],
    ["⚙ Change Withdraw Points", "📜 Redeems Log"],
    ["📋 Force Join List", "➕ Add Force Join"],
    ["➖ Remove Force Join", "🔙 Back"],
  ];
  return buildReplyKeyboard($rows);
}

// -------- Dynamic Force Join (DB) --------
function loadForceJoin(PDO $pdo): array {
  $st = $pdo->query("SELECT id, chat_id, invite_link FROM force_join WHERE is_active = TRUE ORDER BY id ASC");
  return $st->fetchAll() ?: [];
}

function normalizeChatId(string $s): string {
  return trim($s);
}

function forceJoinMissing(string $token, int $userId, array $forceJoinRows): array {
  $missing = [];
  foreach ($forceJoinRows as $row) {
    $chat = trim((string)$row["chat_id"]);
    if ($chat === "") continue;

    $res = tg_api($token, "getChatMember", [
      "chat_id" => $chat,
      "user_id" => $userId
    ]);

    if (!($res["ok"] ?? false)) {
      $missing[] = [
        "id" => (int)$row["id"],
        "chat" => $chat,
        "invite_link" => (string)($row["invite_link"] ?? ""),
        "reason" => "unreachable"
      ];
      continue;
    }

    $status = $res["result"]["status"] ?? "";
    if ($status === "left" || $status === "kicked") {
      $missing[] = [
        "id" => (int)$row["id"],
        "chat" => $chat,
        "invite_link" => (string)($row["invite_link"] ?? ""),
        "reason" => "left"
      ];
    }
  }
  return $missing;
}

function sendForceJoinMessage(string $token, int $chatId, array $missing): void {
  $text = "🚫 <b>Join all required channels/groups first</b>\n\n";
  $rows = [];

  foreach ($missing as $m) {
    $link = trim((string)($m["invite_link"] ?? ""));
    if ($link !== "") {
      $rows[] = [[ "text" => "✅ Join", "url" => $link ]];
    } else {
      $text .= "• Missing: <code>" . htmlspecialchars((string)$m["chat"]) . "</code>\n";
    }
  }

  $rows[] = [[ "text" => "🔄 Check Again", "callback_data" => "check_join" ]];
  sendMessage($token, $chatId, $text, ["reply_markup" => buildInlineKeyboard($rows)]);
}

// -------- Withdraw cost --------
function getWithdrawCost(PDO $pdo, int $amount): int {
  $st = $pdo->prepare("SELECT points FROM withdraw_points WHERE amount = :a");
  $st->execute([":a"=>$amount]);
  $row = $st->fetch();
  return $row ? (int)$row["points"] : 999999;
}

// ---------------- WEB VERIFICATION PAGE ----------------
if (isset($_GET["v"])) {
  $token = (string)($_GET["v"] ?? "");
  $do = (string)($_GET["do"] ?? "");

  $st = $pdo->prepare("SELECT * FROM users WHERE verify_token = :t");
  $st->execute([":t"=>$token]);
  $u = $st->fetch();

  $msg = "";
  $primaryBtn = "";
  $deepLink = $BOT_USERNAME ? ("https://t.me/" . $BOT_USERNAME) : "";

  if (!$u) {
    $msg = "❌ Invalid or expired verification link.";
  } else {
    if ($do === "1") {
      $pdo->prepare("UPDATE users SET verified = TRUE WHERE id = :id")->execute([":id"=>$u["id"]]);

      // ✅ Send Telegram success message + unlock menu
      $uid = (int)$u["id"];
      $admin = isAdmin($uid, $ADMIN_IDS);
      sendMessage($BOT_TOKEN, $uid,
        "✅ <b>Verification successful!</b>\n\nNow your <b>Stats</b> and <b>Withdraw</b> are unlocked 🎉",
        ["reply_markup" => userMenu($admin, true)]
      );

      $msg = "✅ Verified successfully! You can return to Telegram.";
    } else {
      $msg = "Click the button below to verify your account.";
      $primaryBtn = '<a class="btn" href="index.php?v='.htmlspecialchars($token).'&do=1">✅ Verify Now</a>';
    }
  }

  $backBtn = $deepLink ? '<a class="btn secondary" href="'.htmlspecialchars($deepLink).'">↩ Back to Telegram</a>' : '';

  header("Content-Type: text/html; charset=utf-8");
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verify</title>
    <style>
      :root { --bg1:#0ea5e9; --bg2:#22c55e; }
      body{
        margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
        min-height:100vh; display:flex; align-items:center; justify-content:center;
        background: radial-gradient(1200px 600px at 20% 10%, rgba(14,165,233,.35), transparent),
                    radial-gradient(1200px 600px at 80% 90%, rgba(34,197,94,.35), transparent),
                    linear-gradient(135deg, #0b1220, #0b1220);
        padding: 18px;
      }
      .card{
        width:min(520px, 100%);
        background: rgba(255,255,255,.96);
        border-radius: 18px;
        box-shadow: 0 20px 60px rgba(0,0,0,.35);
        overflow:hidden;
      }
      .top{
        padding: 20px 22px;
        background: linear-gradient(135deg, var(--bg1), var(--bg2));
        color:white;
      }
      .top h1{ margin:0; font-size: 20px; letter-spacing:.2px; }
      .content{ padding: 20px 22px 22px; }
      .status{
        padding: 12px 14px;
        border-radius: 12px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        font-weight: 800;
        margin-bottom: 16px;
        line-height: 1.35;
      }
      .btn{
        display:block; text-align:center; text-decoration:none;
        padding: 14px 16px; border-radius: 14px;
        background: #16a34a; color:white; font-weight: 900;
        margin-top: 10px;
      }
      .btn:hover{ filter: brightness(1.05); }
      .secondary{ background: #0ea5e9; }
      .foot{ padding: 0 22px 18px; color:#94a3b8; font-size: 12px; }
    </style>
  </head>
  <body>
    <div class="card">
      <div class="top"><h1>Account Verification</h1></div>
      <div class="content">
        <div class="status"><?php echo htmlspecialchars($msg); ?></div>
        <?php echo $primaryBtn; ?>
        <?php echo $backBtn; ?>
      </div>
      <div class="foot">If verification fails, open the link again from the bot and try once more.</div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// ---------------- TELEGRAM WEBHOOK ----------------
$raw = file_get_contents("php://input");
$update = json_decode($raw ?: "{}", true);
if (!is_array($update)) { echo "OK"; exit; }

$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// ---------------- CALLBACKS ----------------
if ($callback) {
  $cbId   = (string)($callback["id"] ?? "");
  $from   = $callback["from"] ?? [];
  $userId = (int)($from["id"] ?? 0);
  $chatId = (int)($callback["message"]["chat"]["id"] ?? 0);
  $msgId  = (int)($callback["message"]["message_id"] ?? 0);
  $data   = (string)($callback["data"] ?? "");

  if ($userId <= 0 || $chatId === 0) { echo "OK"; exit; }

  $u = upsertUser($pdo, $userId, $from["username"] ?? null);
  $admin = isAdmin($userId, $ADMIN_IDS);

  if ($data === "check_join") {
    $fj = loadForceJoin($pdo);
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $fj);

    if (count($missing) > 0) {
      answerCallback($BOT_TOKEN, $cbId, "Still missing joins.", false);

      $text = "🚫 <b>Join all required channels/groups first</b>\n\n";
      $rows = [];
      foreach ($missing as $m) {
        $link = trim((string)($m["invite_link"] ?? ""));
        if ($link !== "") $rows[] = [[ "text" => "✅ Join", "url" => $link ]];
        else $text .= "• Missing: <code>" . htmlspecialchars((string)$m["chat"]) . "</code>\n";
      }
      $rows[] = [[ "text" => "🔄 Check Again", "callback_data" => "check_join" ]];

      editMessageText($BOT_TOKEN, $chatId, $msgId, $text, ["reply_markup" => buildInlineKeyboard($rows)]);
    } else {
      answerCallback($BOT_TOKEN, $cbId, "All joined ✅", false);
      editMessageText($BOT_TOKEN, $chatId, $msgId, "✅ <b>All required joins completed!</b>\nNow continue in the bot.", ["reply_markup" => null]);
    }

    echo "OK"; exit;
  }

  if ($data === "wd_back") {
    answerCallback($BOT_TOKEN, $cbId, "Back", false);
    $rows = [
      [
        ["text" => formatMoneyOption(500),  "callback_data" => "wd:500"],
        ["text" => formatMoneyOption(1000), "callback_data" => "wd:1000"],
      ],
      [
        ["text" => formatMoneyOption(2000), "callback_data" => "wd:2000"],
        ["text" => formatMoneyOption(4000), "callback_data" => "wd:4000"],
      ]
    ];
    editMessageText($BOT_TOKEN, $chatId, $msgId, "🎁 <b>Select withdrawal option</b>", ["reply_markup" => buildInlineKeyboard($rows)]);
    echo "OK"; exit;
  }

  // Withdraw
  if (preg_match('/^wd:(500|1000|2000|4000)$/', $data, $m)) {
    $amount = (int)$m[1];

    // must be verified
    if (!(bool)$u["verified"]) {
      $vt = ensureVerifyToken($pdo, $userId);
      $url = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");
      $rows = [];
      if ($url) $rows[] = [[ "text" => "✅ Verify (Web)", "url" => $url ]];
      $rows[] = [[ "text" => "🔙 Back", "callback_data" => "wd_back" ]];
      answerCallback($BOT_TOKEN, $cbId, "Verify first.", true);
      editMessageText($BOT_TOKEN, $chatId, $msgId, "⚠️ <b>You must verify your account before withdrawal.</b>", ["reply_markup" => buildInlineKeyboard($rows)]);
      echo "OK"; exit;
    }

    // force join
    $fj = loadForceJoin($pdo);
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $fj);
    if (count($missing) > 0) {
      answerCallback($BOT_TOKEN, $cbId, "Join required channels first.", true);
      sendForceJoinMessage($BOT_TOKEN, $chatId, $missing);
      echo "OK"; exit;
    }

    $need = getWithdrawCost($pdo, $amount);
    $points = (int)$u["points"];
    if ($points < $need) {
      answerCallback($BOT_TOKEN, $cbId, "Not enough points.", true);
      editMessageText($BOT_TOKEN, $chatId, $msgId,
        "❌ <b>Not enough points</b>\n\nYou need <b>{$need}</b> points for <b>{$amount}</b>.\nYou have <b>{$points}</b> points.",
        ["reply_markup" => null]
      );
      echo "OK"; exit;
    }

    // transaction: pick coupon, mark used, deduct points, log redeem
    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("
        WITH picked AS (
          SELECT id, code
          FROM coupons
          WHERE amount = :a AND is_used = FALSE
          ORDER BY id ASC
          FOR UPDATE SKIP LOCKED
          LIMIT 1
        )
        UPDATE coupons c
        SET is_used = TRUE, used_by = :uid
        FROM picked
        WHERE c.id = picked.id
        RETURNING picked.code AS code
      ");
      $st->execute([":a"=>$amount, ":uid"=>$userId]);
      $row = $st->fetch();

      if (!$row || empty($row["code"])) {
        $pdo->rollBack();
        answerCallback($BOT_TOKEN, $cbId, "Out of stock.", true);
        editMessageText($BOT_TOKEN, $chatId, $msgId, "⚠️ <b>Out of stock</b>\nNo coupons available for <b>{$amount}</b> right now.", ["reply_markup"=>null]);
        echo "OK"; exit;
      }

      $code = (string)$row["code"];

      $pdo->prepare("UPDATE users SET points = points - :need WHERE id = :id")
          ->execute([":need"=>$need, ":id"=>$userId]);

      $pdo->prepare("INSERT INTO redeems (user_id, coupon_code, amount) VALUES (:uid,:code,:amt)")
          ->execute([":uid"=>$userId, ":code"=>$code, ":amt"=>$amount]);

      $pdo->commit();

      answerCallback($BOT_TOKEN, $cbId, "Success ✅", false);
      editMessageText($BOT_TOKEN, $chatId, $msgId,
        "✅ <b>Withdrawal Successful</b>\n\n🎁 Amount: <b>{$amount}</b>\n⭐ Points Used: <b>{$need}</b>\n\n<code>{$code}</code>",
        ["reply_markup" => null]
      );

      // notify admins
      $uname = $from["username"] ?? "";
      $who = $uname ? "@{$uname}" : "User";
      foreach ($ADMIN_IDS as $aid) {
        if (!$aid) continue;
        sendMessage($BOT_TOKEN, $aid,
          "🚨 <b>New Withdrawal</b>\n\nUser: <b>{$who}</b>\nID: <code>{$userId}</code>\nAmount: <b>{$amount}</b>\nCoupon: <code>{$code}</code>"
        );
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      answerCallback($BOT_TOKEN, $cbId, "Error.", true);
      editMessageText($BOT_TOKEN, $chatId, $msgId, "❌ <b>Server error</b>\nPlease try again later.", ["reply_markup"=>null]);
    }

    echo "OK"; exit;
  }

  // Admin callbacks
  if ($admin && preg_match('/^admin_add:(500|1000|2000|4000)$/', $data, $m)) {
    $amt = (int)$m[1];
    setStep($pdo, $userId, "ADD_COUPON", $amt);
    answerCallback($BOT_TOKEN, $cbId, "Send codes line-by-line.", true);
    editMessageText($BOT_TOKEN, $chatId, $msgId,
      "➕ <b>Add Coupon</b> for <b>{$amt}</b>\n\nSend coupon codes <b>line by line</b>:\n<code>CODE1\nCODE2\nCODE3</code>",
      ["reply_markup"=>null]
    );
    echo "OK"; exit;
  }

  if ($admin && preg_match('/^admin_pts:(500|1000|2000|4000)$/', $data, $m)) {
    $amt = (int)$m[1];
    setStep($pdo, $userId, "CHG_POINTS", $amt);
    answerCallback($BOT_TOKEN, $cbId, "Send new points number.", true);
    editMessageText($BOT_TOKEN, $chatId, $msgId,
      "⚙ <b>Change Withdraw Points</b>\n\nAmount: <b>{$amt}</b>\nSend new points required (number only).",
      ["reply_markup"=>null]
    );
    echo "OK"; exit;
  }

  answerCallback($BOT_TOKEN, $cbId, "OK", false);
  echo "OK"; exit;
}

// ---------------- MESSAGES ----------------
if ($message) {
  $chat = $message["chat"] ?? [];
  $from = $message["from"] ?? [];
  $chatId = (int)($chat["id"] ?? 0);
  $userId = (int)($from["id"] ?? 0);
  $text   = (string)($message["text"] ?? "");
  $username = $from["username"] ?? null;

  if ($chatId === 0 || $userId <= 0) { echo "OK"; exit; }

  $u = upsertUser($pdo, $userId, $username);
  $admin = isAdmin($userId, $ADMIN_IDS);
  $verified = (bool)$u["verified"];
  $step = (string)($u["step"] ?? "");
  $stepAmt = (int)($u["step_amount"] ?? 0);

  // /start with referral
  if (preg_match('/^\/start(?:\s+(.+))?$/', $text, $m)) {
    $payload = trim($m[1] ?? "");

    // referral credit once
    if ($payload !== "" && ctype_digit($payload)) {
      $refId = (int)$payload;
      if ($refId > 0 && $refId !== $userId) {
        $u2 = getUser($pdo, $userId) ?: $u;
        if (empty($u2["referred_by"])) {
          $pdo->prepare("UPDATE users SET referred_by = :r WHERE id = :id")->execute([":r"=>$refId,":id"=>$userId]);
          $pdo->prepare("UPDATE users SET points = points + 1, referrals = referrals + 1 WHERE id = :r")->execute([":r"=>$refId]);
        }
      }
    }

    $vt = ensureVerifyToken($pdo, $userId);
    $verifyUrl = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");

    $welcome = "👋 <b>Welcome!</b>\n\n"
      . "✅ Earn <b>1 point</b> per referral.\n"
      . "🎁 Withdraw coupons using points.\n\n"
      . ($verified ? "Use the menu below." : "First verify to unlock Stats/Withdraw.");

    sendMessage($BOT_TOKEN, $chatId, $welcome, [
      "reply_markup" => userMenu($admin, $verified)
    ]);

    if (!$verified && $verifyUrl) {
      sendMessage($BOT_TOKEN, $chatId,
        "✅ <b>Verification required</b>\nTap below to verify:",
        ["reply_markup" => buildInlineKeyboard([
          [[ "text" => "✅ Verify (Web)", "url" => $verifyUrl ]]
        ])]
      );
    }

    echo "OK"; exit;
  }

  // ---------------- ADMIN STEP FLOWS ----------------

  // Add coupon bulk
  if ($admin && $step === "ADD_COUPON") {
    $amt = $stepAmt;
    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $codes = [];
    foreach ($lines as $ln) {
      $c = preg_replace('/\s+/', '', trim($ln));
      if ($c !== "") $codes[] = $c;
    }
    if (count($codes) === 0) {
      sendMessage($BOT_TOKEN, $chatId, "❌ No valid codes found. Send again line-by-line.");
      echo "OK"; exit;
    }

    $added = 0;
    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare("INSERT INTO coupons (code, amount) VALUES (:c, :a) ON CONFLICT (code) DO NOTHING");
      foreach ($codes as $c) {
        $st->execute([":c"=>$c, ":a"=>$amt]);
        $added += $st->rowCount();
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      sendMessage($BOT_TOKEN, $chatId, "❌ DB error while adding coupons.");
      echo "OK"; exit;
    }

    setStep($pdo, $userId, null, null);
    sendMessage($BOT_TOKEN, $chatId, "✅ Added <b>{$added}</b> coupons for <b>{$amt}</b>.\nDuplicates skipped.", [
      "reply_markup" => adminMenu()
    ]);
    echo "OK"; exit;
  }

  // Change withdraw points
  if ($admin && $step === "CHG_POINTS") {
    $amt = $stepAmt;
    $newPts = (int)preg_replace('/\D+/', '', $text);
    if ($newPts <= 0) {
      sendMessage($BOT_TOKEN, $chatId, "❌ Send a valid number (example: 12).");
      echo "OK"; exit;
    }

    $pdo->prepare("
      INSERT INTO withdraw_points (amount, points)
      VALUES (:a, :p)
      ON CONFLICT (amount) DO UPDATE SET points = EXCLUDED.points
    ")->execute([":a"=>$amt, ":p"=>$newPts]);

    setStep($pdo, $userId, null, null);
    sendMessage($BOT_TOKEN, $chatId, "✅ Updated: <b>{$amt}</b> now requires <b>{$newPts}</b> points.", [
      "reply_markup" => adminMenu()
    ]);
    echo "OK"; exit;
  }

  // Add Force Join: step 1 ask chat_id
  if ($admin && $step === "ADD_FJ_CHAT") {
    $chatVal = normalizeChatId($text);
    if ($chatVal === "" || strlen($chatVal) < 2) {
      sendMessage($BOT_TOKEN, $chatId, "❌ Invalid. Send @username or -100... chat_id.");
      echo "OK"; exit;
    }
    // store chat_id in step string
    $pdo->prepare("UPDATE users SET step = :s WHERE id = :id")
        ->execute([":s" => "ADD_FJ_LINK|".$chatVal, ":id" => $userId]);

    sendMessage($BOT_TOKEN, $chatId,
      "✅ Chat saved: <code>".htmlspecialchars($chatVal)."</code>\n\nNow send <b>invite link</b> (or type <code>skip</code>):\nExample:\n<code>https://t.me/+AbCdEf</code>"
    );
    echo "OK"; exit;
  }

  // Add Force Join: step 2 insert
  if ($admin && str_starts_with($step, "ADD_FJ_LINK|")) {
    $chatVal = substr($step, strlen("ADD_FJ_LINK|"));
    $link = trim($text);
    if (strtolower($link) === "skip") $link = "";

    $pdo->prepare("INSERT INTO force_join (chat_id, invite_link, is_active) VALUES (:c, :l, TRUE)")
        ->execute([":c"=>$chatVal, ":l"=>$link]);

    setStep($pdo, $userId, null, null);
    sendMessage($BOT_TOKEN, $chatId, "✅ Added force join:\nChat: <code>".htmlspecialchars($chatVal)."</code>", [
      "reply_markup" => adminMenu()
    ]);
    echo "OK"; exit;
  }

  // Remove Force Join
  if ($admin && $step === "REMOVE_FJ") {
    $id = (int)preg_replace('/\D+/', '', $text);
    if ($id <= 0) {
      sendMessage($BOT_TOKEN, $chatId, "❌ Send a valid ID number.");
      echo "OK"; exit;
    }

    $pdo->prepare("UPDATE force_join SET is_active = FALSE WHERE id = :id")->execute([":id"=>$id]);
    setStep($pdo, $userId, null, null);
    sendMessage($BOT_TOKEN, $chatId, "✅ Removed force join ID: <b>{$id}</b>", [
      "reply_markup" => adminMenu()
    ]);
    echo "OK"; exit;
  }

  // ---------------- MAIN MENU BUTTONS ----------------
  if ($text === "✅ Verify") {
    $vt = ensureVerifyToken($pdo, $userId);
    $verifyUrl = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");
    if (!$verifyUrl) {
      sendMessage($BOT_TOKEN, $chatId, "⚠️ SITE_URL is not set in Render env vars.", [
        "reply_markup" => userMenu($admin, $verified)
      ]);
      echo "OK"; exit;
    }
    sendMessage($BOT_TOKEN, $chatId, "✅ <b>Verify your account</b>\nTap below:", [
      "reply_markup" => buildInlineKeyboard([
        [[ "text" => "✅ Verify (Web)", "url" => $verifyUrl ]]
      ])
    ]);
    echo "OK"; exit;
  }

  if ($text === "🔗 Referral Link") {
    $refLink = "https://t.me/" . ($BOT_USERNAME ?: "YOUR_BOT_USERNAME") . "?start=" . $userId;
    sendMessage($BOT_TOKEN, $chatId,
      "🔗 <b>Your Referral Link</b>\n\n<code>{$refLink}</code>\n\n✅ 1 referral = 1 point",
      ["reply_markup" => userMenu($admin, $verified)]
    );
    echo "OK"; exit;
  }

  // Locked until verified
  if (!$verified && ($text === "📊 Stats" || $text === "🎁 Withdraw")) {
    $vt = ensureVerifyToken($pdo, $userId);
    $verifyUrl = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");
    sendMessage($BOT_TOKEN, $chatId,
      "🔒 <b>Locked</b>\nVerify first to unlock Stats/Withdraw.",
      ["reply_markup" => $verifyUrl ? buildInlineKeyboard([[[ "text"=>"✅ Verify (Web)", "url"=>$verifyUrl ]]]) : null]
    );
    echo "OK"; exit;
  }

  if ($text === "📊 Stats") {
    // force join check
    $fj = loadForceJoin($pdo);
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $fj);
    if (count($missing) > 0) {
      sendForceJoinMessage($BOT_TOKEN, $chatId, $missing);
      echo "OK"; exit;
    }

    $u = getUser($pdo, $userId) ?: $u;
    $points = (int)$u["points"];
    $refs   = (int)$u["referrals"];

    sendMessage($BOT_TOKEN, $chatId,
      "📊 <b>Your Stats</b>\n\n⭐ Points: <b>{$points}</b>\n👥 Referrals: <b>{$refs}</b>\n✅ Verified: <b>YES</b>",
      ["reply_markup" => userMenu($admin, true)]
    );
    echo "OK"; exit;
  }

  if ($text === "🎁 Withdraw") {
    // force join check
    $fj = loadForceJoin($pdo);
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $fj);
    if (count($missing) > 0) {
      sendForceJoinMessage($BOT_TOKEN, $chatId, $missing);
      echo "OK"; exit;
    }

    $rows = [
      [
        ["text" => formatMoneyOption(500),  "callback_data" => "wd:500"],
        ["text" => formatMoneyOption(1000), "callback_data" => "wd:1000"],
      ],
      [
        ["text" => formatMoneyOption(2000), "callback_data" => "wd:2000"],
        ["text" => formatMoneyOption(4000), "callback_data" => "wd:4000"],
      ]
    ];
    sendMessage($BOT_TOKEN, $chatId, "🎁 <b>Select withdrawal option</b>", [
      "reply_markup" => buildInlineKeyboard($rows)
    ]);
    echo "OK"; exit;
  }

  // ---------------- ADMIN PANEL ----------------
  if ($admin && $text === "🛠 Admin Panel") {
    sendMessage($BOT_TOKEN, $chatId, "🛠 <b>Admin Panel</b>", ["reply_markup" => adminMenu()]);
    echo "OK"; exit;
  }

  if ($admin && $text === "🔙 Back") {
    sendMessage($BOT_TOKEN, $chatId, "✅ Back to user menu.", ["reply_markup" => userMenu(true, $verified)]);
    echo "OK"; exit;
  }

  if ($admin && $text === "➕ Add Coupon") {
    $rows = [
      [
        ["text" => formatMoneyOption(500),  "callback_data" => "admin_add:500"],
        ["text" => formatMoneyOption(1000), "callback_data" => "admin_add:1000"],
      ],
      [
        ["text" => formatMoneyOption(2000), "callback_data" => "admin_add:2000"],
        ["text" => formatMoneyOption(4000), "callback_data" => "admin_add:4000"],
      ]
    ];
    sendMessage($BOT_TOKEN, $chatId, "➕ <b>Select coupon amount to add</b>", [
      "reply_markup" => buildInlineKeyboard($rows)
    ]);
    echo "OK"; exit;
  }

  if ($admin && $text === "📦 Stock") {
    $amounts = [500,1000,2000,4000];
    $lines = [];
    foreach ($amounts as $a) {
      $st = $pdo->prepare("SELECT COUNT(*) AS c FROM coupons WHERE amount = :a AND is_used = FALSE");
      $st->execute([":a"=>$a]);
      $c = (int)($st->fetch()["c"] ?? 0);
      $lines[] = "• <b>{$a}</b>: <b>{$c}</b>";
    }
    sendMessage($BOT_TOKEN, $chatId, "📦 <b>Current Stock</b>\n\n".implode("\n",$lines), ["reply_markup" => adminMenu()]);
    echo "OK"; exit;
  }

  if ($admin && $text === "⚙ Change Withdraw Points") {
    $rows = [
      [
        ["text" => "500",  "callback_data" => "admin_pts:500"],
        ["text" => "1000", "callback_data" => "admin_pts:1000"],
      ],
      [
        ["text" => "2000", "callback_data" => "admin_pts:2000"],
        ["text" => "4000", "callback_data" => "admin_pts:4000"],
      ]
    ];
    sendMessage($BOT_TOKEN, $chatId, "⚙ <b>Select amount to change points</b>", [
      "reply_markup" => buildInlineKeyboard($rows)
    ]);
    echo "OK"; exit;
  }

  if ($admin && $text === "📜 Redeems Log") {
    $st = $pdo->query("
      SELECT r.amount, r.coupon_code, u.username, r.user_id
      FROM redeems r
      LEFT JOIN users u ON u.id = r.user_id
      ORDER BY r.id DESC
      LIMIT 10
    ");
    $rows = $st->fetchAll();
    if (!$rows) {
      sendMessage($BOT_TOKEN, $chatId, "📜 <b>Redeems Log</b>\n\nNo redeems yet.", ["reply_markup" => adminMenu()]);
      echo "OK"; exit;
    }
    $out = "📜 <b>Last 10 Redeems</b>\n\n";
    $i = 1;
    foreach ($rows as $r) {
      $uname = $r["username"] ? "@".$r["username"] : "ID ".$r["user_id"];
      $out .= "{$i}. {$uname} — <b>{$r['amount']}</b> — <code>{$r['coupon_code']}</code>\n";
      $i++;
    }
    sendMessage($BOT_TOKEN, $chatId, $out, ["reply_markup" => adminMenu()]);
    echo "OK"; exit;
  }

  // Force Join Manager
  if ($admin && $text === "📋 Force Join List") {
    $rows = loadForceJoin($pdo);
    if (!$rows) {
      sendMessage($BOT_TOKEN, $chatId, "📋 <b>Force Join List</b>\n\nNo channels added yet.", ["reply_markup" => adminMenu()]);
      echo "OK"; exit;
    }
    $out = "📋 <b>Force Join List</b>\n\n";
    foreach ($rows as $r) {
      $out .= "ID: <b>{$r['id']}</b>\nChat: <code>{$r['chat_id']}</code>\n";
      if (!empty($r['invite_link'])) $out .= "Link: " . htmlspecialchars($r['invite_link']) . "\n";
      $out .= "\n";
    }
    sendMessage($BOT_TOKEN, $chatId, $out, ["reply_markup" => adminMenu()]);
    echo "OK"; exit;
  }

  if ($admin && $text === "➕ Add Force Join") {
    setStep($pdo, $userId, "ADD_FJ_CHAT", null);
    sendMessage($BOT_TOKEN, $chatId,
      "➕ <b>Add Force Join</b>\n\nSend <b>chat_id</b> or <b>@username</b>:\nExamples:\n<code>@mychannel</code>\n<code>-1001234567890</code>",
      ["reply_markup" => adminMenu()]
    );
    echo "OK"; exit;
  }

  if ($admin && $text === "➖ Remove Force Join") {
    setStep($pdo, $userId, "REMOVE_FJ", null);
    sendMessage($BOT_TOKEN, $chatId,
      "➖ <b>Remove Force Join</b>\n\nSend the <b>ID</b> (from Force Join List). Example: <code>3</code>",
      ["reply_markup" => adminMenu()]
    );
    echo "OK"; exit;
  }

  // fallback
  sendMessage($BOT_TOKEN, $chatId, "Use the menu buttons 👇", [
    "reply_markup" => userMenu($admin, $verified)
  ]);
  echo "OK"; exit;
}

echo "OK";

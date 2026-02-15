<?php
/**
 * ✅ SINGLE-FILE index.php (Telegram Referral Bot + Web Verification in SAME file)
 *
 * FEATURES:
 * ✅ Reply keyboard user menu: Stats / Referral Link / Withdraw (+ Admin Panel for admins)
 * ✅ 1 referral = 1 point (only first time, no double-credit)
 * ✅ Withdraw: 500/1000/2000/4000 (points required from withdraw_points table)
 * ✅ Coupons stock per amount + auto assign unused coupon
 * ✅ Admin panel:
 *    - Add Coupon (bulk line-by-line) for 500/1000/2000/4000
 *    - Stock
 *    - Change Withdraw Points
 *    - Redeems Log (last 10)
 * ✅ Admin notification on every withdrawal
 * ✅ Force join 3 groups/channels (supports public @username OR numeric chat_id like -100xxxx)
 * ✅ Web verification page with good UI
 *
 * ------------------- REQUIRED ENV VARS (Render) -------------------
 * BOT_TOKEN=123:ABC
 * DATABASE_URL=postgresql://user:pass@host:5432/dbname
 * ADMIN_IDS=123456789,987654321
 * BOT_USERNAME=YourBotUsername   (without @)
 *
 * FORCE_JOIN_1=@group1_or_-100xxxx
 * FORCE_JOIN_2=@group2_or_-100xxxx
 * FORCE_JOIN_3=@group3_or_-100xxxx
 *
 * INVITE_LINK_1=https://t.me/xxxxx   (optional but recommended)
 * INVITE_LINK_2=https://t.me/xxxxx
 * INVITE_LINK_3=https://t.me/xxxxx
 *
 * SITE_URL=https://your-render-domain.onrender.com   (no trailing slash)
 * ---------------------------------------------------------------
 *
 * ------------------- SUPABASE SQL (run once) -------------------
 * CREATE TABLE IF NOT EXISTS users (
 *   id BIGINT PRIMARY KEY,
 *   username TEXT,
 *   points INT DEFAULT 0,
 *   referrals INT DEFAULT 0,
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
 *   amount INT NOT NULL,
 *   is_used BOOLEAN DEFAULT FALSE,
 *   used_by BIGINT,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 *
 * CREATE TABLE IF NOT EXISTS withdraw_points (
 *   amount INT PRIMARY KEY,
 *   points INT NOT NULL
 * );
 * INSERT INTO withdraw_points (amount, points) VALUES
 * (500, 3),
 * (1000, 10),
 * (2000, 25),
 * (4000, 40)
 * ON CONFLICT (amount) DO NOTHING;
 *
 * CREATE TABLE IF NOT EXISTS redeems (
 *   id SERIAL PRIMARY KEY,
 *   user_id BIGINT,
 *   coupon_code TEXT,
 *   amount INT,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 * ---------------------------------------------------------------
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------- CONFIG ----------
$BOT_TOKEN    = getenv("BOT_TOKEN") ?: "";
$DB_URL       = getenv("DATABASE_URL") ?: "";
$ADMIN_IDS_CSV= getenv("ADMIN_IDS") ?: "";
$BOT_USERNAME = getenv("BOT_USERNAME") ?: ""; // without @
$SITE_URL     = getenv("SITE_URL") ?: "";

// Force join (3)
$FORCE_JOIN = [
  getenv("FORCE_JOIN_1") ?: "",
  getenv("FORCE_JOIN_2") ?: "",
  getenv("FORCE_JOIN_3") ?: "",
];
$INVITE_LINK = [
  getenv("INVITE_LINK_1") ?: "",
  getenv("INVITE_LINK_2") ?: "",
  getenv("INVITE_LINK_3") ?: "",
];

if (!$BOT_TOKEN) { http_response_code(500); echo "BOT_TOKEN missing"; exit; }
if (!$DB_URL)    { http_response_code(500); echo "DATABASE_URL missing"; exit; }
if (!$BOT_USERNAME) { /* not fatal but recommended */ }
if (!$SITE_URL)  { /* not fatal but recommended */ }

$ADMIN_IDS = array_values(array_filter(array_map('trim', explode(',', $ADMIN_IDS_CSV))));

// ---------- DB ----------
function pdo_from_database_url(string $dbUrl): PDO {
  $parts = parse_url($dbUrl);
  if (!$parts || empty($parts['host']) || empty($parts['path'])) {
    throw new Exception("Invalid DATABASE_URL");
  }
  $user = $parts['user'] ?? '';
  $pass = $parts['pass'] ?? '';
  $host = $parts['host'];
  $port = $parts['port'] ?? 5432;
  $db   = ltrim($parts['path'], '/');

  $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

$pdo = pdo_from_database_url($DB_URL);

// ---------- TELEGRAM API HELPERS ----------
function tg_api(string $token, string $method, array $params = []): array {
  $url = "https://api.telegram.org/bot{$token}/{$method}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $params,
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
    "disable_web_page_preview" => true
  ], $opts);

  tg_api($token, "sendMessage", $params);
}

function answerCallback(string $token, string $callbackId, string $text = "", bool $alert = false): void {
  tg_api($token, "answerCallbackQuery", [
    "callback_query_id" => $callbackId,
    "text" => $text,
    "show_alert" => $alert ? "true" : "false",
  ]);
}

function editMessageText(string $token, int|string $chatId, int $messageId, string $text, array $opts = []): void {
  $params = array_merge([
    "chat_id" => $chatId,
    "message_id" => $messageId,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ], $opts);

  tg_api($token, "editMessageText", $params);
}

function buildReplyKeyboard(array $rows, bool $resize = true): string {
  return json_encode([
    "keyboard" => $rows,
    "resize_keyboard" => $resize,
    "one_time_keyboard" => false
  ], JSON_UNESCAPED_UNICODE);
}

function buildInlineKeyboard(array $rows): string {
  return json_encode([
    "inline_keyboard" => $rows
  ], JSON_UNESCAPED_UNICODE);
}

// ---------- BOT LOGIC HELPERS ----------
function isAdmin(int $userId, array $adminIds): bool {
  return in_array((string)$userId, $adminIds, true);
}

function nowToken(int $len = 26): string {
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
  if (!empty($u['verify_token'])) return (string)$u['verify_token'];
  $token = nowToken(32);
  $st = $pdo->prepare("UPDATE users SET verify_token = :t WHERE id = :id");
  $st->execute([":t" => $token, ":id" => $userId]);
  return $token;
}

function getWithdrawCost(PDO $pdo, int $amount): int {
  $st = $pdo->prepare("SELECT points FROM withdraw_points WHERE amount = :a");
  $st->execute([":a" => $amount]);
  $row = $st->fetch();
  if (!$row) return 999999;
  return (int)$row['points'];
}

function formatMoneyOption(int $amount): string {
  // “500 off on 500” style
  return "{$amount} OFF ON {$amount}";
}

function userMenu(bool $isAdmin): string {
  $rows = [
    ["📊 Stats", "🔗 Referral Link"],
    ["🎁 Withdraw", "✅ Verify"]
  ];
  if ($isAdmin) $rows[] = ["🛠 Admin Panel"];
  return buildReplyKeyboard($rows);
}

function adminMenu(): string {
  $rows = [
    ["➕ Add Coupon", "📦 Stock"],
    ["⚙ Change Withdraw Points", "📜 Redeems Log"],
    ["🔙 Back"]
  ];
  return buildReplyKeyboard($rows);
}

function forceJoinMissing(string $token, int $userId, array $forceJoin): array {
  // Returns list of missing chats (index + chatId/username)
  $missing = [];
  foreach ($forceJoin as $i => $chat) {
    $chat = trim($chat);
    if ($chat === "") continue;

    $res = tg_api($token, "getChatMember", [
      "chat_id" => $chat,
      "user_id" => $userId
    ]);

    // If bot not admin in channel or chat_id wrong => treat as missing + show admin error later
    if (!($res["ok"] ?? false)) {
      $missing[] = ["idx" => $i, "chat" => $chat, "reason" => "unreachable"];
      continue;
    }

    $status = $res["result"]["status"] ?? "";
    if ($status === "left" || $status === "kicked") {
      $missing[] = ["idx" => $i, "chat" => $chat, "reason" => "left"];
    }
  }
  return $missing;
}

function sendForceJoinMessage(string $token, int $chatId, array $missing, array $inviteLinks): void {
  $text = "🚫 <b>Join all required groups/channels first</b>\n\n";
  $rows = [];
  foreach ($missing as $m) {
    $idx = (int)$m["idx"];
    $label = "Join Group " . ($idx + 1);
    $link = trim($inviteLinks[$idx] ?? "");
    if ($link !== "") {
      $rows[] = [[ "text" => "✅ {$label}", "url" => $link ]];
    } else {
      // If no invite link, at least show chat identifier
      $text .= "• Missing: <code>" . htmlspecialchars((string)$m["chat"]) . "</code>\n";
    }
  }
  $rows[] = [[ "text" => "🔄 Check Again", "callback_data" => "check_join" ]];

  sendMessage($token, $chatId, $text, [
    "reply_markup" => buildInlineKeyboard($rows)
  ]);
}

function webVerifyUrl(string $siteUrl, string $token): string {
  $siteUrl = rtrim($siteUrl, "/");
  return $siteUrl . "/index.php?v=" . urlencode($token);
}

// ---------- WEB VERIFICATION ----------
if (isset($_GET["v"])) {
  $token = (string)($_GET["v"] ?? "");
  $do = (string)($_GET["do"] ?? "");

  // Load user by token
  $st = $pdo->prepare("SELECT * FROM users WHERE verify_token = :t");
  $st->execute([":t" => $token]);
  $u = $st->fetch();

  $ok = false;
  $msg = "";
  $deepLink = "";
  if ($u) {
    if ($do === "1") {
      $st2 = $pdo->prepare("UPDATE users SET verified = TRUE WHERE id = :id");
      $st2->execute([":id" => $u["id"]]);
      $ok = true;
      $msg = "✅ Verified successfully!";
    } else {
      $msg = "Click the button below to verify your account.";
    }
    $botUser = getenv("BOT_USERNAME") ?: "";
    if ($botUser) {
      $deepLink = "https://t.me/" . $botUser;
    }
  } else {
    $msg = "❌ Invalid or expired verification link.";
  }

  $primaryBtn = ($u && $do !== "1")
    ? ('<a class="btn" href="index.php?v='.htmlspecialchars($token).'&do=1">✅ Verify Now</a>')
    : '';

  $backBtn = ($deepLink !== "")
    ? ('<a class="btn secondary" href="'.htmlspecialchars($deepLink).'">↩ Back to Telegram</a>')
    : '';

  header("Content-Type: text/html; charset=utf-8");
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verify</title>
    <style>
      :root { --bg1:#0ea5e9; --bg2:#22c55e; --card:#ffffff; --text:#0f172a; --muted:#64748b; }
      body{
        margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
        min-height:100vh; display:flex; align-items:center; justify-content:center;
        background: radial-gradient(1200px 600px at 20% 10%, rgba(14,165,233,.35), transparent),
                    radial-gradient(1200px 600px at 80% 90%, rgba(34,197,94,.35), transparent),
                    linear-gradient(135deg, #0b1220, #0b1220);
        color: var(--text);
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
      .content p{ margin: 0 0 14px; color: var(--muted); line-height: 1.5; }
      .status{
        padding: 12px 14px;
        border-radius: 12px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        font-weight: 600;
        margin-bottom: 16px;
      }
      .btn{
        display:block; text-align:center; text-decoration:none;
        padding: 14px 16px; border-radius: 14px;
        background: #16a34a; color:white; font-weight: 800;
        margin-top: 10px;
      }
      .btn:hover{ filter: brightness(1.05); }
      .secondary{
        background: #0ea5e9;
      }
      .foot{ padding: 0 22px 18px; color:#94a3b8; font-size: 12px; }
    </style>
  </head>
  <body>
    <div class="card">
      <div class="top">
        <h1>Account Verification</h1>
      </div>
      <div class="content">
        <div class="status"><?php echo htmlspecialchars($msg); ?></div>
        <?php echo $primaryBtn; ?>
        <?php echo $backBtn; ?>
      </div>
      <div class="foot">
        If your verification fails, open the link again from the bot and try once more.
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// ---------- TELEGRAM WEBHOOK ----------
$raw = file_get_contents("php://input");
$update = json_decode($raw ?: "{}", true);
if (!is_array($update)) { echo "OK"; exit; }

$message = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// Handle callbacks
if ($callback) {
  $cbId = $callback["id"] ?? "";
  $from = $callback["from"] ?? [];
  $userId = (int)($from["id"] ?? 0);
  $chatId = (int)($callback["message"]["chat"]["id"] ?? 0);
  $msgId  = (int)($callback["message"]["message_id"] ?? 0);
  $data   = (string)($callback["data"] ?? "");

  if ($userId <= 0 || $chatId === 0) { echo "OK"; exit; }

  $u = upsertUser($pdo, $userId, $from["username"] ?? null);
  $admin = isAdmin($userId, $ADMIN_IDS);

  if ($data === "check_join") {
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $GLOBALS["FORCE_JOIN"]);
    if (count($missing) > 0) {
      answerCallback($BOT_TOKEN, $cbId, "Still missing joins.", false);
      // re-send message (edit)
      $text = "🚫 <b>Join all required groups/channels first</b>\n\n";
      $rows = [];
      foreach ($missing as $m) {
        $idx = (int)$m["idx"];
        $label = "Join Group " . ($idx + 1);
        $link = trim($GLOBALS["INVITE_LINK"][$idx] ?? "");
        if ($link !== "") $rows[] = [[ "text" => "✅ {$label}", "url" => $link ]];
      }
      $rows[] = [[ "text" => "🔄 Check Again", "callback_data" => "check_join" ]];
      editMessageText($BOT_TOKEN, $chatId, $msgId, $text, ["reply_markup" => buildInlineKeyboard($rows)]);
    } else {
      answerCallback($BOT_TOKEN, $cbId, "All joined ✅", false);
      editMessageText($BOT_TOKEN, $chatId, $msgId,
        "✅ <b>All required joins completed!</b>\nNow use the menu.",
        ["reply_markup" => null]
      );
    }
    echo "OK"; exit;
  }

  // Withdraw selection
  if (preg_match('/^wd:(500|1000|2000|4000)$/', $data, $m)) {
    $amount = (int)$m[1];

    // Must be verified
    if (!(bool)$u["verified"]) {
      $vt = ensureVerifyToken($pdo, $userId);
      $url = ($GLOBALS["SITE_URL"] ? webVerifyUrl($GLOBALS["SITE_URL"], $vt) : "");
      $rows = [];
      if ($url) $rows[] = [[ "text" => "✅ Verify (Web)", "url" => $url ]];
      $rows[] = [[ "text" => "🔙 Back", "callback_data" => "wd_back" ]];
      answerCallback($BOT_TOKEN, $cbId, "Verify first.", true);
      editMessageText($BOT_TOKEN, $chatId, $msgId,
        "⚠️ <b>You must verify your account before withdrawal.</b>",
        ["reply_markup" => buildInlineKeyboard($rows)]
      );
      echo "OK"; exit;
    }

    // Force join check
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $GLOBALS["FORCE_JOIN"]);
    if (count($missing) > 0) {
      answerCallback($BOT_TOKEN, $cbId, "Join required groups first.", true);
      sendForceJoinMessage($BOT_TOKEN, $chatId, $missing, $GLOBALS["INVITE_LINK"]);
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

    // Allocate coupon transactionally
    try {
      $pdo->beginTransaction();

      // Take one unused coupon for this amount (SKIP LOCKED)
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
      $st->execute([":a" => $amount, ":uid" => $userId]);
      $row = $st->fetch();

      if (!$row || empty($row["code"])) {
        $pdo->rollBack();
        answerCallback($BOT_TOKEN, $cbId, "Out of stock.", true);
        editMessageText($BOT_TOKEN, $chatId, $msgId,
          "⚠️ <b>Out of stock</b>\nNo coupons available for <b>{$amount}</b> right now. Please try later.",
          ["reply_markup" => null]
        );
        echo "OK"; exit;
      }

      $code = (string)$row["code"];

      // Deduct points
      $st2 = $pdo->prepare("UPDATE users SET points = points - :need WHERE id = :id");
      $st2->execute([":need" => $need, ":id" => $userId]);

      // Log redeem
      $st3 = $pdo->prepare("
        INSERT INTO redeems (user_id, coupon_code, amount)
        VALUES (:uid, :code, :amt)
      ");
      $st3->execute([":uid" => $userId, ":code" => $code, ":amt" => $amount]);

      $pdo->commit();

      // Notify user
      answerCallback($BOT_TOKEN, $cbId, "Success ✅", false);
      editMessageText($BOT_TOKEN, $chatId, $msgId,
        "✅ <b>Withdrawal Successful</b>\n\n🎁 Amount: <b>{$amount}</b>\n⭐ Points Used: <b>{$need}</b>\n\n<code>{$code}</code>\n\n(Use this coupon on your order.)",
        ["reply_markup" => null]
      );

      // Notify admins
      $uname = $from["username"] ?? "";
      $who = $uname ? "@{$uname}" : "User";
      foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
        if (!$aid) continue;
        sendMessage($BOT_TOKEN, $aid,
          "🚨 <b>New Withdrawal</b>\n\nUser: <b>{$who}</b>\nID: <code>{$userId}</code>\nAmount: <b>{$amount}</b>\nCoupon: <code>{$code}</code>"
        );
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      answerCallback($BOT_TOKEN, $cbId, "Error.", true);
      editMessageText($BOT_TOKEN, $chatId, $msgId,
        "❌ <b>Server error</b>\nPlease try again later.",
        ["reply_markup" => null]
      );
    }

    echo "OK"; exit;
  }

  // Withdraw back
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
    editMessageText($BOT_TOKEN, $chatId, $msgId,
      "🎁 <b>Select withdrawal option</b>",
      ["reply_markup" => buildInlineKeyboard($rows)]
    );
    echo "OK"; exit;
  }

  // Admin select amount for add coupon
  if ($admin && preg_match('/^admin_add:(500|1000|2000|4000)$/', $data, $m)) {
    $amt = (int)$m[1];
    setStep($pdo, $userId, "ADD_COUPON", $amt);
    answerCallback($BOT_TOKEN, $cbId, "Send coupon codes now.", true);
    editMessageText($BOT_TOKEN, $chatId, $msgId,
      "➕ <b>Add Coupon</b> for <b>{$amt}</b>\n\nNow send coupon codes <b>line by line</b>:\nExample:\n<code>CODE1\nCODE2\nCODE3</code>",
      ["reply_markup" => null]
    );
    echo "OK"; exit;
  }

  // Admin change withdraw points select amount
  if ($admin && preg_match('/^admin_pts:(500|1000|2000|4000)$/', $data, $m)) {
    $amt = (int)$m[1];
    setStep($pdo, $userId, "CHG_POINTS", $amt);
    answerCallback($BOT_TOKEN, $cbId, "Send new points.", true);
    editMessageText($BOT_TOKEN, $chatId, $msgId,
      "⚙ <b>Change Withdraw Points</b>\n\nAmount: <b>{$amt}</b>\nNow send the <b>new points</b> required (number only).",
      ["reply_markup" => null]
    );
    echo "OK"; exit;
  }

  // Default
  answerCallback($BOT_TOKEN, $cbId, "OK", false);
  echo "OK"; exit;
}

// Handle messages
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

  // /start with referral
  if (preg_match('/^\/start(?:\s+(.+))?$/', $text, $m)) {
    $payload = trim($m[1] ?? "");
    // Referral credit only once if referred_by is null
    if ($payload !== "" && ctype_digit($payload)) {
      $refId = (int)$payload;
      if ($refId > 0 && $refId !== $userId) {
        // reload for referred_by
        $u = getUser($pdo, $userId) ?: $u;
        if (empty($u["referred_by"])) {
          // Set referred_by and credit referrer (if exists)
          $pdo->prepare("UPDATE users SET referred_by = :r WHERE id = :id")->execute([":r"=>$refId,":id"=>$userId]);
          $pdo->prepare("UPDATE users SET points = points + 1, referrals = referrals + 1 WHERE id = :r")->execute([":r"=>$refId]);
        }
      }
    }

    // Ensure verify token
    $vt = ensureVerifyToken($pdo, $userId);

    $verifyUrl = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");
    $welcome = "👋 <b>Welcome!</b>\n\n"
      . "✅ Earn <b>1 point</b> for every referral.\n"
      . "🎁 Use points to withdraw coupons.\n\n"
      . "Use the menu below.";

    sendMessage($BOT_TOKEN, $chatId, $welcome, [
      "reply_markup" => userMenu($admin)
    ]);

    // If not verified, send verify button
    if (!(bool)$u["verified"] && $verifyUrl) {
      sendMessage($BOT_TOKEN, $chatId,
        "✅ <b>Verification required</b>\nTap below to verify on the web:",
        ["reply_markup" => buildInlineKeyboard([
          [[ "text" => "✅ Verify (Web)", "url" => $verifyUrl ]]
        ])]
      );
    }

    echo "OK"; exit;
  }

  // If user is in a step (admin flows)
  $step = (string)($u["step"] ?? "");
  $stepAmt = (int)($u["step_amount"] ?? 0);

  // Admin: add coupons bulk
  if ($admin && $step === "ADD_COUPON") {
    $amt = $stepAmt;
    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $codes = [];
    foreach ($lines as $ln) {
      $c = trim($ln);
      if ($c === "") continue;
      // basic sanitize: remove spaces
      $c = preg_replace('/\s+/', '', $c);
      if ($c !== "") $codes[] = $c;
    }
    if (count($codes) === 0) {
      sendMessage($BOT_TOKEN, $chatId, "❌ No valid codes found. Send again line-by-line.");
      echo "OK"; exit;
    }

    $added = 0;
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("INSERT INTO coupons (code, amount) VALUES (:c, :a) ON CONFLICT (code) DO NOTHING");
      foreach ($codes as $c) {
        $st->execute([":c" => $c, ":a" => $amt]);
        $added += $st->rowCount(); // 1 if inserted, 0 if duplicate
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      sendMessage($BOT_TOKEN, $chatId, "❌ DB error while adding coupons.");
      echo "OK"; exit;
    }

    setStep($pdo, $userId, null, null);
    sendMessage($BOT_TOKEN, $chatId,
      "✅ Added <b>{$added}</b> coupons for <b>{$amt}</b>.\nDuplicates were skipped.",
      ["reply_markup" => adminMenu()]
    );
    echo "OK"; exit;
  }

  // Admin: change points
  if ($admin && $step === "CHG_POINTS") {
    $amt = $stepAmt;
    $newPts = (int)preg_replace('/\D+/', '', $text);
    if ($newPts <= 0) {
      sendMessage($BOT_TOKEN, $chatId, "❌ Send a valid number (example: 12).");
      echo "OK"; exit;
    }
    $st = $pdo->prepare("INSERT INTO withdraw_points (amount, points) VALUES (:a, :p)
                         ON CONFLICT (amount) DO UPDATE SET points = EXCLUDED.points");
    $st->execute([":a" => $amt, ":p" => $newPts]);
    setStep($pdo, $userId, null, null);
    sendMessage($BOT_TOKEN, $chatId, "✅ Updated: <b>{$amt}</b> now requires <b>{$newPts}</b> points.", [
      "reply_markup" => adminMenu()
    ]);
    echo "OK"; exit;
  }

  // MAIN MENU COMMANDS (reply keyboard)
  if ($text === "📊 Stats") {
    $u = getUser($pdo, $userId) ?: $u;
    $points = (int)$u["points"];
    $refs   = (int)$u["referrals"];
    $verified = (bool)$u["verified"];

    $msg = "📊 <b>Your Stats</b>\n\n"
      . "⭐ Points: <b>{$points}</b>\n"
      . "👥 Referrals: <b>{$refs}</b>\n"
      . "✅ Verified: <b>" . ($verified ? "YES" : "NO") . "</b>\n";
    sendMessage($BOT_TOKEN, $chatId, $msg, ["reply_markup" => userMenu($admin)]);
    echo "OK"; exit;
  }

  if ($text === "🔗 Referral Link") {
    $refLink = "https://t.me/" . ($BOT_USERNAME ?: "YOUR_BOT_USERNAME") . "?start=" . $userId;
    $msg = "🔗 <b>Your Referral Link</b>\n\n"
      . "Share this link:\n<code>{$refLink}</code>\n\n"
      . "✅ 1 referral = 1 point";
    sendMessage($BOT_TOKEN, $chatId, $msg, ["reply_markup" => userMenu($admin)]);
    echo "OK"; exit;
  }

  if ($text === "✅ Verify") {
    $vt = ensureVerifyToken($pdo, $userId);
    $verifyUrl = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");
    if (!$verifyUrl) {
      sendMessage($BOT_TOKEN, $chatId, "⚠️ SITE_URL is not set in Render env vars.", ["reply_markup" => userMenu($admin)]);
      echo "OK"; exit;
    }
    sendMessage($BOT_TOKEN, $chatId,
      "✅ <b>Verify your account</b>\nTap below:",
      ["reply_markup" => buildInlineKeyboard([
        [[ "text" => "✅ Verify (Web)", "url" => $verifyUrl ]]
      ])]
    );
    echo "OK"; exit;
  }

  if ($text === "🎁 Withdraw") {
    // Check verified
    $u = getUser($pdo, $userId) ?: $u;
    if (!(bool)$u["verified"]) {
      $vt = ensureVerifyToken($pdo, $userId);
      $verifyUrl = ($SITE_URL ? webVerifyUrl($SITE_URL, $vt) : "");
      $rows = [];
      if ($verifyUrl) $rows[] = [[ "text" => "✅ Verify (Web)", "url" => $verifyUrl ]];
      sendMessage($BOT_TOKEN, $chatId,
        "⚠️ <b>You must verify before withdrawal.</b>",
        ["reply_markup" => buildInlineKeyboard($rows)]
      );
      echo "OK"; exit;
    }

    // Force join check
    $missing = forceJoinMissing($BOT_TOKEN, $userId, $FORCE_JOIN);
    if (count($missing) > 0) {
      sendForceJoinMessage($BOT_TOKEN, $chatId, $missing, $INVITE_LINK);
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

  // ADMIN PANEL
  if ($admin && $text === "🛠 Admin Panel") {
    sendMessage($BOT_TOKEN, $chatId, "🛠 <b>Admin Panel</b>", ["reply_markup" => adminMenu()]);
    echo "OK"; exit;
  }

  if ($admin && $text === "🔙 Back") {
    sendMessage($BOT_TOKEN, $chatId, "✅ Back to user menu.", ["reply_markup" => userMenu(true)]);
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
      $st->execute([":a" => $a]);
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
      SELECT r.created_at, r.amount, r.coupon_code, u.username, r.user_id
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

  // Unknown text -> show menu
  sendMessage($BOT_TOKEN, $chatId, "Use the menu buttons 👇", ["reply_markup" => userMenu($admin)]);
  echo "OK"; exit;
}

echo "OK";

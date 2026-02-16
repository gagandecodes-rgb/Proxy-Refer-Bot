<?php
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_IDS = explode(",", getenv("ADMIN_IDS"));
$DATABASE_URL = getenv("DATABASE_URL");
$BASE_URL = getenv("BASE_URL");

$db = parse_url($DATABASE_URL);
$pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname=".ltrim($db['path'],"/"),
$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

function bot($method,$data=[]){
global $BOT_TOKEN;
$ch=curl_init("https://api.telegram.org/bot$BOT_TOKEN/$method");
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
$res=curl_exec($ch);
curl_close($ch);
return json_decode($res,true);
}

function isAdmin($id){
global $ADMIN_IDS;
return in_array($id,$ADMIN_IDS);
}

$update=json_decode(file_get_contents("php://input"),true);
if(!$update) exit;

$message=$update["message"]??null;
$callback=$update["callback_query"]??null;

# ================= MESSAGE =================
if($message){
$user_id=$message["from"]["id"];
$username=$message["from"]["username"]??"";
$text=$message["text"]??"";

$stmt=$pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user=$stmt->fetch();

# NEW USER
if(!$user){
$ref=null;
if(strpos($text,"/start ")===0){
$ref=explode(" ",$text)[1];
}
$pdo->prepare("INSERT INTO users(id,username,ref_by) VALUES(?,?,?)")
->execute([$user_id,$username,$ref]);
if($ref && $ref!=$user_id){
$pdo->prepare("UPDATE users SET points=points+1,referrals=referrals+1 WHERE id=?")
->execute([$ref]);
}
$stmt->execute([$user_id]);
$user=$stmt->fetch();
}

# ===== ADMIN PANEL OPEN =====
if($text=="⚙ Admin Panel" && isAdmin($user_id)){
$menu=[
"keyboard"=>[
[["text"=>"➕ Add Coupon"],["text"=>"📦 Stock"]],
[["text"=>"📜 Redeems Log"],["text"=>"⚙ Change Points"]],
[["text"=>"➕ Add Force Group"],["text"=>"➖ Remove Force Group"]],
[["text"=>"⬅ Back"]]
],
"resize_keyboard"=>true
];
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Admin Panel","reply_markup"=>json_encode($menu)]);
exit;
}

# ===== USER MENU =====
if($text=="⬅ Back"){
$menu=[
"keyboard"=>[
[["text"=>"📊 Stats"],["text"=>"💰 Withdraw"]],
[["text"=>"🔗 Referral Link"]]
],
"resize_keyboard"=>true
];
if(isAdmin($user_id)){
$menu["keyboard"][]=[["text"=>"⚙ Admin Panel"]];
}
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Main Menu","reply_markup"=>json_encode($menu)]);
exit;
}

# ===== ADD COUPON =====
if($text=="➕ Add Coupon" && isAdmin($user_id)){
$pdo->prepare("UPDATE users SET state='choose_coupon_type' WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Send Type: 500 / 1000 / 2000 / 4000"]);
exit;
}

if($user["state"]=="choose_coupon_type"){
$type=intval($text);
$pdo->prepare("UPDATE users SET state='add_coupon_$type' WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Send coupon codes line by line"]);
exit;
}

if(strpos($user["state"],"add_coupon_")===0){
$type=intval(str_replace("add_coupon_","",$user["state"]));
$codes=explode("\n",$text);
foreach($codes as $c){
$c=trim($c);
if($c!=""){
$pdo->prepare("INSERT INTO coupons(code,type) VALUES(?,?)")->execute([$c,$type]);
}
}
$pdo->prepare("UPDATE users SET state=NULL WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Coupons Added"]);
exit;
}

# ===== STOCK =====
if($text=="📦 Stock" && isAdmin($user_id)){
$res=$pdo->query("SELECT type,COUNT(*) as total FROM coupons WHERE used=false GROUP BY type")->fetchAll();
$msg="📦 Stock:\n";
foreach($res as $r){
$msg.="{$r["type"]} => {$r["total"]}\n";
}
bot("sendMessage",["chat_id"=>$user_id,"text"=>$msg]);
exit;
}

# ===== REDEEMS LOG =====
if($text=="📜 Redeems Log" && isAdmin($user_id)){
$res=$pdo->query("SELECT * FROM withdraw_logs ORDER BY id DESC LIMIT 10")->fetchAll();
$msg="Last 10 Withdraws:\n";
foreach($res as $r){
$msg.="User {$r["user_id"]} - {$r["type"]}\n";
}
bot("sendMessage",["chat_id"=>$user_id,"text"=>$msg]);
exit;
}

# ===== FORCE GROUP ADD =====
if($text=="➕ Add Force Group" && isAdmin($user_id)){
$pdo->prepare("UPDATE users SET state='add_force_group' WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Send group username like @channelname"]);
exit;
}

if($user["state"]=="add_force_group"){
$pdo->prepare("INSERT INTO force_groups(chat_id,title) VALUES(?,?)")
->execute([$text,$text]);
$pdo->prepare("UPDATE users SET state=NULL WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Force Group Added"]);
exit;
}

# ===== REMOVE FORCE GROUP =====
if($text=="➖ Remove Force Group" && isAdmin($user_id)){
$res=$pdo->query("SELECT * FROM force_groups")->fetchAll();
$msg="Send group username to remove:\n";
foreach($res as $r){ $msg.=$r["chat_id"]."\n"; }
$pdo->prepare("UPDATE users SET state='remove_force_group' WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>$msg]);
exit;
}

if($user["state"]=="remove_force_group"){
$pdo->prepare("DELETE FROM force_groups WHERE chat_id=?")->execute([$text]);
$pdo->prepare("UPDATE users SET state=NULL WHERE id=?")->execute([$user_id]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Removed"]);
exit;
}

}
?>

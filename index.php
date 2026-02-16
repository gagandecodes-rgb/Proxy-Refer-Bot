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
$url="https://api.telegram.org/bot$BOT_TOKEN/$method";
$ch=curl_init($url);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
$res=curl_exec($ch);
curl_close($ch);
return json_decode($res,true);
}

$update=json_decode(file_get_contents("php://input"),true);
if(!$update) exit;

$message=$update["message"]??null;
$callback=$update["callback_query"]??null;

if($message){
$user_id=$message["from"]["id"];
$username=$message["from"]["username"]??"";
$text=$message["text"]??"";

$stmt=$pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user=$stmt->fetch();

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
}

$stmt=$pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user=$stmt->fetch();

if(!$user["verified"]){
$groups=$pdo->query("SELECT * FROM force_groups")->fetchAll();
$buttons=[];
foreach($groups as $g){
$buttons[]=[["text"=>$g["title"],"url"=>"https://t.me/".str_replace("@","",$g["chat_id"])]];
}
$buttons[]=[["text"=>"✅ Joined All Channels","callback_data"=>"check_join"]];
bot("sendMessage",[
"chat_id"=>$user_id,
"text"=>"🚀 Please join all channels to continue:",
"reply_markup"=>json_encode(["inline_keyboard"=>$buttons])
]);
exit;
}

if($text=="📊 Stats"){
bot("sendMessage",[
"chat_id"=>$user_id,
"text"=>"Points: {$user["points"]}\nReferrals: {$user["referrals"]}"
]);
}

if($text=="🔗 Referral Link"){
$link="https://t.me/".bot("getMe")["result"]["username"]."?start=".$user_id;
bot("sendMessage",["chat_id"=>$user_id,"text"=>$link]);
}

if($text=="💰 Withdraw"){
$settings=$pdo->query("SELECT * FROM withdraw_settings")->fetchAll();
$keyboard=[];
foreach($settings as $s){
$keyboard["keyboard"][]=[["text"=>"{$s["type"]} OFF"]];}
bot("sendMessage",[
"chat_id"=>$user_id,
"text"=>"Choose withdraw option:",
"reply_markup"=>json_encode($keyboard)
]);
}

if(is_numeric(str_replace(" OFF","",$text))){
$type=intval(str_replace(" OFF","",$text));
$stmt=$pdo->prepare("SELECT required_points FROM withdraw_settings WHERE type=?");
$stmt->execute([$type]);
$row=$stmt->fetch();
if($user["points"] < $row["required_points"]){
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Not enough points"]);
}else{
$c=$pdo->prepare("SELECT * FROM coupons WHERE type=? AND used=false LIMIT 1");
$c->execute([$type]);
$coupon=$c->fetch();
if(!$coupon){
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Out of stock"]);
}else{
$pdo->prepare("UPDATE coupons SET used=true,used_by=? WHERE id=?")
->execute([$user_id,$coupon["id"]]);
$pdo->prepare("UPDATE users SET points=points-? WHERE id=?")
->execute([$row["required_points"],$user_id]);
$pdo->prepare("INSERT INTO withdraw_logs(user_id,coupon_code,type) VALUES(?,?,?)")
->execute([$user_id,$coupon["code"],$type]);
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Your Coupon: ".$coupon["code"]]);
foreach($ADMIN_IDS as $admin){
bot("sendMessage",["chat_id"=>$admin,"text"=>"User $user_id withdrew $type coupon"]);
}
}
}
}

}

if($callback){
$data=$callback["data"];
$user_id=$callback["from"]["id"];

if($data=="check_join"){
$groups=$pdo->query("SELECT * FROM force_groups")->fetchAll();
$joined=true;
foreach($groups as $g){
$res=bot("getChatMember",["chat_id"=>$g["chat_id"],"user_id"=>$user_id]);
if(!in_array($res["result"]["status"],["member","administrator","creator"])){
$joined=false;
}
}
if(!$joined){
bot("answerCallbackQuery",["callback_query_id"=>$callback["id"],"text"=>"Join all channels first"]);
}else{
$token=bin2hex(random_bytes(16));
$pdo->prepare("UPDATE users SET verify_token=? WHERE id=?")
->execute([$token,$user_id]);
$url="$BASE_URL/verify.php?token=$token";
bot("sendMessage",[
"chat_id"=>$user_id,
"text"=>"Now verify on website:",
"reply_markup"=>json_encode(["inline_keyboard"=>[
[["text"=>"✅ Verify Now","url"=>$url]],
[["text"=>"Completed Verification","callback_data"=>"complete_verify"]]
]])
]);
}
}

if($data=="complete_verify"){
$stmt=$pdo->prepare("SELECT verified FROM users WHERE id=?");
$stmt->execute([$user_id]);
$row=$stmt->fetch();
if($row["verified"]){
$menu=[
"keyboard"=>[
[["text"=>"📊 Stats"],["text"=>"💰 Withdraw"]],
[["text"=>"🔗 Referral Link"]]
],
"resize_keyboard"=>true
];
bot("sendMessage",["chat_id"=>$user_id,"text"=>"Welcome!","reply_markup"=>json_encode($menu)]);
}else{
bot("answerCallbackQuery",["callback_query_id"=>$callback["id"],"text"=>"Not verified yet"]);
}
}
}
?>

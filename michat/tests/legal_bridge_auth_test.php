<?php
declare(strict_types=1);
/**
 * Pure, fixture-only contract checks. These are NOT integration tests and
 * never connect to production MySQL, Bedrock, S3 or external hosts.
 */
require_once __DIR__.'/../includes/LegalBridge/LegalBridgeAuth.php';

function legalAssert(bool $condition, string $name): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$name}\n"); exit(1); }
    echo "PASS: {$name}\n";
}
$body='{"operation":"health","data":{}}';
$ts='1794700000';
$nonce=str_repeat('a',48);
$canonical=LegalBridgeAuth::canonical('POST','/api/legal-bridge.php',$body,$ts,$nonce,'legal-hostgator');
$expected="POST\n/api/legal-bridge.php\n".hash('sha256',$body)."\n".$ts."\n".$nonce."\nlegal-hostgator";
legalAssert(hash_equals($expected,$canonical),'canonical LF and body digest');
$secret=str_repeat('b',64);
$signed=hash_hmac('sha256',$canonical,$secret);
legalAssert(hash_equals($signed,hash_hmac('sha256',$canonical,$secret)),'valid deterministic signature');
legalAssert(!hash_equals($signed,hash_hmac('sha256',LegalBridgeAuth::canonical('POST','/api/legal-bridge.php',$body.' ', $ts,$nonce,'legal-hostgator'),$secret)),'mutated body invalidates signature');
legalAssert(!hash_equals($signed,hash_hmac('sha256',LegalBridgeAuth::canonical('POST','/api/other.php',$body,$ts,$nonce,'legal-hostgator'),$secret)),'mutated path invalidates signature');

$ref=new ReflectionClass(LegalBridgeAuth::class);
$auth=$ref->newInstanceWithoutConstructor();
$envNames=['LEGAL_BRIDGE_ENABLED','LEGAL_BRIDGE_KEY_ID','LEGAL_BRIDGE_SECRET'];
$before=[];
foreach($envNames as $n) $before[$n]=getenv($n);
try {
    putenv('LEGAL_BRIDGE_ENABLED=false');
    try {$auth->verify('POST','/api/legal-bridge.php',$body,[],true);legalAssert(false,'disabled');}
    catch(LegalBridgeError $e) {legalAssert($e->getMessage()==='bridge_disabled'&&$e->httpStatus===503,'disabled fails closed');}

    putenv('LEGAL_BRIDGE_ENABLED=true');
    putenv('LEGAL_BRIDGE_KEY_ID=legal-hostgator');
    putenv('LEGAL_BRIDGE_SECRET='.$secret);
    try {$auth->verify('POST','/api/legal-bridge.php',$body,[],false);legalAssert(false,'HTTPS');}
    catch(LegalBridgeError $e) {legalAssert($e->getMessage()==='https_required','HTTP rejected');}

    $headers=['x-legal-key-id'=>'unknown','x-legal-timestamp'=>(string)time(),'x-legal-nonce'=>$nonce,'x-legal-signature'=>$signed];
    try {$auth->verify('POST','/api/legal-bridge.php',$body,$headers,true);legalAssert(false,'key ID');}
    catch(LegalBridgeError $e) {legalAssert($e->getMessage()==='invalid_credentials','unknown credential rejected');}
    $headers['x-legal-key-id']='legal-hostgator';
    $headers['x-legal-timestamp']=(string)(time()-900);
    try {$auth->verify('POST','/api/legal-bridge.php',$body,$headers,true);legalAssert(false,'expired');}
    catch(LegalBridgeError $e) {legalAssert($e->getMessage()==='request_expired','expired timestamp rejected');}
    $headers['x-legal-timestamp']=(string)time();
    $headers['x-legal-signature']=str_repeat('0',64);
    try {$auth->verify('POST','/api/legal-bridge.php',$body,$headers,true);legalAssert(false,'invalid signature');}
    catch(LegalBridgeError $e) {legalAssert($e->getMessage()==='invalid_signature','invalid signature rejected');}
} finally {
    foreach($before as $n=>$v) { if($v===false)putenv($n);else putenv($n.'='.$v); }
}
echo "Pure HMAC tests passed; DB replay/ownership and end-to-end tests remain pending.\n";

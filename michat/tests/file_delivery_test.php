<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/Files/FileDeliveryService.php';
$n=0;
$check=static function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
foreach ([[null,10,null],['',0,null],['bytes=0-3',10,[0,3]],['bytes=4-',10,[4,9]],['bytes=-3',10,[7,9]],['bytes=0-999',10,[0,9]],['bytes=-99',10,[0,9]]] as [$input,$size,$expected]) {
    $check(FileDeliveryService::range($input,$size)===$expected,'range '.($input??'absent'));
}
foreach (['bytes=10-','bytes=3-1','bytes=-0','bytes=-','bytes=0-1,3-4','garbage','bytes=99999999999999999999999999-'] as $input) {
    try {FileDeliveryService::range($input,10);throw new RuntimeException('Accepted '.$input);}
    catch(FileDeliveryException $e){$check($e->getCode()===416,'reject '.$input);}
}
try {FileDeliveryService::range('bytes=0-',0);throw new RuntimeException('Accepted empty range');}
catch(FileDeliveryException $e){$check($e->getCode()===416,'empty file range');}
$d=FileDeliveryService::disposition("convenio ñ\"\r\n/x\\y.pdf",false);
$check(!str_contains($d,"\r")&&!str_contains($d,"\n")&&!str_contains($d,'/x')&&!str_contains($d,'\\y'),'filename header cannot inject controls, quotes or paths');
$check(str_starts_with($d,'attachment; filename="')&&str_contains($d,'filename*=UTF-8\'\'convenio%20%C3%B1%22'),'RFC5987 preserves Unicode safely');
$check(str_starts_with(FileDeliveryService::disposition('legal.pdf',true),'inline;'),'PDF inline disposition');
echo "OK $n file delivery checks\n";

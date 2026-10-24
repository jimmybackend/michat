<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP worker revocation: isolated DB missing\n";exit;}
require __DIR__.'/multimodal_processing_mysql_test.php';
$db->query("UPDATE AttachmentProcessingJobs SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE status IN ('pending','retry','running','waiting')");
foreach([9950,9960] as $id){
    $add($id,'Worker'.$id.'.xlsx','Data/worker/'.$id.'.xlsx',arcadecloudXlsxFixture());
    $copy=$attach->attach(1,1,$id);
    $queued[$id]=$service->enqueue(1,1,(int)$copy['id_'],'worker-revocation-fixture','text');
}
$gets=count(Config::$s3->gets);
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=9950");
$blocked=$service->workOne();
workCheck($blocked['id_']===$queued[9950]['id_']&&$blocked['status']==='blocked','Worker persists revoked source as blocked instead of crashing');
workCheck(count(Config::$s3->gets)===$gets,'Revoked job reads no file bytes');
$next=$service->workOne();
workCheck($next['id_']===$queued[9960]['id_']&&$next['phase']==='index','Unrelated authorized due job continues after a revoked job');
$next=$service->workOne();
workCheck($next['status']==='ready','Authorized content-only job completes on the following bounded worker tick');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=9950");
$resumed=$service->enqueue(1,1,(int)$queued[9950]['files3_id'],'worker-revocation-fixture','text');
workCheck($resumed['id_']===$queued[9950]['id_']&&$resumed['status']==='pending','Explicit request after access restoration resumes the same job');
echo "OK worker revocation recovery\n";

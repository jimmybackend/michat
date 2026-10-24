<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');if(!$host){echo "SKIP automatic processing schema: isolated DB missing\n";exit;}
if(!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback isolated DB only');
require_once __DIR__.'/../includes/Migrations/AttachmentProcessingSchema.php';
require_once __DIR__.'/../includes/Migrations/MigrationCatalog.php';
require_once __DIR__.'/../includes/Migrations/MigrationRunner.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306);$user=getenv('DOCUMENT_TEST_DB_USER')?:'root';$password=getenv('DOCUMENT_TEST_DB_PASSWORD')?:'';
$admin=new mysqli($host,$user,$password,'',$port);$name='michat_schema_auto_test_'.bin2hex(random_bytes(6));$created=false;$count=0;
$check=static function(bool $ok,string $message)use(&$count){$count++;if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";};
try{
    $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;
    $db=new mysqli($host,$user,$password,$name,$port);$db->set_charset('utf8mb4');$schema=new AttachmentProcessingSchema($db);
    $db->query('CREATE TABLE FileS3(id_ int PRIMARY KEY,Nombre text)');$db->query("INSERT INTO FileS3 VALUES(1,'private original, never modify')");
    // Exercise the actual bootstrap after a checkout update with isolated private configuration.
    $autoload=tempnam(sys_get_temp_dir(),'schema-loader-');$config=tempnam(sys_get_temp_dir(),'schema-config-');$dbFile=tempnam(sys_get_temp_dir(),'schema-db-');
    try{
        file_put_contents($autoload,'<?php');file_put_contents($config,'<?php');
        file_put_contents($dbFile,'<?php $db_connection=new mysqli('.implode(',',array_map(static fn($v)=>var_export($v,true),[$host,$user,$password,$name,$port])).');');
        $env=getenv();$env['MICHAT_VENDOR_AUTOLOAD']=$autoload;$env['MICHAT_CONFIG_FILE']=$config;$env['MICHAT_DB_BOOTSTRAP']=$dbFile;
        $code='require '.var_export(dirname(__DIR__).'/app_bootstrap.php',true).'; echo json_encode((new AttachmentProcessingSchema($db_connection))->ready());';
        $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($process))throw new RuntimeException('Bootstrap fixture process unavailable');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $check($exit===0&&trim($out)==='true','Actual application bootstrap creates the processing tables on first start after updating: '.$err);
    }finally{unlink($autoload);unlink($config);unlink($dbFile);}
    $first=$schema->ensure();$check($first['ready']&&$first['created']===[],'Feature use reuses the automatically installed processing tables');
    $check((int)$db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='SchemaMigrations'")->fetch_assoc()['n']===0,'Uninitialized historical migrations are not fabricated or replayed');
    $db->query("INSERT INTO AttachmentProcessingJobs(user_id_,session_id_,files3_id,revision_hash,source_etag) VALUES(1,2,3,REPEAT('a',64),'etag-v1')");
    $original=$db->query('SELECT * FROM AttachmentProcessingJobs')->fetch_all(MYSQLI_ASSOC);
    $check($schema->ensure(true)['created']===[]&&$db->query('SELECT * FROM AttachmentProcessingJobs')->fetch_all(MYSQLI_ASSOC)===$original,'Repeated updates verify schema without replacing queued work or timestamps');
    $db->query('DROP TABLE AttachmentProcessingCosts'); // Only inside this test-owned disposable DB.
    $check($schema->ensure()['created']===['AttachmentProcessingCosts']&&$db->query('SELECT * FROM AttachmentProcessingJobs')->fetch_all(MYSQLI_ASSOC)===$original,'Interrupted two-table installation resumes while retaining existing job data');
    // Two independent connections serialize on the same migration lock.
    $db->query('DROP TABLE AttachmentProcessingCosts');$lockRepository=new SchemaMigrationRepository($db,$name);$lockRepository->acquireLock(10);
    try{
        $env=getenv();foreach(['AUTO_TEST_HOST'=>$host,'AUTO_TEST_USER'=>$user,'AUTO_TEST_PASSWORD'=>$password,'AUTO_TEST_DB'=>$name,'AUTO_TEST_PORT'=>(string)$port] as $key=>$value)$env[$key]=$value;
        $code='require '.var_export(dirname(__DIR__).'/includes/Migrations/AttachmentProcessingSchema.php',true).'; $c=new mysqli(getenv("AUTO_TEST_HOST"),getenv("AUTO_TEST_USER"),getenv("AUTO_TEST_PASSWORD"),getenv("AUTO_TEST_DB"),(int)getenv("AUTO_TEST_PORT"));echo "START\n";flush();echo json_encode((new AttachmentProcessingSchema($c))->ensure());';
        $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);if(!is_resource($process))throw new RuntimeException('Concurrent schema fixture unavailable');
        fclose($pipes[0]);$started=fgets($pipes[1]);
        $parent=$schema->ensure(true,true);
    }finally{$lockRepository->releaseLock();}
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $check(trim((string)$started)==='START'&&$exit===0&&$parent['created']===['AttachmentProcessingCosts']&&(json_decode($out,true)['created']??null)===[],'Concurrent workers serialize creation and reuse the same schema without duplicate DDL: '.$err);
    $db->query("ALTER TABLE AttachmentProcessingJobs MODIFY phase varchar(16) NOT NULL DEFAULT 'extract'");$db->query('DROP TABLE AttachmentProcessingCosts');
    $blocked=false;try{$schema->ensure();}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'incompatible');}
    $check($blocked&&(int)$db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AttachmentProcessingCosts'")->fetch_assoc()['n']===0,'Incompatible existing table blocks all further DDL instead of being altered');
    $db->query("ALTER TABLE AttachmentProcessingJobs MODIFY phase varchar(32) NOT NULL DEFAULT 'extract'");$schema->ensure();
    $db->query('ALTER TABLE AttachmentProcessingJobs DROP INDEX uq_attachment_revision');$blocked=false;try{$schema->ensure(true);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'incompatible');}
    $check($blocked,'Schema verification includes unique indexes needed for idempotent work');
    $db->query('ALTER TABLE AttachmentProcessingJobs ADD UNIQUE KEY uq_attachment_revision(user_id_,session_id_,files3_id,revision_hash)');
    // Existing history is authoritative; match the real catalog, without applying unrelated SQL.
    $db->query("CREATE TABLE SchemaMigrations(migration_id varchar(128) PRIMARY KEY,filename varchar(255),checksum_sha256 char(64),applied_at timestamp(6) DEFAULT CURRENT_TIMESTAMP(6),execution_ms int,application_mode varchar(32),runner_version varchar(64))");
    $repo=new SchemaMigrationRepository($db,$name);$catalog=new MigrationCatalog();$definitions=$catalog->all();
    foreach(array_slice($definitions,0,-1) as $definition)$repo->insertHistory($definition,0,'applied','fixture-only');
    $runner=new MigrationRunner($catalog,$repo,new SqlMigrationExecutor($db));
    $check($runner->apply()===['fase14_attachment_processing']&&$runner->status()['global']==='APPLIED','CLI runner recognizes the exact runtime-created tables and records the real checksum');
    $check($runner->apply()===[],'Subsequent CLI migration is idempotent');
    $db->query("UPDATE SchemaMigrations SET checksum_sha256=REPEAT('0',64) WHERE migration_id='fase14_attachment_processing'");$blocked=false;try{$schema->ensure(true);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'DRIFT');}
    $check($blocked,'Recorded migration checksum drift prevents automatic repair');
    $last=end($definitions);$q=$db->prepare("UPDATE SchemaMigrations SET checksum_sha256=? WHERE migration_id='fase14_attachment_processing'");$q->bind_param('s',$last['checksum_sha256']);$q->execute();$q->close();
    $db->query('DROP TABLE AttachmentProcessingCosts');$blocked=false;try{$schema->ensure();}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'historial');}
    $check($blocked,'Missing table after recorded success is treated as integrity loss, not silently recreated');
    $check($db->query('SELECT * FROM AttachmentProcessingJobs')->fetch_all(MYSQLI_ASSOC)===$original&&$db->query('SELECT Nombre FROM FileS3')->fetch_assoc()['Nombre']==='private original, never modify','Queue data and unrelated shared file records remain byte-for-byte unchanged');
    echo "OK $count automatic schema checks\n";$db->close();
}finally{if($created)$admin->query("DROP DATABASE `$name`");$admin->close();}

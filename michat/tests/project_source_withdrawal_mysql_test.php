<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP project source withdrawal: isolated DB missing\n";exit;}
require __DIR__.'/project_attachment_engine_mysql_test.php';
require_once dirname(__DIR__).'/includes/ProjectSourceRemovalService.php';
foreach(['SourceChunks','ChunkEmbeddings','FileVersions'] as $name){
    preg_match('/CREATE TABLE IF NOT EXISTS `'.$name.'` \(.*?\) ENGINE=.*?;/s',$schema,$m);
    $db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]));
}
$db->query("INSERT INTO SourceChunks(id_,source_id_,project_id_,chunk_type,content) VALUES(100,920,10,'block','Cell evidence'),(101,921,20,'block','Other source')");
$db->query("INSERT INTO ChunkEmbeddings(chunk_id_,model_id,embedding,embedding_json) VALUES(100,'fixture',X'00000000','[0]'),(101,'fixture',X'00000000','[0]')");
$db->query("INSERT INTO EmbeddingJobs(target_type,target_id,model_id) VALUES('source_chunk',100,'withdraw-fixture'),('source_chunk',101,'withdraw-fixture')");
$db->query("INSERT INTO FileVersions(project_id_,original_filename,version,s3_path,status) VALUES(10,'Proyecto.xlsx','1','Data/version/sheet-v1.xlsx','committed')");
$removal=new ProjectSourceRemovalService($db);
$denied=false;try{$removal->withdraw(2,920);}catch(Throwable){$denied=true;}
workCheck($denied&&(int)$db->query('SELECT COUNT(*) n FROM ProjectSources WHERE id_=920')->fetch_assoc()['n']===1,'Other owner cannot withdraw a publication');
$before=Config::$s3->objects;$beforeFiles=$db->query('SELECT * FROM FileS3 ORDER BY id_')->fetch_all(MYSQLI_ASSOC);
$result=$removal->withdraw(1,920);
workCheck($result['original_preserved']&&Config::$s3->objects===$before&&$beforeFiles===$db->query('SELECT * FROM FileS3 ORDER BY id_')->fetch_all(MYSQLI_ASSOC),'Withdrawing source preserves every S3 object and FileS3 row');
workCheck((int)$db->query('SELECT COUNT(*) n FROM FileVersions WHERE project_id_=10')->fetch_assoc()['n']===1,'Published file version history is preserved');
workCheck((int)$db->query('SELECT COUNT(*) n FROM ProjectSources WHERE id_=920')->fetch_assoc()['n']===0&&(int)$db->query('SELECT COUNT(*) n FROM SourceChunks WHERE id_=100')->fetch_assoc()['n']===0,'Publication and derived chunks are removed');
workCheck((int)$db->query('SELECT COUNT(*) n FROM ChunkEmbeddings WHERE chunk_id_=100')->fetch_assoc()['n']===0&&(int)$db->query("SELECT COUNT(*) n FROM EmbeddingJobs WHERE target_type='source_chunk' AND target_id=100")->fetch_assoc()['n']===0,'Derived jobs and embeddings are removed even without cascading FKs');
workCheck((int)$db->query('SELECT COUNT(*) n FROM SourceChunks WHERE id_=101')->fetch_assoc()['n']===1&&(int)$db->query('SELECT COUNT(*) n FROM ChunkEmbeddings WHERE chunk_id_=101')->fetch_assoc()['n']===1,'Unrelated source data remains untouched');
workCheck((int)$db->query('SELECT index_gen FROM Projects WHERE id_=10')->fetch_assoc()['index_gen']===1,'Withdrawal invalidates project index caches');
echo "OK non-destructive project source withdrawal\n";

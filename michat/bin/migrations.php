<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

require_once dirname(__DIR__).'/includes/Config/EnvironmentLoader.php';
require_once dirname(__DIR__).'/includes/Migrations/MigrationCatalog.php';
require_once dirname(__DIR__).'/includes/Migrations/SchemaMigrationRepository.php';
require_once dirname(__DIR__).'/includes/Migrations/SqlMigrationExecutor.php';
require_once dirname(__DIR__).'/includes/Migrations/MigrationRunner.php';

const MIGRATION_COMMANDS=['status','apply','adopt-existing','baseline'];

try {
    $command=$argv[1]??'';$profile=null;
    if(!in_array($command,MIGRATION_COMMANDS,true))throw new InvalidArgumentException('Usage: migrations.php status|apply|adopt-existing --profile=post-fase10d|baseline --profile=current-dump');
    $expectedArguments=$command==='status'||$command==='apply'?2:3;
    if(count($argv)!==$expectedArguments)throw new InvalidArgumentException('Invalid command arguments');
    if($expectedArguments===3){if(!str_starts_with($argv[2],'--profile='))throw new InvalidArgumentException('A closed profile is required');$profile=substr($argv[2],10);}

    $root=dirname(__DIR__,2);(new EnvironmentLoader())->loadIfPresent($root.'/.env');
    foreach(['DB_HOST','DB_USER','DB_PASSWORD','DB_NAME'] as $key)if(getenv($key)===false||trim((string)getenv($key))==='')throw new RuntimeException('Missing required database configuration: '.$key);
    $port=filter_var((string)(getenv('DB_PORT')?:'3306'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
    if($port===false)throw new RuntimeException('Invalid DB_PORT');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli((string)getenv('DB_HOST'),(string)getenv('DB_USER'),(string)getenv('DB_PASSWORD'),(string)getenv('DB_NAME'),(int)$port);
    $db->set_charset('utf8mb4');
    $repository=new SchemaMigrationRepository($db,(string)getenv('DB_NAME'));
    $runner=new MigrationRunner(new MigrationCatalog(),$repository,new SqlMigrationExecutor($db));
    $exit=0;
    if($command==='status'){$result=$runner->status();echo $result['global']."\n";foreach($result['rows'] as $row)echo ($row['state']??'ERROR')."\t".($row['migration_id']??'')."\t".($row['filename']??'')."\n";$exit=$result['ok']?0:2;}
    elseif($command==='apply'){foreach($runner->apply() as $id)echo "APPLIED\t{$id}\n";}
    elseif($command==='adopt-existing'){$runner->adoptExisting((string)$profile);echo "ADOPTED\t{$profile}\n";}
    else {$runner->baseline((string)$profile);echo "BASELINED\t{$profile}\n";}
    $db->close();exit($exit);
} catch(Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n");exit(1);
}

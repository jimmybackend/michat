<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ').$name."\n";
    $condition ? $passed++ : $failed++;
};

$root = dirname(__DIR__);
$endpointSource = $root.'/trace_metrics_api.php';
$runtime = sys_get_temp_dir().'/michat-trace-contract-'.bin2hex(random_bytes(6));
$log = $runtime.'/server.log';
$process = null;

/** @return array{status:int,headers:array<string,string>,body:string,json:mixed} */
$request = static function (string $base, string $method, array $query = []): array {
    $url = $base.'/trace_metrics_api.php';
    if ($query !== []) $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create(['http'=>[
        'method'=>$method,
        'ignore_errors'=>true,
        'timeout'=>5,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP contract request failed: '.$url);
    $rawHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', (string)($rawHeaders[0] ?? ''), $statusMatch);
    $headers = [];
    foreach (array_slice($rawHeaders, 1) as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) continue;
        $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
    }
    return [
        'status'=>(int)($statusMatch[1] ?? 0),
        'headers'=>$headers,
        'body'=>$body,
        'json'=>json_decode($body, true),
    ];
};

try {
    mkdir($runtime.'/includes/Chat', 0777, true);
    mkdir($runtime.'/includes/Trace', 0777, true);
    copy($endpointSource, $runtime.'/trace_metrics_api.php');

    file_put_contents($runtime.'/includes/Chat/ChatEndpointBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);
final class TraceContractDb { public array $charsets=[]; public function set_charset(string $charset): bool { $this->charsets[]=$charset; return true; } }
final class ChatEndpointBootstrap { public static function mysqli(string $endpointDir): TraceContractDb { return new TraceContractDb(); } }
PHP);
    file_put_contents($runtime.'/includes/Chat/ChatIdentity.php', <<<'PHP'
<?php
declare(strict_types=1);
final class ChatIdentity {
    public static function resolveUserId(object $db): int { return ($_GET['scenario']??'')==='unauthenticated' ? 0 : 7; }
    public static function isAdminLike(): bool { return ($_GET['scenario']??'')==='admin'; }
}
PHP);
    file_put_contents($runtime.'/includes/Trace/TraceMetricsRepository.php', <<<'PHP'
<?php
declare(strict_types=1);
final class TraceMetricsRepository {
    private static int $calls=0;
    public function __construct(private object $db,private int $viewer,private bool $admin,private int $target) {}
    public function summary(int $sessionId,?int $projectId=null,?string $month=null): array {
        self::$calls++;
        $scenario=(string)($_GET['scenario']??'success');
        if($scenario==='invalid_argument')throw new InvalidArgumentException('PUBLIC INVALID MONTH');
        if($scenario==='public_permission')throw new RuntimeException('No tienes permisos para esta métrica.');
        if($scenario==='public_ownership')throw new RuntimeException('La sesión no pertenece al usuario seleccionado.');
        if($scenario==='internal_runtime')throw new RuntimeException('SQL INTERNAL SECRET COLUMN foo_bar /srv/private/schema.php:42 Stack trace');
        if($scenario==='throwable')throw new Error('DRIVER INTERNAL DETAIL baz_qux');
        return ['repository_version'=>'double','viewer'=>$this->viewer,'admin'=>$this->admin,'target'=>$this->target,'session'=>$sessionId,'project'=>$projectId,'month'=>$month,'calls'=>self::$calls];
    }
}
PHP);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Loopback unavailable: '.$error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || !str_contains($address, ':')) throw new RuntimeException('Loopback port unavailable.');
    $port = (int)substr(strrchr($address, ':'), 1);
    $php = PHP_BINARY;
    $command = escapeshellarg($php).' -d '.escapeshellarg('error_log='.$log).' -S 127.0.0.1:'.$port.' -t '.escapeshellarg($runtime);
    $pipes = [];
    $process = proc_open($command, [['pipe','r'],['file',$runtime.'/stdout.log','a'],['file',$runtime.'/stderr.log','a']], $pipes, $runtime);
    if (!is_resource($process)) throw new RuntimeException('Unable to start PHP loopback server.');
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:'.$port;
    $ready = false;
    for ($attempt=0; $attempt<50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeErrno, $probeError, 0.1);
        if (is_resource($probe)) { fclose($probe); $ready=true; break; }
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('PHP loopback server did not become ready.');

    $method = $request($base, 'POST');
    $check($method['status']===405, 'non-GET method returns 405');
    $check($method['json']===['ok'=>false,'api_version'=>'7.7','error'=>'La API de métricas es read-only. Usa GET.'], '405 error envelope is exact');
    $check(str_starts_with(strtolower($method['headers']['content-type']??''), 'application/json; charset=utf-8'), 'response content type is JSON UTF-8');
    $cacheControl = strtolower($method['headers']['cache-control'] ?? '');
    $check(str_contains($cacheControl, 'no-store')&&str_contains($cacheControl, 'no-cache')&&str_contains($cacheControl, 'must-revalidate'), 'cache-control forbids storage and revalidation caching');
    $check(($method['headers']['pragma']??'')==='no-cache', 'pragma no-cache is present');

    $unauthenticated = $request($base, 'GET', ['scenario'=>'unauthenticated','session_id'=>3]);
    $check($unauthenticated['status']===401, 'missing server-side identity returns 401');
    $check($unauthenticated['json']===['ok'=>false,'api_version'=>'7.7','error'=>'Sesión de usuario no válida'], '401 error envelope is exact');

    $targetInvalid = $request($base, 'GET', ['user_id'=>0,'session_id'=>3]);
    $check($targetInvalid['status']===400&&$targetInvalid['json']['error']==='user_id inválido', 'non-positive target user returns 400');
    $sessionInvalid = $request($base, 'GET');
    $check($sessionInvalid['status']===400&&$sessionInvalid['json']['error']==='session_id es obligatorio', 'missing session_id returns 400');
    $crossUser = $request($base, 'GET', ['user_id'=>8,'session_id'=>3]);
    $check($crossUser['status']===403&&$crossUser['json']['error']==='No tienes permisos para consultar otro usuario', 'cross-user non-admin returns 403 before repository access');

    $success = $request($base, 'GET', ['session_id'=>11,'project_id'=>22,'month'=>' 2026-08 ','pretty'=>1]);
    $check($success['status']===200, 'GET success returns 200');
    $check(array_keys($success['json'])===['ok','data']&&$success['json']['ok']===true, 'success envelope is exactly ok plus data without api_version');
    $check($success['json']['data']===['repository_version'=>'double','viewer'=>7,'admin'=>false,'target'=>7,'session'=>11,'project'=>22,'month'=>'2026-08','calls'=>1], 'repository is constructed and summary called once with exact normalized arguments');
    $check(str_contains($success['body'], "\n"), 'pretty=1 enables pretty-printed JSON');

    $defaults = $request($base, 'GET', ['user_id'=>'not-numeric','session_id'=>9,'project_id'=>'not-numeric','month'=>'  ']);
    $check($defaults['json']['data']['target']===7, 'non-numeric user_id defaults to viewer identity');
    $check($defaults['json']['data']['project']===null, 'non-numeric project_id defaults to null');
    $check($defaults['json']['data']['month']===null, 'blank month defaults to null');

    $admin = $request($base, 'GET', ['scenario'=>'admin','user_id'=>8,'session_id'=>4]);
    $check($admin['status']===200&&$admin['json']['data']['viewer']===7&&$admin['json']['data']['target']===8&&$admin['json']['data']['admin']===true, 'admin-like viewer may select another target without changing viewer identity');

    $invalidArgument = $request($base, 'GET', ['scenario'=>'invalid_argument','session_id'=>3]);
    $check($invalidArgument['status']===400&&$invalidArgument['json']['error']==='PUBLIC INVALID MONTH', 'InvalidArgumentException is currently public with 400');
    $permission = $request($base, 'GET', ['scenario'=>'public_permission','session_id'=>3]);
    $check($permission['status']===403&&$permission['json']['error']==='No tienes permisos para esta métrica.', 'permission RuntimeException is currently public with 403');
    $ownership = $request($base, 'GET', ['scenario'=>'public_ownership','session_id'=>3]);
    $check($ownership['status']===403&&$ownership['json']['error']==='La sesión no pertenece al usuario seleccionado.', 'ownership RuntimeException is currently public with 403');

    $internal = $request($base, 'GET', ['scenario'=>'internal_runtime','session_id'=>3]);
    $check($internal['status']===500&&$internal['json']['error']==='Error interno construyendo métricas.', 'internal RuntimeException returns generic 500 JSON');
    $check(!str_contains($internal['body'], 'SQL INTERNAL SECRET COLUMN foo_bar'), 'internal SQL-like marker is absent from JSON');
    $check(!str_contains($internal['body'], 'foo_bar'), 'internal column name is absent from JSON');
    $check(!str_contains($internal['body'], '/srv/private/schema.php'), 'internal filesystem path is absent from JSON');
    $check(!str_contains($internal['body'], 'Stack trace'), 'internal stack detail is absent from JSON');
    $logBeforeThrowable = is_file($log) ? (string)file_get_contents($log) : '';
    $check(str_contains($logBeforeThrowable, 'TRACE_METRICS_7_7: SQL INTERNAL SECRET COLUMN foo_bar'), 'internal RuntimeException detail is logged server-side');

    $throwable = $request($base, 'GET', ['scenario'=>'throwable','session_id'=>3]);
    $check($throwable['status']===500&&$throwable['json']['error']==='Error interno construyendo métricas.', 'non-Runtime Throwable returns generic 500 JSON');
    usleep(100000);
    $serverLog = is_file($log) ? (string)file_get_contents($log) : '';
    $check(str_contains($serverLog, 'TRACE_METRICS_7_7: DRIVER INTERNAL DETAIL baz_qux'), 'non-Runtime Throwable detail is logged server-side');
    $check(!str_contains($throwable['body'], 'DRIVER INTERNAL DETAIL baz_qux'), 'non-Runtime Throwable detail is absent from JSON');

    $check(hash_file('sha256', $endpointSource)===hash_file('sha256', $runtime.'/trace_metrics_api.php'), 'harness executes an unchanged copy of the real endpoint');
} catch (Throwable $error) {
    $check(false, 'contract harness completed: '.$error->getMessage());
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_dir($runtime)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($runtime);
    }
}

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

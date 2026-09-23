<?php
declare(strict_types=1);

const PLN_MAX_BODY = 8192;
const PLN_RETENTION = 90 * 86400;
const PLN_RATE_RETENTION = 48 * 3600;
const PLN_BUCKET_LIMIT = 128;

final class PLNRequestError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

/** JSON syntax is checked first; this pass rejects duplicate object keys, including escaped keys. */
function pln_json(string $body): stdClass
{
    try { $value = json_decode($body, false, 12, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new PLNRequestError(400, 'invalid_json'); }
    if (!$value instanceof stdClass) { throw new PLNRequestError(400, 'invalid_notice'); }
    $stack = [];
    for ($i=0, $length=strlen($body); $i<$length; $i++) {
        $char=$body[$i];
        if ($char==='{' || $char==='[') { $stack[]=['object'=>$char==='{','key'=>true,'seen'=>[]]; }
        elseif ($char==='}' || $char===']') { array_pop($stack); }
        elseif ($char===',' && $stack!==[]) { $stack[count($stack)-1]['key']=true; }
        elseif ($char==='"') {
            $start=$i;
            while (++$i<$length) { if ($body[$i]==='\\') { $i++; } elseif ($body[$i]==='"') { break; } }
            $top=count($stack)-1;
            if ($top>=0 && $stack[$top]['object'] && $stack[$top]['key']) {
                $key=json_decode(substr($body,$start,$i-$start+1),true,2,JSON_THROW_ON_ERROR);
                if (isset($stack[$top]['seen'][$key])) { throw new PLNRequestError(400,'duplicate_key'); }
                $stack[$top]['seen'][$key]=true; $stack[$top]['key']=false;
            }
        }
    }
    return $value;
}

function pln_text(mixed $value, int $limit, bool $required=false): string
{
    if (!is_string($value) || !preg_match('/^[^\x00-\x1f\x7f]{0,'.$limit.'}$/uD',$value) || ($required && trim($value)==='')) {
        throw new PLNRequestError(400,'invalid_field');
    }
    return $value;
}

function pln_payload(string $body): array
{
    if (strlen($body)>PLN_MAX_BODY) { throw new PLNRequestError(413,'too_large'); }
    $object=pln_json($body); $data=(array)$object;
    $required=['schema','installation_id','event','version','channel','php_version','database_engine','database_version','os_family','mode','at'];
    if (array_diff($required,array_keys($data))!==[] || array_diff(array_keys($data),array_merge($required,['registration']))!==[] || $data['schema']!==1) {
        throw new PLNRequestError(400,'invalid_fields');
    }
    foreach (array_diff($required,['schema']) as $field) { pln_text($data[$field],80,true); }
    if (!preg_match('/^[a-f0-9]{32}$/D',$data['installation_id'])
        || !in_array($data['event'],['install','update-check','preferences'],true)
        || !preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/D',$data['version'])
        || $data['channel']!==(str_contains($data['version'],'-')?'preview':'stable')
        || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}$/D',$data['php_version'])
        || !in_array($data['database_engine'],['mysql','mariadb'],true)
        || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}$/D',$data['database_version'])
        || !in_array($data['os_family'],['Linux','Windows','Darwin','BSD','Solaris','Unknown'],true)
        || !in_array($data['mode'],['managed','container','composer','panel'],true)
        || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$data['at'])) {
        throw new PLNRequestError(400,'invalid_field');
    }
    $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$data['at']); $errors=DateTimeImmutable::getLastErrors();
    if ($time===false || (is_array($errors) && ($errors['warning_count']>0 || $errors['error_count']>0))) { throw new PLNRequestError(400,'invalid_time'); }
    if (array_key_exists('registration',$data)) {
        if (!$data['registration'] instanceof stdClass) { throw new PLNRequestError(400,'invalid_registration'); }
        $registration=(array)$data['registration'];
        if (array_diff(array_keys($registration),['name','email','site','company'])!==[] || !isset($registration['name'],$registration['email'])) { throw new PLNRequestError(400,'invalid_registration'); }
        $clean=[];
        foreach (['name'=>120,'email'=>254,'site'=>480,'company'=>160] as $name=>$limit) { $clean[$name]=trim(pln_text($registration[$name]??'',$limit,in_array($name,['name','email'],true))); }
        if (!filter_var($clean['email'],FILTER_VALIDATE_EMAIL)) { throw new PLNRequestError(400,'invalid_registration'); }
        if ($clean['site']!=='') {
            $site=parse_url($clean['site']);
            if (!is_array($site) || !in_array($site['scheme']??'', ['http','https'],true) || empty($site['host']) || isset($site['user']) || isset($site['pass']) || isset($site['query']) || isset($site['fragment'])) { throw new PLNRequestError(400,'invalid_registration'); }
        }
        $data['registration']=$clean;
    }
    return $data;
}

/** Every parent is checked; storage is private, local and never inside the document root. */
function pln_root(): string
{
    $path=rtrim((string)(getenv('PL_NOTICE_DIRECTORY')?:'/var/lib/phpledger-notices'),'/');
    if ($path==='' || $path[0]!=='/' || str_contains($path,"\0") || preg_match('~/(?:\.|\.\.)(?:/|$)~',$path)) { throw new RuntimeException('storage'); }
    $current='';
    foreach (explode('/',ltrim($path,'/')) as $part) {
        $current.='/'.$part; clearstatcache(true,$current);
        if (is_link($current) || !is_dir($current)) { throw new RuntimeException('storage'); }
    }
    $real=realpath($path); $public=realpath(dirname(__DIR__).'/public'); $stat=lstat($path);
    if ($real!==$path || $stat===false || ($stat['mode']&0077)!==0 || ($public!==false && ($path===$public || str_starts_with($path,$public.'/')))) { throw new RuntimeException('storage'); }
    if (function_exists('posix_geteuid') && $stat['uid']!==posix_geteuid() && posix_geteuid()!==0) { throw new RuntimeException('storage'); }
    return $path;
}

function pln_directory(string $path): void
{
    clearstatcache(true,$path);
    if (is_link($path)) { throw new RuntimeException('storage'); }
    if (!is_dir($path) && !mkdir($path,0700) && !is_dir($path)) { throw new RuntimeException('storage'); }
    $stat=lstat($path);
    if ($stat===false || ($stat['mode']&0170000)!==0040000 || ($stat['mode']&0077)!==0) { throw new RuntimeException('storage'); }
}

function pln_file(string $path): ?array
{
    clearstatcache(true,$path); $stat=@lstat($path);
    if ($stat===false) { return null; }
    if (($stat['mode']&0170000)!==0100000 || $stat['nlink']!==1 || ($stat['mode']&0077)!==0) { throw new RuntimeException('storage'); }
    return $stat;
}

function pln_read(string $path): ?array
{
    $stat=pln_file($path); if ($stat===null) { return null; }
    if ($stat['size']>16384) { throw new RuntimeException('storage'); }
    $handle=fopen($path,'rb'); if ($handle===false) { throw new RuntimeException('storage'); }
    try {
        $opened=fstat($handle);
        if ($opened===false || $opened['ino']!==$stat['ino'] || $opened['dev']!==$stat['dev']) { throw new RuntimeException('storage'); }
        $bytes=stream_get_contents($handle,16385); $data=json_decode($bytes===false?'':$bytes,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('storage'); } return $data;
    } finally { fclose($handle); }
}

function pln_write(string $path, array $value): void
{
    pln_file($path); $temporary=dirname($path).'/.tmp-'.bin2hex(random_bytes(12));
    $handle=fopen($temporary,'x+b'); if ($handle===false) { throw new RuntimeException('storage'); }
    try {
        chmod($temporary,0600); $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        for ($offset=0,$length=strlen($bytes);$offset<$length;) { $written=fwrite($handle,substr($bytes,$offset)); if ($written===false || $written===0) { throw new RuntimeException('storage'); } $offset+=$written; }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) { throw new RuntimeException('storage'); }
        pln_file($path);
        if (!rename($temporary,$path)) { throw new RuntimeException('storage'); }
    } finally { fclose($handle); if (is_file($temporary) && !is_link($temporary)) { unlink($temporary); } }
}

/** All mutations and owner summaries share this lock; no read/modify/write race. */
function pln_locked(callable $action): mixed
{
    umask(0077); $root=pln_root(); $path=$root.'/store.lock'; $before=pln_file($path);
    $handle=$before===null?@fopen($path,'x+b'):fopen($path,'r+b');
    if ($handle===false) { $before=pln_file($path); $handle=$before===null?false:fopen($path,'r+b'); }
    if ($handle===false) { throw new RuntimeException('storage'); }
    try {
        $after=pln_file($path); $opened=fstat($handle);
        if ($after===null || $opened===false || $opened['ino']!==$after['ino'] || $opened['dev']!==$after['dev']) { throw new RuntimeException('storage'); }
        $deadline=microtime(true)+2.0;
        while (!flock($handle,LOCK_EX|LOCK_NB)) { if (microtime(true)>=$deadline) { throw new PLNRequestError(429,'busy'); } usleep(10000); }
        pln_directory($root.'/records'); pln_directory($root.'/rates');
        return $action($root);
    } finally { flock($handle,LOCK_UN); fclose($handle); }
}

/** Each bucket has a hard entry cap, so request cleanup never walks an unbounded directory. */
function pln_entries(string $directory): array
{
    pln_directory($directory); $entries=[]; $scanned=0;
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) { continue; }
        if (++$scanned>PLN_BUCKET_LIMIT*2) { throw new RuntimeException('storage'); }
        $name=$entry->getFilename(); $path=$directory.'/'.$name; $stat=pln_file($path);
        if (preg_match('/^\.tmp-[a-f0-9]{24}$/D',$name)) {
            if ($stat!==null && $stat['mtime']<time()-3600) { unlink($path); } continue;
        }
        if (!preg_match('/^[a-f0-9]{64}\.json$/D',$name) || count($entries)>=PLN_BUCKET_LIMIT) { throw new RuntimeException('storage'); }
        $entries[]=$path;
    }
    return $entries;
}

function pln_prune_bucket(string $root, int $bucket, int $now): array
{
    $deleted=['records'=>0,'rates'=>0]; $part=sprintf('%02x',$bucket);
    foreach (['records'=>PLN_RETENTION,'rates'=>PLN_RATE_RETENTION] as $type=>$ttl) {
        foreach (pln_entries($root.'/'.$type.'/'.$part) as $path) {
            $entry=pln_read($path);
            if ($entry===null || !isset($entry['received_at']) || !is_int($entry['received_at'])) { throw new RuntimeException('storage'); }
            if ($entry['received_at']<=$now-$ttl) { unlink($path); $deleted[$type]++; }
        }
    }
    return $deleted;
}

function pln_accept(array $payload, string $ip, int $now): void
{
    $binary=inet_pton($ip); if ($binary===false) { throw new PLNRequestError(400,'invalid_client'); }
    pln_locked(static function (string $root) use ($payload,$binary,$now): void {
        $maintenance=pln_read($root.'/maintenance.json')??['bucket'=>0];
        if (!is_int($maintenance['bucket']??null) || $maintenance['bucket']<0 || $maintenance['bucket']>255) { throw new RuntimeException('storage'); }
        $bucket=$maintenance['bucket']; pln_prune_bucket($root,$bucket,$now);
        pln_write($root.'/maintenance.json',['bucket'=>($bucket+1)%256]);
        $secret=pln_read($root.'/rate-key.json');
        if ($secret===null) { $secret=['key'=>bin2hex(random_bytes(32))]; pln_write($root.'/rate-key.json',$secret); }
        if (!is_string($secret['key']??null) || !preg_match('/^[a-f0-9]{64}$/D',$secret['key'])) { throw new RuntimeException('storage'); }
        $key=hash_hmac('sha256',gmdate('Y-m-d',$now)."\0".$binary,$secret['key']);
        $rateDirectory=$root.'/rates/'.substr($key,0,2); $ratePath=$rateDirectory.'/'.$key.'.json';
        $rates=pln_entries($rateDirectory); $rate=pln_read($ratePath);
        if ($rate===null && count($rates)>=PLN_BUCKET_LIMIT) { throw new PLNRequestError(429,'rate_limited'); }
        $minute=intdiv($now,60); $hour=intdiv($now,3600);
        $minuteCount=($rate['minute']??null)===$minute?(int)$rate['minute_count']:0;
        $hourCount=($rate['hour']??null)===$hour?(int)$rate['hour_count']:0;
        if ($minuteCount>=60 || $hourCount>=600) { throw new PLNRequestError(429,'rate_limited'); }
        pln_write($ratePath,['received_at'=>$now,'minute'=>$minute,'minute_count'=>$minuteCount+1,'hour'=>$hour,'hour_count'=>$hourCount+1]);
        $id=hash('sha256',$payload['installation_id']); $directory=$root.'/records/'.substr($id,0,2); $path=$directory.'/'.$id.'.json';
        $entries=pln_entries($directory);
        if (pln_file($path)===null && count($entries)>=PLN_BUCKET_LIMIT) { throw new PLNRequestError(429,'capacity_limited'); }
        // Replace the complete record: omission of registration removes earlier named details.
        pln_write($path,['received_at'=>$now,'notice'=>$payload]);
    });
}

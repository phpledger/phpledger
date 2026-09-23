<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$service=getenv('PL_NOTICE_SERVICE_ROOT')?:dirname(__DIR__).'/www/installation-service';
require $service.'/includes/receiver.php';
try {
    $argument=$argv[1]??'--summary';
    if (!in_array($argument,['--summary','--prune'],true) && !preg_match('/^--delete=[a-f0-9]{32}$/D',$argument)) { throw new InvalidArgumentException('Use --summary, --prune or --delete=<installation ID>.'); }
    $result=pln_locked(static function (string $root) use ($argument): array {
        if (str_starts_with($argument,'--delete=')) {
            $id=hash('sha256',substr($argument,9)); $path=$root.'/records/'.substr($id,0,2).'/'.$id.'.json';
            $directory=dirname($path); pln_directory($directory); $found=pln_file($path)!==null;
            if ($found && !unlink($path)) { throw new RuntimeException('storage'); }
            return ['deleted'=>$found];
        }
        $removed=['records'=>0,'rates'=>0]; $now=time();
        for ($bucket=0;$bucket<256;$bucket++) { $counts=pln_prune_bucket($root,$bucket,$now); foreach ($counts as $type=>$count) { $removed[$type]+=$count; } }
        if ($argument==='--prune') { return ['expired_removed'=>$removed]; }
        $summary=['retained_installations'=>0,'registered_installations'=>0,'seen_7_days'=>0,'seen_30_days'=>0,'by_version'=>[],'by_channel'=>[],'by_database'=>[],'by_os'=>[],'by_mode'=>[]];
        for ($bucket=0;$bucket<256;$bucket++) {
            foreach (pln_entries($root.'/records/'.sprintf('%02x',$bucket)) as $path) {
                $record=pln_read($path); if ($record===null || !is_array($record['notice']??null)) { throw new RuntimeException('storage'); }
                $notice=$record['notice']; $summary['retained_installations']++;
                if (isset($notice['registration'])) { $summary['registered_installations']++; }
                if ($record['received_at']>=$now-7*86400) { $summary['seen_7_days']++; }
                if ($record['received_at']>=$now-30*86400) { $summary['seen_30_days']++; }
                foreach (['version'=>'by_version','channel'=>'by_channel','database_engine'=>'by_database','os_family'=>'by_os','mode'=>'by_mode'] as $field=>$group) { $key=(string)$notice[$field]; $summary[$group][$key]=($summary[$group][$key]??0)+1; }
            }
        }
        foreach (['by_version','by_channel','by_database','by_os','by_mode'] as $group) { ksort($summary[$group],SORT_STRING); }
        return $summary;
    });
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) { fwrite(STDERR,"Notice storage operation refused. Check the private directory and command.\n"); exit(1); }

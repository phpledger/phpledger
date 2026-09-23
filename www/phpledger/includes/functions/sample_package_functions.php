<?php
declare(strict_types=1);

const PL_SAMPLE_PACKAGE_CONTRACT = 1;
const PL_SAMPLE_DIRECTORY_URL = 'https://phpledger.com/directory/index.json';

function pl_sample_package_directory(bool $create=false): string
{
    require_once __DIR__.'/installation_state_functions.php';
    $configured=defined('PL_SAMPLE_PACKAGE_DIRECTORY')?(string)constant('PL_SAMPLE_PACKAGE_DIRECTORY'):(string)(getenv('PL_SAMPLE_PACKAGE_DIRECTORY')?:dirname(__DIR__,2).'/storage/packages/samples');
    $path=pl_install_private_path($configured);
    if ($create) {
        if (pl_sample_packages_readonly()) { throw new DomainException('Sample packages on this shared host are managed by its operator and are read-only.'); }
        if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) { throw new DomainException('Create a private writable sample-package directory.'); }
    }
    return $path;
}
function pl_sample_packages_readonly(): bool { return getenv('PL_SAMPLE_PACKAGES_READONLY')==='1'; }
function pl_sample_repository_fallback(): bool { return in_array(getenv('PL_ENV'),['local','test','demo'],true); }
function pl_sample_repository_directory(string $kind): string
{
    if (!pl_sample_repository_fallback() || !in_array($kind,['demo-packs','sample-structures'],true)) { throw new DomainException('Repository sample fallback is unavailable here.'); }
    return implode('/',[PL_ROOT,'resources',$kind]);
}
function pl_sample_publisher_key(): string
{
    require_once __DIR__.'/installation_state_functions.php';
    $path=(string)(getenv('PL_UPDATE_PUBLIC_KEY')?:pl_install_directory().'/publisher.pem');
    if (!is_file($path) || is_link($path)) { throw new DomainException('Pin the publisher public key before installing verified sample packages.'); }
    return (string)file_get_contents($path);
}

/** Strict data-only contract. No entry point, SQL, hooks, migrations, or arbitrary payload files. */
function pl_sample_package_manifest(array $m,string $slug): array
{
    pl_plugin_slug($slug);
    $keys=['type','slug','version','contract','name','description','author','licence','homepage','requires','sample','files'];
    if (array_diff(array_keys($m),$keys)!==[] || ($m['type']??null)!=='sample' || ($m['slug']??null)!==$slug || ($m['contract']??null)!==1
        || !is_string($m['version']??null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$m['version']) || ($m['licence']??null)!=='CC0-1.0') { throw new DomainException('Unsupported data-only sample package contract or licence.'); }
    foreach(['name'=>160,'description'=>500,'author'=>160,'homepage'=>300] as $key=>$limit){$m[$key]=pl_ledger_text($m[$key]??null,ucfirst($key),$limit);}
    if (!str_starts_with($m['homepage'],'https://')) { throw new DomainException('A sample homepage uses HTTPS.'); }
    if (!is_array($m['requires']??null) || ($m['requires']['core']??null)!=='1.0.0') { throw new DomainException('A sample must declare its exact core module contract.'); }
    foreach($m['requires'] as $name=>$version){if(!is_string($name)||!is_string($version)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version)){throw new DomainException('Sample dependencies use exact versions.');}pl_plugin_slug($name);}
    if (!is_array($m['files']??null) || array_diff(array_keys($m['files']),['pack.json','structure.json'])!==[] || !isset($m['files']['pack.json'],$m['files']['structure.json'])) { throw new DomainException('A sample contains only pack.json and structure.json.'); }
    foreach($m['files'] as $hash){if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){throw new DomainException('Invalid sample file digest.');}}
    $entry=$m['sample']??null;
    if(!is_array($entry)||!is_string($entry['id']??null)||$slug!=='sample-'.$entry['id']||($entry['version']??null)!==$m['version']||($entry['sha256']??null)!==$m['files']['pack.json']){throw new DomainException('The sample identity does not match its package.');}
    pl_plugin_slug($entry['id']);
    foreach(['name','business','capability_note'] as $key){if(!is_string($entry[$key]??null)||$entry[$key]===''){throw new DomainException('The sample catalogue metadata is incomplete.');}}
    if(!is_int($entry['source_count']??null)||$entry['source_count']<85||$entry['source_count']>5000||!in_array($entry['status']??null,['released_demo_only','preview_only'],true)){throw new DomainException('Unsupported sample catalogue contract.');}
    ksort($m['files']);ksort($m['requires']);return $m;
}
function pl_sample_package_content(array $manifest,array $bytes): array
{
    foreach($manifest['files'] as $name=>$hash){if(!isset($bytes[$name])||strlen($bytes[$name])>5000000||!hash_equals($hash,hash('sha256',$bytes[$name]))){throw new DomainException('Sample content does not match its pinned digest.');}}
    $pack=json_decode($bytes['pack.json'],true,64,JSON_THROW_ON_ERROR);$structure=json_decode($bytes['structure.json'],true,64,JSON_THROW_ON_ERROR);
    if(!is_array($pack)||!is_array($structure)||($pack['id']??null)!==$manifest['sample']['id']||($pack['version']??null)!==$manifest['version']||($pack['demo_only']??null)!==true
        || !is_array($pack['events']??null)||!is_array($pack['drafts']??null)||count($pack['events'])+count($pack['drafts'])!==$manifest['sample']['source_count']
        ||($structure['source_digest']??null)!==$manifest['files']['pack.json']){throw new DomainException('The sample data and structure do not describe the same source.');}
    if (($pack['start_date']??null)!=='2024-01-01' || ($pack['source_count']??null)!==$manifest['sample']['source_count'] || !in_array($pack['status']??null,['released_demo_only','preview_only'],true)
        || !is_array($pack['checkpoints']??null) || count($pack['checkpoints'])!==36 || !is_array($pack['company_profile']??null) || !is_array($pack['partners']??null) || !is_array($pack['anticipated_1_3']??null)) { throw new DomainException('Unsupported sample history contract.'); }
    pl_sample_structure_validate($structure,$pack['id'],$pack['version'],'package');
    return ['pack'=>$pack,'structure'=>$structure];
}
function pl_sample_package_requirements(array $manifest): void
{
    pl_plugin_require_requirements($manifest);
    foreach($manifest['requires'] as $slug=>$version){$record=pl_plugin_record($slug);if($record!==null&&$record['type']==='plugin'&&$record['trust']==='unverified'&&$record['status']!=='active'){throw new DomainException('Review and activate the unverified dependency in Packages first. A sample never installs code.');}}
}
function pl_sample_package_envelope(string $json,string $key): array
{
    require_once __DIR__.'/update_functions.php';
    $m=pl_verify_publisher_envelope($json,$key);
    if(($m['schema']??null)!==1||($m['type']??null)!=='sample'||!is_string($m['slug']??null)||!is_string($m['version']??null)
        ||!is_int($m['archive_bytes']??null)||$m['archive_bytes']<1||$m['archive_bytes']>12000000||!preg_match('/^[a-f0-9]{64}$/D',$m['archive_sha256']??'')
        ||!preg_match('/^[a-f0-9]{64}$/D',$m['manifest_sha256']??'')||!is_array($m['files']??null)||array_diff(array_keys($m['files']),['pack.json','structure.json'])!==[]||count($m['files'])!==2){throw new DomainException('Invalid signed sample inventory.');}
    pl_plugin_slug($m['slug']);foreach($m['files'] as $hash){if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){throw new DomainException('Invalid signed sample file digest.');}}
    if(isset($m['expires_at'])&&(!is_int($m['expires_at'])||$m['expires_at']<time())){throw new DomainException('Signed sample metadata has expired.');}
    return $m;
}
function pl_sample_package_read(string $slug,bool $installed=true): array
{
    $slug=pl_plugin_slug($slug);$base=pl_sample_package_directory().'/'.$slug;
    if(!is_dir($base)||is_link($base)){throw new DomainException('The sample package is unavailable.');}
    $names=array_values(array_diff(scandir($base)?:[],['.','..']));
    if(array_diff($names,['package.json','pack.json','structure.json','sample-envelope.json'])!==[]){throw new DomainException('Unexpected file in the data-only sample package.');}
    $bytes=[];foreach($names as $name){$path=$base.'/'.$name;if(is_link($path)||!is_file($path)||filesize($path)>5000000){throw new DomainException('Invalid sample package member.');}$bytes[$name]=(string)file_get_contents($path);}
    $manifest=pl_sample_package_manifest(json_decode($bytes['package.json']??'',true,32,JSON_THROW_ON_ERROR),$slug);pl_sample_package_content($manifest,$bytes);
    $digest=hash('sha256',$bytes['package.json']);$trust='unverified';
    if(isset($bytes['sample-envelope.json'])){$signed=pl_sample_package_envelope($bytes['sample-envelope.json'],pl_sample_publisher_key());if($signed['slug']!==$slug||$signed['version']!==$manifest['version']||!hash_equals($signed['manifest_sha256'],$digest)||$signed['files']!=$manifest['files']){throw new DomainException('Signed sample identity differs from the installed files.');}$trust='verified';}
    if($installed){
        if(pl_sample_packages_readonly()){if($trust!=='verified'){throw new DomainException('Host-preloaded sample packages require a verified publisher envelope.');}}
        else{$record=pl_plugin_record($slug);if(!$record||$record['type']!=='sample'||!hash_equals($record['files_digest'],$digest)){throw new DomainException('This sample package has not been installed or its content changed.');}$trust=$record['trust'];}
        pl_sample_package_requirements($manifest);
    }
    return ['slug'=>$slug,'manifest'=>$manifest,'files_digest'=>$digest,'trust'=>$trust,'path'=>$base];
}
function pl_sample_installed_packages(): array
{
    $root=pl_sample_package_directory();$out=[];if(!is_dir($root)){return [];}
    foreach(scandir($root)?:[] as $slug){if(!preg_match('/^sample-[a-z][a-z0-9-]{0,52}$/D',$slug)){continue;}try{$out[$slug]=pl_sample_package_read($slug);}catch(Throwable $e){error_log('PHP Ledger sample package unavailable: '.$slug.' ('.get_class($e).').');}}
    return $out;
}

/** Uses the package framework's bounded ZIP member scan and private file writer. */
function pl_sample_stage_members(int $actor,ZipArchive $zip,array $members,string $slug): array
{
    pl_plugin_require_admin($actor);
    if(array_diff(array_keys($members),['package.json','pack.json','structure.json'])!==[]||count($members)!==3){throw new DomainException('A data-only sample ZIP contains exactly package.json, pack.json and structure.json.');}
    $bytes=[];foreach($members as $name=>$index){$stat=$zip->statIndex($index);if(!$stat||$stat['size']>5000000||($name==='package.json'&&$stat['size']>262144)){throw new DomainException('Sample package member exceeds its content limit.');}$raw=$zip->getFromIndex($index);if($raw===false){throw new DomainException('Unreadable sample archive member.');}$bytes[$name]=$raw;}
    $manifest=pl_sample_package_manifest(json_decode($bytes['package.json'],true,32,JSON_THROW_ON_ERROR),$slug);pl_sample_package_content($manifest,$bytes);pl_sample_package_requirements($manifest);
    $root=pl_sample_package_directory(true);$base=$root.'/'.$slug;
    if(file_exists($base)||is_link($base)||pl_plugin_record($slug)!==null){throw new DomainException('This sample is already present. Remove its package before installing the replacement; existing companies are kept.');}
    try{foreach($bytes as $name=>$raw){pl_plugin_write_staged($base.'/'.$name,$raw);}}catch(Throwable $e){pl_package_remove_directory($base,$root);throw $e;}
    return ['slug'=>$slug,'manifest'=>$manifest,'files_digest'=>hash('sha256',$bytes['package.json']),'type'=>'sample'];
}
function pl_sample_package_install(int $actor,string $slug,string $reason,string $key,?string $envelope=null): array
{
    pl_plugin_require_admin($actor);pl_sample_package_directory(true);$key=pl_request_key($key);$reason=pl_ledger_text($reason,'Reason',500);
    $package=pl_sample_package_read($slug,false);pl_sample_package_requirements($package['manifest']);
    if($envelope!==null){$signed=pl_sample_package_envelope($envelope,pl_sample_publisher_key());if($signed['slug']!==$slug||$signed['version']!==$package['manifest']['version']||!hash_equals($signed['manifest_sha256'],$package['files_digest'])||$signed['files']!=$package['manifest']['files']){throw new DomainException('Downloaded sample differs from its signed inventory.');}pl_plugin_write_staged($package['path'].'/sample-envelope.json',$envelope);$package['trust']='verified';}
    return pl_ledger_transaction(function()use($actor,$slug,$reason,$key,$package):array{
        if(pl_plugin_record($slug)!==null){throw new DomainException('This sample package is already installed.');}
        $m=$package['manifest'];$result=['slug'=>$slug,'version'=>$m['version'],'status'=>'active','trust'=>$package['trust']];
        DB::insert('pl_packages',['slug'=>$slug,'type'=>'sample','name'=>$m['name'],'version'=>$m['version'],'contract'=>1,'api_version'=>PL_PLUGIN_API_VERSION,'trust'=>$package['trust'],'status'=>'active','manifest'=>json_encode($m,JSON_THROW_ON_ERROR),'manifest_hash'=>pl_plugin_manifest_hash($m),'files_digest'=>$package['files_digest'],'revision'=>1,'installed_by'=>$actor,'updated_by'=>$actor]);
        pl_plugin_audit($actor,$slug,'installed',$package['trust'],$package['files_digest'],$reason,null,$result,null,$key);return $result;
    });
}
function pl_sample_package_remove(int $actor,string $slug,string $reason,string $key): void
{
    pl_plugin_require_admin($actor);$root=pl_sample_package_directory(true);$slug=pl_plugin_slug($slug);$reason=pl_ledger_text($reason,'Reason',500);$key=pl_request_key($key);
    pl_ledger_transaction(function()use($actor,$slug,$reason,$key):void{
        $row=DB::queryFirstRow('SELECT * FROM pl_packages WHERE slug=%s FOR UPDATE',$slug);
        if(!$row||$row['type']!=='sample'){throw new DomainException('Choose an installed sample package.');}
        DB::query('DELETE FROM pl_packages WHERE slug=%s',$slug);
        pl_plugin_audit($actor,$slug,'uninstalled',$row['trust'],$row['files_digest'],$reason,['slug'=>$slug],['slug'=>$slug,'companies_kept'=>true],null,$key);
    });
    if(!pl_package_remove_directory($root.'/'.$slug,$root)){throw new DomainException('The sample is no longer selectable, but its files require host cleanup. Existing companies are unchanged.');}
}

function pl_sample_directory_parse(string $json): array
{
    if(strlen($json)>1000000){throw new DomainException('Sample directory is too large.');}
    $feed=json_decode($json,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($feed)||($feed['schema']??null)!==1||!is_array($feed['packages']??null)||!array_is_list($feed['packages'])||count($feed['packages'])>200){throw new DomainException('Unsupported sample directory.');}
    $seen=[];foreach($feed['packages'] as &$entry){
        if(!is_array($entry)||($entry['type']??null)!=='sample'||!is_string($entry['slug']??null)||!str_starts_with($entry['slug'],'sample-')||!is_string($entry['version']??null)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$entry['version'])){throw new DomainException('Invalid sample directory entry.');}
        $slug=pl_plugin_slug($entry['slug']);if(isset($seen[$slug])){throw new DomainException('Duplicate directory package.');}$seen[$slug]=true;
        foreach(['name'=>160,'description'=>500,'author'=>160,'licence'=>80] as $field=>$length){$entry[$field]=pl_ledger_text($entry[$field]??null,$field,$length);}
        if($entry['licence']!=='CC0-1.0'){throw new DomainException('Directory sample data must be CC0.');}
        $entry['homepage']='https://phpledger.com/directory/'.$slug.'/';
        $entry['metadata_url']='https://phpledger.com/directory/'.$slug.'/'.$entry['version'].'/sample-envelope.json';
    }unset($entry);return $feed;
}
/** Explicit refresh/install only. Redirects are refused and the destination is fixed-origin. */
function pl_sample_directory_fetch(string $url): string
{
    if(!preg_match('#^https://phpledger\.com/directory/(?:index\.json|sample-[a-z0-9-]+/[0-9]+\.[0-9]+\.[0-9]+/sample-envelope\.json)$#D',$url)||!extension_loaded('curl')){throw new DomainException('A supported directory URL and PHP cURL are required.');}
    $body='';$request=curl_init($url);
    curl_setopt_array($request,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>4000000){return 0;}$body.=$chunk;return strlen($chunk);}]);
    try{if(curl_exec($request)!==true||curl_getinfo($request,CURLINFO_RESPONSE_CODE)!==200){throw new DomainException('The sample directory is unavailable. Installed samples and the neutral starter still work offline.');}}finally{curl_close($request);}return $body;
}
function pl_sample_directory_cache_path(): string { return dirname(pl_sample_package_directory()).'/directory-cache.json'; }
function pl_sample_directory_cached(): array
{
    $path=pl_sample_directory_cache_path();if(!is_file($path)||is_link($path)){return ['schema'=>1,'packages'=>[]];}
    try{return pl_sample_directory_parse((string)file_get_contents($path));}catch(Throwable){return ['schema'=>1,'packages'=>[]];}
}
function pl_sample_directory_refresh(int $actor): array
{
    pl_plugin_require_admin($actor);pl_sample_package_directory(true);$feed=pl_sample_directory_parse(pl_sample_directory_fetch(PL_SAMPLE_DIRECTORY_URL));
    pl_install_write_private(pl_sample_directory_cache_path(),json_encode($feed,JSON_THROW_ON_ERROR));return $feed;
}
function pl_sample_directory_install(int $actor,string $slug,string $key): array
{
    pl_plugin_require_admin($actor);$root=pl_sample_package_directory(true);$entry=null;
    foreach(pl_sample_directory_cached()['packages'] as $candidate){if($candidate['slug']===$slug){$entry=$candidate;break;}}
    if($entry===null){throw new DomainException('Refresh the directory and choose a listed sample.');}
    $envelope=pl_sample_directory_fetch($entry['metadata_url']);$metadata=pl_sample_package_envelope($envelope,pl_sample_publisher_key());
    if($metadata['slug']!==$slug||$metadata['version']!==$entry['version']){throw new DomainException('Signed sample differs from the selected directory version.');}
    require_once __DIR__.'/update_download_functions.php';
    $url='https://github.com/phpledger/'.$slug.'/releases/download/v'.$metadata['version'].'/'.$slug.'-'.$metadata['version'].'.zip';
    $archive=pl_download_verified_archive($metadata,$root,$url);
    try{$zip=new ZipArchive();if($zip->open($archive)!==true){throw new DomainException('The sample archive is invalid.');}try{if($zip->locateName($slug.'/package.json')===false||$zip->locateName($slug.'/plugin.json')!==false){throw new DomainException('The directory archive is not the selected data-only sample.');}}finally{$zip->close();}$staged=pl_plugin_stage_archive($actor,$archive);if(($staged['type']??null)!=='sample'||$staged['slug']!==$slug){throw new DomainException('The directory returned a different package type.');}return pl_sample_package_install($actor,$slug,'Installed reviewed directory sample',$key,$envelope);}catch(Throwable $error){if(isset($staged)&&pl_plugin_record($slug)===null){pl_package_remove_directory($root.'/'.$slug,$root);}throw $error;}finally{if(is_file($archive)){unlink($archive);}}
}

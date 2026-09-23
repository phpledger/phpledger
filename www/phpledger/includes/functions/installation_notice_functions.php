<?php
declare(strict_types=1);

require_once __DIR__ . '/installation_state_functions.php';
require_once __DIR__ . '/update_channel_functions.php';

const PL_INSTALL_NOTICE_URL = 'https://phpledger.com/installations/notice';

/** Existing installations stay silent until their administrator chooses a preference. */
function pl_install_notice_state(): array
{
    return pl_install_read_state('notice.json') + ['format'=>1, 'installation_id'=>'', 'enabled'=>false,
        'registration'=>null, 'last_attempt'=>null, 'last_sent'=>null, 'last_payload'=>null, 'status'=>'not-sent'];
}

/** Explicitly consented fields only; never derive a company from financial books. */
function pl_install_notice_registration(array $input): array
{
    $out=[];
    foreach (['name'=>120,'email'=>254,'site'=>480,'company'=>160] as $key=>$limit) {
        $value=$input[$key]??'';
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value,'UTF-8')>$limit || preg_match('/[\x00-\x1f\x7f]/',$value)) {
            throw new DomainException('Enter valid optional registration details.');
        }
        $out[$key]=trim($value);
    }
    if ($out['name']==='' || !filter_var($out['email'],FILTER_VALIDATE_EMAIL)) { throw new DomainException('Registration needs your name and a valid email address.'); }
    if ($out['site']!=='') {
        $url=parse_url($out['site']);
        if (!is_array($url) || !in_array($url['scheme']??'', ['http','https'],true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new DomainException('Use a public site address without credentials, query or fragment.');
        }
    }
    return $out;
}

/** Called under the installer lock, or by the authorized settings service below. */
function pl_install_notice_choose(bool $enabled, ?array $registration): array
{
    $lock=pl_install_operation_lock('notice.lock');
    try {
    if (in_array(getenv('PL_ENV'),['demo','demo-install'],true)) { $enabled=false; $registration=null; }
    if (!$enabled) { $registration=null; }
    $registration=$registration===null?null:pl_install_notice_registration($registration);
    $state=pl_install_notice_state();
    if (!preg_match('/^[a-f0-9]{32}$/D',(string)$state['installation_id'])) { $state['installation_id']=bin2hex(random_bytes(16)); }
    $state['enabled']=$enabled;
    $state['registration']=$registration;
    // Disabling registration also removes named details from the local last-payload display.
    if ($registration===null && is_array($state['last_payload'])) { unset($state['last_payload']['registration']); }
    pl_install_save_state($state,'notice.json');
    return $state;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function pl_install_notice_configure(int $actor, bool $enabled, ?array $registration): array
{
    pl_require_capability($actor,0,'installation.admin','Installation administration permission is required.');
    $lock=pl_install_operation_lock();
    try { return pl_install_notice_choose($enabled,$registration); }
    finally { flock($lock,LOCK_UN); fclose($lock); }
}

/** This explicit allowlist excludes database destinations, paths, users and book data. */
function pl_install_notice_payload(array $state, string $event, string $version, array $platform, string $php, string $os, string $mode, string $at): array
{
    if (!in_array($event,['install','update-check','preferences'],true) || !preg_match('/^[a-f0-9]{32}$/D',(string)($state['installation_id']??'')) || !pl_release_version_valid($version)) {
        throw new DomainException('Invalid installation notice.');
    }
    $payload=['schema'=>1,'installation_id'=>$state['installation_id'],'event'=>$event,'version'=>$version,
        'channel'=>pl_release_channel_for($version),'php_version'=>$php,
        'database_engine'=>$platform['engine'],'database_version'=>$platform['version'],
        'os_family'=>$os,'mode'=>$mode,'at'=>$at];
    if ($state['registration']!==null) { $payload['registration']=pl_install_notice_registration($state['registration']); }
    return $payload;
}

/** Fixed-origin, bounded transport. Never invoked by rendering a page. */
function pl_install_notice_transport(string $url, ?array $payload, int $limit): string
{
    if (!in_array($url,[PL_INSTALL_NOTICE_URL,PL_RELEASE_FEED_URL],true)) { throw new DomainException('Unknown project service.'); }
    $handle=curl_init($url);$response='';
    if ($handle===false) { throw new RuntimeException('Project service unavailable.'); }
    try {
        curl_setopt_array($handle,[CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>2500,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],
            CURLOPT_USERAGENT=>'PHP-Ledger/installation-notice',
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use(&$response,$limit):int { if(strlen($response)+strlen($chunk)>$limit){return 0;} $response.=$chunk;return strlen($chunk); }]);
        if ($payload!==null) { curl_setopt($handle,CURLOPT_POST,true);curl_setopt($handle,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR)); }
        if (curl_exec($handle)!==true || curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200) { throw new RuntimeException('Project service unavailable.'); }
        return $response;
    } finally { curl_close($handle); }
}

/** Failure cannot make installation or accounting fail; details never enter public logs. */
function pl_install_notice_send(string $event='install', ?callable $transport=null): bool
{
    $lock=null;
    try {
        $lock=pl_install_operation_lock('notice.lock');
        $state=pl_install_notice_state();
        if (!$state['enabled'] || getenv('PL_INSTALL_NOTICE')==='0' || in_array(getenv('PL_ENV'),['demo','demo-install'],true)
            || ($transport===null && getenv('PL_ENV')!=='production' && getenv('PL_ENV')!==false)) { return false; }
        $payload=pl_install_notice_payload($state,$event,trim((string)file_get_contents(dirname(__DIR__,2).'/VERSION')),
            pl_database_platform(pl_database_server_version()),PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,PHP_OS_FAMILY,pl_update_mode(),gmdate('c'));
        $state['last_attempt']=gmdate('c');$state['status']='unavailable';
        try {
            $reply=json_decode(($transport??'pl_install_notice_transport')(PL_INSTALL_NOTICE_URL,$payload,8192),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($reply) || ($reply['accepted']??false)!==true) { throw new RuntimeException('Notice was not accepted.'); }
            $state['last_sent']=$state['last_attempt'];$state['last_payload']=$payload;$state['status']='sent';
        } finally { pl_install_save_state($state,'notice.json'); }
        return true;
    } catch (Throwable $error) { return false; }
    finally { if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);} }
}

/** Called only after the installer's final POST has successfully created its owner. */
function pl_install_notice_after_setup(array $choice): void
{
    try {
        $registration=($choice['register_installation']??'')==='1' ? pl_install_notice_registration([
            'name'=>$choice['name']??'','email'=>$choice['email']??'','site'=>$choice['registration_site']??'', 'company'=>$choice['registration_company']??'']) : null;
        pl_install_notice_choose(($choice['installation_notice']??'')==='1',$registration);
        pl_install_notice_send();
    } catch (Throwable $error) { /* Installation remains usable even when notice storage is unavailable. */ }
}

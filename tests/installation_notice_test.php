<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/www/phpledger/includes/functions/installation_notice_functions.php';

function with_notice_fixture(callable $action): void
{
    $previous=getenv('PL_INSTALL_DIRECTORY');$root=sys_get_temp_dir().'/pl-notice-'.bin2hex(random_bytes(8));mkdir($root,0700);
    putenv('PL_INSTALL_DIRECTORY='.$root);
    try { $action(); }
    finally { putenv($previous===false?'PL_INSTALL_DIRECTORY':'PL_INSTALL_DIRECTORY='.$previous);foreach(glob($root.'/*')?:[] as $path){unlink($path);}rmdir($root); }
}

test('installation notices are optional, anonymous by default and preserve a random identity',function():void{
    with_notice_fixture(function():void{
        assert_same(false,pl_install_notice_state()['enabled']);
        $first=pl_install_notice_choose(true,null);assert_same(32,strlen($first['installation_id']));
        $payload=pl_install_notice_payload($first,'install','1.3.0',['engine'=>'mysql','version'=>'8.4.3'],'8.3.33','Linux','managed','2026-09-23T00:00:00+00:00');
        assert_same(['schema','installation_id','event','version','channel','php_version','database_engine','database_version','os_family','mode','at'],array_keys($payload));
        $second=pl_install_notice_choose(false,null);assert_same($first['installation_id'],$second['installation_id']);
        $calls=0;assert_same(false,pl_install_notice_send('install',function()use(&$calls){++$calls;return '{"accepted":true}';}));assert_same(0,$calls);
    });
});

test('notice transport failure does not block installation and only accepted payloads are recorded',function():void{
    with_notice_fixture(function():void{
        pl_install_notice_choose(true,null);
        assert_same(false,pl_install_notice_send('install',static function(){throw new RuntimeException('private transport detail');}));
        assert_same('unavailable',pl_install_notice_state()['status']);assert_same(null,pl_install_notice_state()['last_payload']);
        assert_same(true,pl_install_notice_send('update-check',static function(string $url,array $payload,int $limit):string{
            assert_same(PL_INSTALL_NOTICE_URL,$url);assert_same('update-check',$payload['event']);assert_true(!isset($payload['registration']));return '{"accepted":true}';
        }));
        assert_same('sent',pl_install_notice_state()['status']);assert_same('update-check',pl_install_notice_state()['last_payload']['event']);
    });
});

test('named registration is explicit, removable locally and rejects credentials in URLs',function():void{
    with_notice_fixture(function():void{
        $registration=['name'=>'Fictional maintainer','email'=>'sample@example.invalid','site'=>'https://example.invalid','company'=>'Fictional books'];
        $state=pl_install_notice_choose(true,$registration);assert_same($registration,$state['registration']);
        pl_install_notice_send('preferences',static fn()=>'{"accepted":true}');
        $state=pl_install_notice_choose(true,null);assert_same(null,$state['registration']);assert_true(!isset($state['last_payload']['registration']));
        assert_throws(fn()=>pl_install_notice_registration(array_replace($registration,['site'=>'https://secret:password@example.invalid'])),DomainException::class);
        $f=ledger_fixture();assert_throws(fn()=>pl_install_notice_configure($f['actor_id'],true,null),DomainException::class);
    });
});

test('shared demos ignore forged registration and never contact project services',function():void{
    with_notice_fixture(function():void{
        $env=getenv('PL_ENV');putenv('PL_ENV=demo-install');
        try {
            $state=pl_install_notice_choose(true,['name'=>'forged']);assert_same(false,$state['enabled']);assert_same(null,$state['registration']);
            $called=false;assert_same(false,pl_install_notice_send('install',function()use(&$called){$called=true;return '{"accepted":true}';}));assert_same(false,$called);
        } finally { putenv('PL_ENV='.$env); }
    });
});

<?php
declare(strict_types=1);
require_once __DIR__.'/installation_notice_functions.php';

function pl_web_updates(int $actor, array $user, array $company, string $method): never
{
    pl_require_capability($actor,0,'installation.admin','Installation administration permission is required.');
    if ($method==='POST') {
        pl_require_csrf(pl_web_text($_POST,'csrf'));
        if (pl_demo_enabled() || pl_shared_demo_enabled()) { throw new DomainException('Project contact is disabled in the shared demo.'); }
        try {
            $action=pl_web_text($_POST,'action');
            if ($action==='preferences') {
                // The anonymous notice is always on (owner decision, 26 September 2026); registration is the only choice here.
                $registration=pl_web_text($_POST,'register')==='1' ? ['name'=>pl_web_text($_POST,'name'),'email'=>pl_web_text($_POST,'email'),'site'=>pl_web_text($_POST,'site'),'company'=>pl_web_text($_POST,'registration_company')] : null;
                pl_install_notice_configure($actor,true,$registration);
                $sent=pl_install_notice_send('preferences');
                pl_notice($sent ? ($registration!==null?'Registration saved and notice accepted.':'Registration removed; an anonymous notice replaced it.') : 'Preferences saved. The project service was unavailable; your installation is unaffected.');
            } elseif ($action==='check') {
                // The same random installation ID accompanies this explicit check.
                pl_install_notice_send('update-check');
                $feed=pl_release_feed_parse(pl_install_notice_transport(PL_RELEASE_FEED_URL,null,PL_RELEASE_FEED_MAX_BYTES));
                $available=pl_release_feed_available($feed,pl_app_version());
                $_SESSION['pl_update_available']=$available;
                pl_notice($available===null?'No newer release is listed for this channel.':'A newer release is available. Follow the verified download and your installation channel.');
            } else { throw new DomainException('Unknown update action.'); }
        } catch (Throwable $error) {
            pl_notice($error instanceof DomainException?$error->getMessage():'The project service is unavailable. Your installation and accounting remain unchanged.');
        }
        pl_redirect('/updates');
    }
    $state=pl_install_notice_state();
    pl_render('updates',['title'=>'Updates and privacy','user'=>$user,'company'=>$company,'state'=>$state,
        'form'=>pl_form_state(pl_url('/updates')),'available'=>$_SESSION['pl_update_available']??null,
        'disabled'=>pl_demo_enabled()||pl_shared_demo_enabled(),'mode'=>pl_update_mode()]);
}

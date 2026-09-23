"""Two actual release images over one populated baseline fixture; uses existing container helpers.
Run only against the explicitly named disposable artifact-gate network/database.
"""
from __future__ import annotations
import argparse,json,os,subprocess,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
import _container_support as support

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--baseline-image',default='ghcr.io/phpledger/phpledger:1.2.1')
    parser.add_argument('--candidate-image',required=True)
    parser.add_argument('--network',required=True)
    parser.add_argument('--baseline-fixtures',type=Path,required=True)
    args=parser.parse_args()
    if args.network not in ('codex13artifact_default', 'codex13onboarding_default'):raise RuntimeError('Use the dedicated artifact-gate network')
    support.NETWORK=args.network;support.DB_NAME='db_test';support.PRIVATE_VOLUME='codex13artifact-production-private';support.WEB_NAME='codex13artifact-production-web'
    env={'PL_DB_HOST':'db_test','PL_DB_NAME':'phpledger_test','PL_DB_USER':'root','PL_DB_PASSWORD':'local-test-root-only','PL_INSTALL_DIRECTORY':'/var/lib/phpledger/installation','PL_ENV':'test','PL_UPGRADE_KEEP':'1','PL_UPGRADE_RECEIPT':'/var/lib/phpledger/proof.json','PL_BASELINE_FIXTURES':'/baseline-fixtures'}
    tools=Path(__file__).resolve().parents[1]/'tools';database=None
    def worker(image,mode):
        command=['docker','run','--rm','--network',args.network,'-v',f'{support.PRIVATE_VOLUME}:/var/lib/phpledger','-v',f'{tools}:/proof:ro','-v',f'{args.baseline_fixtures.resolve()}:/baseline-fixtures:ro']
        for key,value in env.items():command+=['-e',f'{key}={value}']
        command+=['--entrypoint','php',image,'/proof/verify-m17-upgrade.php',mode,'/var/www/phpledger']
        support.run(command)
    try:
        support.run(['docker','volume','create',support.PRIVATE_VOLUME])
        support.run_once_sh(args.baseline_image,args.network,env,'mkdir -p /var/lib/phpledger/installation /var/lib/phpledger/oauth')
        worker(args.baseline_image,'seed')
        receipt=json.loads(support.run_once_php(args.baseline_image,args.network,env,"echo file_get_contents('/var/lib/phpledger/proof.json');"))
        database=receipt['database']
        support.run_once_php(args.baseline_image,args.network,env,"file_put_contents('/var/lib/phpledger/installation/installed.json',json_encode(['format'=>1,'initial_owner_id'=>"+str(receipt['fixture']['actor_id'])+",'completed_at'=>gmdate('c')]));")
        boot_env={'PL_DB_HOST':'db_test','PL_DB_NAME':database,'PL_DB_USER':'root','PL_DB_PASSWORD':'local-test-root-only','PL_INSTALL_DIRECTORY':'/var/lib/phpledger/installation','PL_ENV':'production'}
        support.IMAGE_TAG=args.baseline_image
        support.start_web({**boot_env,'PL_AUTO_MIGRATE':'0'})
        support.wait_for_health(support.WEB_NAME)
        print('PASS published 1.2.1 production image serves populated baseline')
        support.try_run(['docker','rm','-f',support.WEB_NAME])
        support.IMAGE_TAG=args.candidate_image
        support.start_web({**boot_env,'PL_AUTO_MIGRATE':'1'})
        support.wait_for_health(support.WEB_NAME)
        version=support.exec_php(support.WEB_NAME,"echo trim(file_get_contents('/var/www/phpledger/www/phpledger/VERSION'));")
        if version!='1.3.0':raise RuntimeError('Candidate production image version differs')
        worker(args.candidate_image,'verify-candidate')
        print('PASS exact 1.3.0 production image PL_AUTO_MIGRATE preserves baseline journals, partial payment, receipts and balances')
    finally:
        support.try_run(['docker','rm','-f',support.WEB_NAME])
        if database is not None:
            if not database.startswith('phpledger_m17_verify_'):raise RuntimeError('Unexpected fixture database')
            support.run_once_php(args.baseline_image,args.network,env,"require '/var/www/phpledger/vendor/autoload.php';DB::$host='db_test';DB::$user='root';DB::$password='local-test-root-only';DB::$dbName='information_schema';DB::query('DROP DATABASE %b',"+repr(database)+");")
        support.try_run(['docker','volume','rm',support.PRIVATE_VOLUME])
    return 0
if __name__=='__main__':raise SystemExit(main())

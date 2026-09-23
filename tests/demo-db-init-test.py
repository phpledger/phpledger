"""Fresh MySQL source-mode regression; synthetic data, isolated disposable container.
Run: python tests/demo-db-init-test.py --log /private/fixture.log
"""
import argparse,json,subprocess,tempfile,time,uuid
from pathlib import Path

def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--image',default='mysql:8.4');p.add_argument('--log',type=Path,required=True);args=p.parse_args()
 root=Path(__file__).resolve().parents[1];name='phpledger-init-test-'+uuid.uuid4().hex[:12];created=False
 def run(command):
  result=subprocess.run(command,capture_output=True,text=True,timeout=60)
  if result.returncode:raise RuntimeError('Fixture command failed: '+' '.join(command[:2]))
  return result.stdout
 with tempfile.TemporaryDirectory()as directory:
  script=Path(directory)/'init.sh';script.write_bytes((root/'docker/demo-db-init.sh').read_bytes().replace(b'\r\n',b'\n'))
  try:
   run(['docker','run','-d','--name',name,'--network','none','--memory','768m','-e','MYSQL_ROOT_PASSWORD=fixture-only-root','-e','PL_DEMO_WEB_PASSWORD='+'a'*64,'-e','PL_DEMO_RESET_PASSWORD='+'b'*64,'--mount','type=bind,source='+str(script)+',target=/fixture.sh,readonly','--entrypoint','bash',args.image,'-c','cp /fixture.sh /docker-entrypoint-initdb.d/01-demo-users.sh && chmod 0644 /docker-entrypoint-initdb.d/01-demo-users.sh && exec /usr/local/bin/docker-entrypoint.sh mysqld']);created=True
   for attempt in range(65):
    state=json.loads(run(['docker','inspect','--format','{{json .State}}',name]))
    if not state['Running']:raise AssertionError('Fresh initialization exited: '+str(state['ExitCode']))
    if 'MySQL init process done. Ready for start up.'in run(['docker','logs',name]):break
    time.sleep(2)
   else:raise AssertionError('Fresh initialization timed out')
   for account,password in [('web','a'*64),('reset','b'*64)]:
    sql='CREATE TABLE probe_'+account+' (id INT PRIMARY KEY); INSERT INTO probe_'+account+' VALUES (1); SELECT COUNT(*) FROM probe_'+account+'; DROP TABLE probe_'+account+';'
    for attempt in range(15):
     result=subprocess.run(['docker','exec','-e','MYSQL_PWD='+password,name,'mysql','--protocol=socket','-u','ledger_demo_'+account,'-N','phpledger_demo','-e',sql],capture_output=True,text=True,timeout=15)
     if result.returncode==0:
      assert result.stdout.strip()=='1';break
     if 'ERROR 2002'not in result.stderr or attempt==14:raise AssertionError('Synthetic account access failed: '+account)
     time.sleep(1)
   print('PASS sourced0644 fresh initialization; both web/reset accounts DDL and data access')
  finally:
   if created:
    logs=subprocess.run(['docker','logs',name],capture_output=True,text=True,timeout=30);args.log.parent.mkdir(parents=True,exist_ok=True);args.log.write_text(logs.stdout+logs.stderr)
    run(['docker','rm','-f','-v',name])
if __name__=='__main__':main()

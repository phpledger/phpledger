"""Separate expense/receipt browser regression. Local disposable fixture only; no JavaScript."""
import base64, importlib.util, json, os, secrets, sys, uuid
from pathlib import Path
from urllib.parse import urlparse, urljoin, parse_qs, urlencode
spec=importlib.util.spec_from_file_location('accounting_fixture',Path(__file__).with_name('accounting-http-smoke.py'))
a=importlib.util.module_from_spec(spec);sys.modules[spec.name]=a;spec.loader.exec_module(a)
h=a.http
h.ORIGIN=os.environ.get('PL_TRANSACTION_HTTP_ORIGIN','http://127.0.0.1:18200')
if urlparse(h.ORIGIN).hostname!='127.0.0.1':raise RuntimeError('Loopback fixture only')
def local_url(value):
 result=urljoin(h.ORIGIN,value);parsed=urlparse(result);expected=urlparse(h.ORIGIN)
 if (parsed.scheme,parsed.hostname,parsed.port)!=('http','127.0.0.1',expected.port):raise RuntimeError('Unexpected local redirect')
 return result
h.local_url=local_url

def run():
 checks=[]
 def check(condition,name):
  if not condition:raise AssertionError(name)
  checks.append(name);print('PASS:',name,flush=True)
 p={'email':'expense-'+uuid.uuid4().hex+'@example.test','password':secrets.token_urlsafe(32),'viewer':'viewer-'+uuid.uuid4().hex+'@example.test','viewer_password':secrets.token_urlsafe(32)}
 encoded=base64.b64encode(json.dumps(p).encode()).decode()
 f=a.php_local("$p=json_decode(base64_decode('"+encoded+"'),true);"+r'''
$owner=pl_create_user($p['email'],'Sample route owner',$p['password']);
$viewer=pl_create_user($p['viewer'],'Sample route viewer',$p['viewer_password']);
$companies=[];
foreach(['Expense route A','Expense route B'] as $name){$companies[]=pl_setup_company($owner,['name'=>$name,'currency'=>'USD','start_date'=>'2026-01-01','fiscal_year_end'=>'12-31','start_mode'=>'fresh','zero_balances_confirmed'=>true,'template_digest'=>pl_starter_template()['digest']],bin2hex(random_bytes(20)));}
DB::insert('pl_company_members',['company_id'=>$companies[0]['id'],'user_id'=>$viewer,'role_id'=>DB::queryFirstField("SELECT id FROM pl_roles WHERE company_id IS NULL AND slug='viewer'")]);
$accounts=[];foreach($companies[0]['accounts'] as $account){if($account['role']==='cash_bank'){$accounts['cash']=$account['id'];}if($account['role']==='expense'){$accounts['expense']=$account['id'];}if($account['role']==='income'){$accounts['receipt']=$account['id'];}}
echo json_encode(['a'=>$companies[0]['id'],'b'=>$companies[1]['id'],'accounts'=>$accounts]);
''')
 owner=h.Session();a.sign_in(owner,p['email'],p['password'],f['a'])
 def select(company):
  page=owner.request('/companies');form=next(x for x in page.markup.forms if urlparse(x.action).path=='/company/select'and x.fields.get('company_id')==str(company));owner.submit(form)
 def count():return a.php_local("echo json_encode(['n'=>(int)DB::queryFirstField('SELECT COUNT(*) FROM pl_documents WHERE company_id IN (%i,%i)',"+str(f['a'])+","+str(f['b'])+")]);")['n']
 chooser=owner.request('/transactions/new');check(chooser.status==200 and not any(urlparse(x.action).path.endswith('/save')for x in chooser.markup.forms),'Generic new is chooser without default expense form')
 for kind,screen,party in [('expense','expenses','Paid to'),('receipt','receipts','Received from')]:
  page=owner.request('/'+screen+'/new');form=page.markup.form_for('/'+screen+'/save')
  check(page.status==200 and party in page.body and 'type="radio" name="kind"'not in page.body,'Separate '+kind+' page and fixed context without toggle')
  check(all(o.get('data-category-kind')in(None,kind)for o in form.options['category_account_id']),'No-JavaScript '+kind+' categories scoped')
  legacy=owner.request('/transactions/new?kind='+kind);check(urlparse(legacy.url).path=='/'+screen+'/new','Legacy '+kind+' link canonicalized')
  values={'date':'2026-01-02','amount':'12.50','counterparty':'Sample route party','money_account_id':str(f['accounts']['cash']),'category_account_id':str(f['accounts'][kind]),'memo':'Sample retained memo'}
  before=count();bad=owner.submit(form,values|{'kind':'receipt'if kind=='expense'else'expense'});check(bad.status==422 and count()==before,'Forged '+kind+' hidden kind rejected')
  check(owner.submit(form,values|{'csrf':'invalid'}).status==403,'CSRF protects '+kind+' save')
  invalid=owner.submit(form,values|{'amount':'invalid'});retry=invalid.markup.form_for('/'+screen+'/save');check(invalid.status==422 and retry.fields['memo']==values['memo'],'Validation retains '+kind+' values on same screen')
  preview=owner.submit(retry,values|{'editor_action':'preview'});check(preview.status==200 and count()==before,'No-JavaScript '+kind+' preview does not save')
  saved=owner.submit(form,values);check(saved.status==200 and count()==before+1,'Separate '+kind+' draft saved')
  posting=saved.markup.form_for('/transactions/post');doc=posting.fields['id']
  edited=owner.request('/'+screen+'/edit?id='+doc);edit=edited.markup.form_for('/'+screen+'/save');check('Edit draft'in edited.body and 'data-heading-id'not in edited.body,'Existing '+kind+' identifier heading stays stable')
  other='receipts'if screen=='expenses'else'expenses';canonical=owner.request('/'+other+'/edit?id='+doc);check(urlparse(canonical.url).path=='/'+screen+'/edit','Wrong-kind edit canonicalized')
  forged=owner.request('/'+other+'/save',edit.fields);check(forged.status==422 and count()==before+1,'Wrong endpoint cannot convert '+kind+' draft')
  legacybad=owner.request('/transactions/save',edit.fields|{'kind':'receipt'if kind=='expense'else'expense'});check(legacybad.status==422,'Legacy save cannot convert '+kind+' draft')
  updated=owner.submit(edit,{'memo':'Updated sample'});check(updated.status==200,'Update '+kind+' draft succeeds')
  stale=owner.submit(edit,{'memo':'Stale sample'});check(stale.status==422 and stale.markup.form_for('/'+screen+'/save').fields['memo']=='Stale sample','Stale '+kind+' revision retains input')
  repeated=owner.submit(form,values);check(repeated.status==200 and count()==before+1,'Creation-key retry does not duplicate '+kind)
  changed=owner.submit(form,values|{'amount':'13.50'});check(changed.status==422 and count()==before+1,'Changed creation-key payload refused for '+kind)
 context={'kind':'receipt','return_filters[q]':'sample-search','return_account[id]':str(f['accounts']['cash']),'return_account[as_of]':'2026-01-31'}
 returned=owner.request('/transactions/new?'+urlencode(context));query=parse_qs(urlparse(returned.url).query)
 check(query.get('return_filters[q]')==['sample-search']and query.get('return_account[id]')==[str(f['accounts']['cash'])],'Legacy redirect preserves validated list and account context')
 legacyform=owner.request('/expenses/new').markup.form_for('/expenses/save');legacyvalues={'kind':'expense','date':'2026-01-02','amount':'3.00','counterparty':'Sample legacy party','money_account_id':str(f['accounts']['cash']),'category_account_id':str(f['accounts']['expense'])}
 before=count();wrongcategory=owner.submit(legacyform,legacyvalues|{'category_account_id':str(f['accounts']['receipt'])});check(wrongcategory.status==422 and count()==before,'Expense rejects income category without saving')
 legacyfailure=owner.request('/transactions/save',legacyform.fields|legacyvalues|{'amount':'invalid'});check(legacyfailure.status==422 and legacyfailure.markup.form_for('/expenses/save').fields['counterparty']=='Sample legacy party','Legacy save failure retains fields on canonical screen without toggle')
 legacyok=owner.request('/transactions/save',legacyform.fields|legacyvalues);check(legacyok.status==200 and count()==before+1,'Valid legacy save remains supported')
 form=owner.request('/expenses/new').markup.form_for('/expenses/save');before=count()
 invalid=owner.request('/transactions/save',form.fields|{'kind':'invalid'});check(invalid.status==422 and count()==before,'Unsupported legacy kind rejected')
 marker='PRIVATE-SAMPLE-'+uuid.uuid4().hex
 select(f['b']);cross=owner.submit(form,{'counterparty':marker,'memo':marker});check(marker not in cross.body and count()==before,'Old company submission cannot disclose retained fields under new company')
 wrongdoc=owner.request('/receipts/edit?id='+doc);check(wrongdoc.status==403,'Wrong-company document cannot be opened')
 select(f['a']);fresh=owner.request('/expenses/new').markup.form_for('/expenses/save')
 # Intercept only the redirect, then change company before consuming failed form state.
 class NoRedirect(h.HTTPRedirectHandler):
  def redirect_request(self,*args,**kwargs):return None
 original=owner.opener;owner.opener=h.build_opener(NoRedirect(),h.HTTPCookieProcessor(owner.cookies))
 failed=owner.submit(fresh,{'amount':'invalid','counterparty':marker,'memo':marker});owner.opener=original
 check(failed.status==303,'Failed save uses redirect form state')
 select(f['b']);retained=owner.request('/expenses/new');check(marker not in retained.body and count()==before,'Company switch before failed-form GET discards stale private input')
 select(f['a']);viewer=h.Session();a.sign_in(viewer,p['viewer'],p['viewer_password'],f['a'])
 for screen in ['expenses','receipts']:
  check(viewer.request('/'+screen+'/new').status==403,'Viewer cannot open '+screen+' editor')
  viewer_csrf=next(x.fields['csrf']for x in viewer.request('/companies').markup.forms if 'csrf'in x.fields)
  forged=fresh.fields|{'csrf':viewer_csrf,'kind':'receipt'if screen=='receipts'else'expense'}
  check(viewer.request('/'+screen+'/save',forged).status==403 and count()==before,'Viewer cannot save through '+screen+' endpoint')
 print(json.dumps({'checks':len(checks),'passed':True}))
if __name__=='__main__':run()

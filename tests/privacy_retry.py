"""A live mail lock delays coordinated erasure; the ID-only cron retry converges."""
import hashlib,json,subprocess
from support import COMPOSE,wp
fixture=json.loads(wp('eval',"""
    add_filter('pre_wp_mail','__return_true');$p=new WC_Product_Simple();$p->set_name('Privacy retry');$p->set_regular_price('20');$p->set_virtual(true);$p->save();
    $t=Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'15','retry-privacy@example.test');$o=Latse\\QuoteFlow\\Offers::accept($t);$o->set_status('completed');$o->save();
    global $wpdb;echo wp_json_encode(['id'=>Latse\\QuoteFlow\\Offers::find($t)->id,'order'=>$o->get_id(),'prefix'=>$wpdb->prefix]);
"""))
id=int(fixture['id']);order=int(fixture['order'])
lock='qf_email_'+hashlib.md5(f"{fixture['prefix']}qf_offers:{id}".encode()).hexdigest()
code=f'global $wpdb;$wpdb->get_var("SELECT GET_LOCK(\'{lock}\',0)");echo "READY\\n";flush();sleep(5);$wpdb->get_var("SELECT RELEASE_LOCK(\'{lock}\')");'
proc=subprocess.Popen([*COMPOSE,'exec','-T','--user','www-data','wp','php','/downloads/wp-cli.phar','eval',code],stdout=subprocess.PIPE,text=True)
try:
    assert proc.stdout.readline().strip()=='READY'
    result=wp('eval',f"WC_Privacy_Erasers::remove_order_personal_data(wc_get_order({order}));$e=wp_get_scheduled_event('qf_privacy_cleanup',[{id}]);echo wp_json_encode([$e?$e->args:null,wc_get_order({order})->get_meta('_qf_privacy_cleanup_pending')]);")
    assert json.loads(result)==[[id],'yes'],result
finally:proc.wait(timeout=15)
result=wp('eval',f"Latse\\QuoteFlow\\Privacy::cleanup_order({id});global $wpdb;$exists=$wpdb->get_var(\"SELECT id FROM {{$wpdb->prefix}}qf_offers WHERE id={id}\");echo wp_json_encode([(bool)$exists,(bool)wp_next_scheduled('qf_privacy_cleanup',[{id}]),wc_get_order({order})->get_meta('_qf_privacy_cleanup_pending')]);")
assert json.loads(result)==[False,False,''],result
print('Busy coordinated cleanup, ID-only scheduling and successful retry passed',flush=True)

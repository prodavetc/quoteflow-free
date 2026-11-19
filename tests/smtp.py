"""Actual local SMTP protocol handoff and rejection, without any external recipient."""
from email import policy
from email.parser import BytesParser
from pathlib import Path
import subprocess
from support import COMPOSE,wp
mu='/var/www/html/wp-content/mu-plugins/qf-smtp-protocol-test.php'
subprocess.run([*COMPOSE,'exec','-T','wp','test','!','-e',mu],check=True)
subprocess.run([*COMPOSE,'cp',str(Path(__file__).with_name('fixtures')/'smtp-server.php'),'wp:/tmp/qf-smtp-server.php'],check=True)
subprocess.run([*COMPOSE,'exec','-T','wp','tee',mu],text=True,input="""<?php
add_filter('wp_mail_from', static fn()=> 'store@example.test');
add_action('phpmailer_init', static function($mailer){
    $mailer->isSMTP();$mailer->Host='127.0.0.1';$mailer->Port=2526;
    $mailer->SMTPAuth=false;$mailer->SMTPSecure='';$mailer->SMTPAutoTLS=false;$mailer->Timeout=10;
});
""",stdout=subprocess.DEVNULL,check=True)
try:
    token=wp('eval',"$p=new WC_Product_Simple();$p->set_name('SMTP protocol fixture');$p->set_regular_price('20');$p->set_virtual(true);$p->save();echo Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'15','buyer@example.test');")
    for mode,attempt in (('reject',0),('accept',1)):
        proc=subprocess.Popen([*COMPOSE,'exec','-T','wp','php','/tmp/qf-smtp-server.php',mode],stdout=subprocess.PIPE,text=True)
        try:
            assert proc.stdout.readline().strip()=='READY'
            result=wp('eval',f"try{{Latse\\QuoteFlow\\Offer_Email::send('{token}',{attempt});echo 'accepted';}}catch(RuntimeException $e){{echo 'rejected';}}")
            assert result==('accepted' if mode=='accept' else 'rejected'),result
        finally:proc.wait(timeout=20)
    raw=subprocess.check_output([*COMPOSE,'exec','-T','wp','cat','/tmp/qf-smtp-message.eml'])
    mail=BytesParser(policy=policy.default).parsebytes(raw)
    content=mail.get_body(preferencelist=('html','plain')).get_content() if mail.is_multipart() else mail.get_content()
    assert token in content and mail['To']=='buyer@example.test'
    assert wp('eval',f"$o=Latse\\QuoteFlow\\Offers::find('{token}');echo $o->email_state.':'.$o->email_attempts;")=='accepted:2'
    print('Local SMTP rejection, retry, protocol delivery and message content passed',flush=True)
finally:
    subprocess.run([*COMPOSE,'exec','-T','wp','rm','-f',mu],check=True)

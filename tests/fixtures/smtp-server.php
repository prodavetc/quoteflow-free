<?php
// Local protocol fixture only. Never listens on an external interface or relays mail.
$server=stream_socket_server('tcp://127.0.0.1:2526',$errno,$error);
if(!$server){throw new RuntimeException($error);}
echo "READY\n";flush();
$client=stream_socket_accept($server,30);
if(!$client){throw new RuntimeException('SMTP connection timeout');}
stream_set_timeout($client,15);fwrite($client,"220 localhost test SMTP\r\n");
$message='';$data=false;
while(false!==($line=fgets($client))){
    if($data){
        if(rtrim($line,"\r\n")==='.'){
            file_put_contents('/tmp/qf-smtp-message.eml',$message);
            fwrite($client,"250 queued locally\r\n");$data=false;
        }else{$message.=$line;}
        continue;
    }
    $command=strtoupper(strtok($line," \r\n"));
    if(in_array($command,['EHLO','HELO'],true)){fwrite($client,"250 localhost\r\n");}
    elseif($command==='MAIL'){fwrite($client,"250 sender accepted\r\n");}
    elseif($command==='RCPT'){fwrite($client,($argv[1]??'accept')==='reject'?"550 test recipient rejection\r\n":"250 recipient accepted\r\n");}
    elseif($command==='DATA'){fwrite($client,"354 send message\r\n");$data=true;}
    elseif($command==='QUIT'){fwrite($client,"221 bye\r\n");break;}
    elseif($command==='RSET'){fwrite($client,"250 reset\r\n");}
    else{fwrite($client,"250 ok\r\n");}
}
fclose($client);fclose($server);

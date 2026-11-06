<?php
// Exactly one additive DEV migration. Never migrate/refresh/import other schemas.
try {
 $root=dirname(__DIR__);
 $previous='/var/www/svs-laravel/releases/20261106-clubunio-chat-async-locks';
 if(PHP_SAPI!=='cli'||$root!=='/var/www/svs-laravel/releases/20261106-clubunio-chat-interaction'
   ||realpath('/var/www/svs-laravel/current')!==$previous) throw new LogicException('Reviewed inactive candidate required');
 require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if(config('app.url')!=='https://dev.clubunio.ch') throw new LogicException('DEV required');
 $db=Illuminate\Support\Facades\DB::connection('clubunio');
 if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Reviewed database required');
 $name='2026_11_06_000300_chat_replies';
 $db->transaction(function()use($db,$root,$name){
   $db->statement("SET LOCAL lock_timeout='5s'");$db->statement("SET LOCAL statement_timeout='20s'");
   if(!$db->selectOne("SELECT pg_try_advisory_xact_lock(hashtext('clubunio-chat-replies-v1')) AS locked")->locked) throw new LogicException('Concurrent migration');
   $db->statement('LOCK TABLE clubora.migrations IN SHARE ROW EXCLUSIVE MODE NOWAIT');
   if((int)$db->selectOne("SELECT count(*) AS count FROM information_schema.columns WHERE table_schema='clubora' AND table_name='chat_messages' AND column_name='reply_to_message_id'")->count!==0
     ||$db->table('clubora.migrations')->where('migration',$name)->exists()) throw new LogicException('Expected fresh additive schema');
   $migration=require $root.'/database/clubunio/'.$name.'.php';$migration->up();
   $db->table('clubora.migrations')->insert(['migration'=>$name,'batch'=>(int)$db->table('clubora.migrations')->max('batch')+1]);
 });
 echo "Applied one additive nullable reply column and ledger entry atomically; existing message contents unchanged.\n";
}catch(Throwable $e){fwrite(STDERR,'Chat schema stopped: '.($e instanceof LogicException?$e->getMessage():get_class($e)).PHP_EOL);exit(1);}

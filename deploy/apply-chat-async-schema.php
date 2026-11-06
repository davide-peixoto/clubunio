<?php
// Exactly one additive DEV migration. Never migrate/refresh/import other schemas.
try {
 $root=dirname(__DIR__);
 $previous='/var/www/svs-laravel/releases/20261106-clubunio-chat-send-push-candidates';
 if(PHP_SAPI!=='cli'||$root!=='/var/www/svs-laravel/releases/20261106-clubunio-chat-async-send'
   ||realpath('/var/www/svs-laravel/current')!==$previous) throw new LogicException('Reviewed inactive candidate required');
 require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if(config('app.url')!=='https://dev.clubunio.ch') throw new LogicException('DEV required');
 $db=Illuminate\Support\Facades\DB::connection('clubunio');
 if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Reviewed database required');
 $name='2026_11_06_000200_mobile_chat_publications';
 $db->transaction(function()use($db,$root,$name){
   $db->statement("SET LOCAL lock_timeout='5s'");$db->statement("SET LOCAL statement_timeout='20s'");
   if(!$db->selectOne("SELECT pg_try_advisory_xact_lock(hashtext('clubunio-mobile-chat-publications-v1')) AS locked")->locked) throw new LogicException('Concurrent migration');
   $db->statement('LOCK TABLE clubora.migrations IN SHARE ROW EXCLUSIVE MODE NOWAIT');
   if($db->selectOne("SELECT to_regclass('clubora.mobile_chat_publications')::text AS name")->name!==null
     ||$db->table('clubora.migrations')->where('migration',$name)->exists()) throw new LogicException('Expected fresh additive schema');
   $migration=require $root.'/database/clubunio/'.$name.'.php';$migration->up();
   if($db->table('clubora.mobile_chat_publications')->exists()) throw new LogicException('Expected empty publication table');
   $db->table('clubora.migrations')->insert(['migration'=>$name,'batch'=>(int)$db->table('clubora.migrations')->max('batch')+1]);
 });
 echo "Applied one additive publication table and ledger entry atomically; existing rows unchanged.\n";
}catch(Throwable $e){fwrite(STDERR,'Chat schema stopped: '.($e instanceof LogicException?$e->getMessage():get_class($e)).PHP_EOL);exit(1);}

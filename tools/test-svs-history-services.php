<?php
declare(strict_types=1);
// Real application services, synthetic admin rights only inside an outer rollback.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(count($argv)!==2||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[1]))throw new LogicException('Rehearsal only');
$c=config('database.connections.clubunio');$c['database']=$argv[1];config(['database.connections.clubunio'=>$c,'logging.default'=>'null']);Illuminate\Support\Facades\DB::purge('clubunio');$db=Illuminate\Support\Facades\DB::connection('clubunio');App\ClubUnio\Services\SvsRehearsalTarget::authorize($db,$argv[1]);
$private=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));config(['app.key'=>$private['APP_KEY']]);$app->forgetInstance('encrypter');Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
Illuminate\Support\Facades\Http::preventStrayRequests();Illuminate\Support\Facades\Http::fake(fn()=>throw new LogicException('Unexpected HTTP in service guard test'));Illuminate\Support\Facades\Mail::fake();
$passed=[];$assert=function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed[$label]=true;};
$blocked=function(string $label,callable $action)use($assert){$ok=false;try{$action();}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$ok=$e->getStatusCode()===409;}$assert($ok,$label);};
$db->beginTransaction();
try{
 $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();$actor=new App\ClubUnio\Models\Account;$actor->forceFill(['username'=>'synthetic-history-'.bin2hex(random_bytes(8)).'@example.invalid','display_name'=>'Synthetic history tester','active'=>true,'password'=>''])->save();
 $db->table('clubunio.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'admin']);
 $series=App\ClubUnio\Models\DocumentSeries::where('tenant_id',$tenant->id)->firstOrFail();$recipient=App\ClubUnio\Models\DocumentSeriesRecipient::where('series_id',$series->id)->firstOrFail();
 $service=app(App\ClubUnio\Services\DocumentSeriesService::class);
 $assert($service->templates($actor,$tenant)->isEmpty(),'historical_templates_not_offered');
 $blocked('series_processing_blocked',fn()=>$service->process($actor,$tenant,$series->id));
 $blocked('series_download_blocked',fn()=>$service->download($actor,$tenant,$series->id,$recipient->id));
 $blocked('signed_return_publish_blocked',fn()=>$service->finishSignedReturn($actor,$tenant,$series->id,$recipient->id));
 $file=Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic.pdf',"%PDF-1.4\nSynthetic rollback-only file\n%%EOF");
 $blocked('signed_return_stage_blocked',fn()=>$service->signedReturn($actor,$tenant,$series->id,$recipient->id,$file,true,$recipient->original_sha256??str_repeat('0',64)));
 $template=App\ClubUnio\Models\DocumentSeriesTemplate::where('tenant_id',$tenant->id)->firstOrFail();
 $blocked('history_template_reuse_blocked',fn()=>$service->saveTemplate($actor,$tenant,$template->definition,$template->id));
 $blocked('history_preview_blocked',fn()=>$service->preview($actor,$tenant,['template_id'=>$template->id,'teams'=>[1],'target'=>'players','date'=>'2026-11-22','signature_required'=>true,'portal_visible'=>false]));
 $receipt=App\ClubUnio\Models\LegacyMaterialReceipt::where('tenant_id',$tenant->id)->firstOrFail();
 $blocked('unverified_receipt_download_blocked',fn()=>app(App\ClubUnio\Services\LegacyMaterialReceiptService::class)->pdf($actor,$tenant,$receipt->id,true));
 $plan=App\ClubUnio\Models\LegacyTrainingPlan::where('tenant_id',$tenant->id)->firstOrFail();
 $blocked('historical_training_import_blocked',fn()=>app(App\ClubUnio\Services\LegacyTrainingPlanImporter::class)->import($actor,$tenant,$plan->id,['revision'=>$plan->revision,'owner_id'=>$actor->id,'team_season_id'=>1,'decision_note'=>'Synthetic test','schematic_confirmed'=>true]));
 $request=App\ClubUnio\Models\MemberChangeRequest::where('tenant_id',$tenant->id)->firstOrFail();
 $blocked('historical_member_review_blocked',fn()=>app(App\ClubUnio\Services\MemberChangeService::class)->review($actor,$tenant,$request->id,$request->revision,true,'Synthetic test'));
 $normal=$service->saveTemplate($actor,$tenant,$template->definition);$assert(!$normal->legacy_read_only&&$normal->created_by===$actor->id&&$service->templates($actor,$tenant)->count()===1,'normal_template_creation_preserved');
 $blade=file_get_contents($root.'/resources/views/clubunio/document-series/page.blade.php');$start=strpos($blade,'    @if($seriesId)',strpos($blade,'{{ $this->table }}'));$fragment=substr($blade,$start,strrpos($blade,'</x-filament-panels::page>')-$start);
 foreach(['de','en'] as $locale){app()->setLocale($locale);$html=Illuminate\Support\Facades\Blade::render($fragment,['seriesId'=>$series->id,'recipients'=>$service->recipients($actor,$tenant,$series->id),'can'=>fn()=>true,'returnRecipient'=>null]);$assert(str_contains($html,e(__('svs.metadata_only')))&&!str_contains($html,'wire:click="processNext"')&&!str_contains($html,'wire:click="chooseReturn')&&!str_contains($html,e(__('Geprüfte PDF-Rückgabe')))&&!str_contains($html,e(__('Wiederholung erforderlich'))),'history_ui_metadata_only_'.$locale);}
 $assert(Illuminate\Support\Facades\Http::recorded()->isEmpty()&&Illuminate\Support\Facades\Mail::sent(Illuminate\Mail\Mailable::class)->isEmpty()&&Illuminate\Support\Facades\Mail::queued(Illuminate\Mail\Mailable::class)->isEmpty()&&!config('clubunio.outbound_enabled'),'no_outbound');
 echo json_encode(['status'=>'passed','passed'=>$passed,'all_fixtures_rolled_back'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Service guard test failed ['.get_class($e).']; private values suppressed.'.PHP_EOL);fwrite(STDERR,'At '.basename($e->getFile()).':'.$e->getLine().'; last assertion '.array_key_last($passed).PHP_EOL);if($e instanceof RuntimeException&&!($e instanceof Illuminate\Database\QueryException))fwrite(STDERR,$e->getMessage().PHP_EOL);if($e instanceof Illuminate\Validation\ValidationException)fwrite(STDERR,'Validation fields: '.implode(',',array_keys($e->errors())).PHP_EOL);exit(1);}
finally{$db->rollBack();}

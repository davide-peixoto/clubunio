<?php
declare(strict_types=1);

namespace App\ClubUnio\Services;

use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use LogicException;

/** CLI-only native projection merge. Neither source nor target application events run. */
final class SvsDeltaImporter
{
    private array $references=[];
    private array $incoming=[];
    private array $report=['inserted'=>0,'updated'=>0,'unchanged'=>0,'missing_review'=>0,'conflicts'=>0,'tables'=>[]];
    private string $key;

    public function __construct(private Connection $source,private Connection $target,private array $mapping,private Encrypter $sourceCrypt,private Encrypter $targetCrypt,private string $version)
    {
        $this->key=hash_hmac('sha256','svs-import-baseline-v1',$targetCrypt->getKey(),true);
        foreach($mapping as $table=>$contract)foreach($contract['fks'] as $fk){
            $parent=basename(str_replace('.','/',$fk['parent']));
            if(isset($mapping[$parent]))$this->incoming[$parent][json_encode($fk['referenced'])]=$fk['referenced'];
        }
        foreach(['document_types','members','countries','genders','billing_recipients','document_series_templates'] as $table)if(isset($mapping[$table]))$this->incoming[$table]['opaque-id']=['id'];
    }

    public static function decision(string $oldSource,string $newSource,string $oldTarget,string $currentTarget): string
    {
        if(hash_equals($oldSource,$newSource))return 'preserve';
        if(hash_equals($newSource,$currentTarget))return 'converged';
        return hash_equals($oldTarget,$currentTarget)?'apply':'conflict';
    }

    public function report(): array{return $this->report;}

    private function canonical(mixed $v): string
    {
        $sort=function($v)use(&$sort){if(is_array($v)){if(!array_is_list($v))ksort($v);return array_map($sort,$v);}return $v;};
        return json_encode($sort($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    }
    private function hash(mixed $v): string{return hash_hmac('sha256',$this->canonical($v),$this->key);}
    private function where(Connection $db,string $table,array $pk){$q=$db->table('clubunio.'.$table);foreach($pk as $k=>$v)$q->where($k,$v);return $q;}
    private function values(array $row,array $columns): array{$out=[];foreach($columns as $col){if(!array_key_exists($col,$row))throw new LogicException('Missing mapping field');$out[$col]=$row[$col];}return $out;}
    private function parent(string $name): string{return basename(str_replace('.','/',$name));}

    public function run(bool $commit,string $snapshot,string $expectedTarget,bool $upgradeV1=false): array
    {
        if(PHP_SAPI!=='cli'||(!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$expectedTarget)
                &&!($expectedTarget==='clubunio_prod'&&SvsProductionTarget::allows($this->target)))
            ||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$this->source->getDatabaseName())
            ||$this->source->getDriverName()!=='pgsql'||$this->target->getDriverName()!=='pgsql'
            ||$this->source->selectOne('select current_database() as n')->n!==$this->source->getDatabaseName()
            ||$this->target->selectOne('select current_database() as n')->n!==$expectedTarget
            ||$this->target->getDatabaseName()!==$expectedTarget)throw new LogicException('Only explicitly named isolated rehearsal databases are allowed');
        $this->references=[];
        $this->report=['inserted'=>0,'updated'=>0,'unchanged'=>0,'missing_review'=>0,'conflicts'=>0,'tables'=>[]];
        $ownsSource=$this->source->transactionLevel()===0;
        if($ownsSource){$this->source->beginTransaction();$this->source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');}
        try{
        $tenant=$this->source->table('clubunio.tenants')->sole();
        if($tenant->slug!=='sv-sissach')throw new LogicException('Only the SV Sissach projection is allowed');
        foreach($this->mapping as $table=>$contract){
            if(in_array('tenant_id',array_column($contract['columns'],'name'),true)&&$this->source->table('clubunio.'.$table)->where('tenant_id','<>',$tenant->id)->exists())throw new LogicException('Foreign source tenant');
        }
        $this->source->statement("SET timezone TO 'UTC'");$this->target->statement("SET timezone TO 'UTC'");
        $this->target->beginTransaction();
        try{
            $this->target->select('SELECT pg_advisory_xact_lock(2026112201)');
            $this->target->statement("SET LOCAL lock_timeout='5s'");
            $this->target->statement('CREATE SCHEMA IF NOT EXISTS migration_control');
            $this->target->statement('REVOKE ALL ON SCHEMA migration_control FROM PUBLIC');
            $this->target->unprepared('CREATE TABLE IF NOT EXISTS migration_control.svs_state (entity text NOT NULL, identity_hash char(64) NOT NULL, target_pk jsonb NOT NULL, baseline jsonb NOT NULL, mapping_version text NOT NULL, PRIMARY KEY(entity,identity_hash)); CREATE TABLE IF NOT EXISTS migration_control.svs_runs (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, source_hash char(64) NOT NULL, mapping_version text NOT NULL, result jsonb NOT NULL, completed_at timestamptz NOT NULL DEFAULT now()); REVOKE ALL ON ALL TABLES IN SCHEMA migration_control FROM PUBLIC');
            if($this->target->table('migration_control.svs_state')->where('mapping_version','<>',$this->version)->exists()){
                if(!$upgradeV1)throw new LogicException('Mapping upgrade requires explicit baseline migration');
                if(!hash_equals('4f1b5bc8ef70fe0f6840a8b0cb2967e363b6acdd0b4da68203245ff18fba3b02',hash_file('sha256',base_path('deploy/svs-import-mapping-v1.json'))))throw new LogicException('Unrecognized v1 mapping contract');
                $v1=json_decode(file_get_contents(base_path('deploy/svs-import-mapping-v1.json')),true,512,JSON_THROW_ON_ERROR);
                $known='36bff90ccdb89434e8372968241fb1354d3acc46a5539cba47167200a0792b3c';
                foreach($v1 as $t=>$c){
                    // Preserve the existing field, identity and reference contracts verbatim.
                    if(!isset($this->mapping[$t])||$c['pk']!==$this->mapping[$t]['pk']||$c['identity']!==$this->mapping[$t]['identity']||$c['fks']!==$this->mapping[$t]['fks'])throw new LogicException('Incompatible v1 identity/reference contract');
                    foreach($c['columns'] as $column)if(!in_array($column,$this->mapping[$t]['columns'],true))throw new LogicException('Incompatible v1 field contract');
                }
                foreach($this->target->table('migration_control.svs_state')->get() as $state){
                    if($state->mapping_version!==$known||!isset($v1[$state->entity]))throw new LogicException('Unknown baseline version');
                }
                $this->target->table('migration_control.svs_state')->update(['mapping_version'=>$this->version]);
                $this->report['baseline_upgraded']=true;
            }
            if(!$this->target->table('migration_control.svs_state')->exists()&&$this->target->table('clubunio.tenants')->where('slug','sv-sissach')->exists())throw new LogicException('Existing tenant requires reviewed adoption, not automatic merge');
            $pending=array_keys($this->mapping);$done=[];
            while($pending){
                $ready=array_values(array_filter($pending,fn($t)=>!array_diff($this->mapping[$t]['after']??[],$done)&&!array_filter($this->mapping[$t]['fks'],fn($fk)=>isset($this->mapping[$this->parent($fk['parent'])])&&$this->parent($fk['parent'])!==$t&&!in_array($this->parent($fk['parent']),$done,true))));
                if(!$ready)throw new LogicException('Unresolved mapping dependency cycle');
                foreach($ready as $table){$this->table($table);$done[]=$table;}
                $pending=array_values(array_diff($pending,$ready));
            }
            if($this->report['conflicts']>0)throw new LogicException('Source/target conflicts; entire run rolled back');
            $this->target->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->target->table('migration_control.svs_runs')->insert(['source_hash'=>$snapshot,'mapping_version'=>$this->version,'result'=>json_encode($this->report,JSON_THROW_ON_ERROR)]);
            if($commit)$this->target->commit();else $this->target->rollBack();
            return $this->report+['committed'=>$commit,'mapping_version'=>$this->version];
        }catch(\Throwable $e){$this->target->rollBack();throw $e;}
        }finally{if($ownsSource)$this->source->rollBack();}
    }

    private function table(string $table): void
    {
        $contract=$this->mapping[$table];
        if(!$contract['identity'])throw new LogicException('No reviewed stable identity');
        $rows=$this->source->table('clubunio.'.$table)->orderBy($contract['pk'][0])->get();
        $states=[];
        foreach($this->target->table('migration_control.svs_state')->where('entity',$table)->get() as $s)$states[$s->identity_hash]=$s;
        $seen=[];$counts=['inserted'=>0,'updated'=>0,'unchanged'=>0,'missing_review'=>0];
        foreach($rows as $object){
            $original=(array)$object;$desired=$original;
            foreach($contract['fks'] as $fk){
                if(array_filter($fk['columns'],fn($c)=>$original[$c]===null))continue;
                $parent=$this->parent($fk['parent']);
                if(!isset($this->mapping[$parent]))throw new LogicException('Unmapped nonnull reference');
                $lookup=array_combine($fk['referenced'],array_values($this->values($original,$fk['columns'])));
                $ref=$this->references[$parent][$this->hash($lookup)]??null;
                if(!$ref)throw new LogicException('Unresolved native reference');
                foreach($fk['columns'] as $i=>$col)$desired[$col]=$ref[$fk['referenced'][$i]];
            }
            $desired=$this->rebase($table,$desired);
            $identity=$this->values($desired,$contract['identity']);
            if(in_array(null,$identity,true))throw new LogicException('Null source identity');
            $id=$this->hash($identity);
            if(isset($seen[$id]))throw new LogicException('Duplicate mapped source identity');$seen[$id]=true;
            $state=$states[$id]??null;
            $pk=$state?json_decode($state->target_pk,true,512,JSON_THROW_ON_ERROR):null;
            $current=$pk?(array)$this->where($this->target,$table,$pk)->lockForUpdate()->sole():null;
            if($current&&isset($desired['tenant_id'])&&(string)$current['tenant_id']!==(string)$desired['tenant_id'])throw new LogicException('Foreign target tenant in identity ledger');
            if($table==='tenants'&&$current&&$current['slug']!=='sv-sissach')throw new LogicException('Foreign target tenant');
            $old=$state?json_decode($state->baseline,true,512,JSON_THROW_ON_ERROR):[];
            $base=[];$changes=[];
            foreach($contract['columns'] as $column){
                $name=$column['name'];
                if(!array_key_exists($name,$desired))throw new LogicException('Projection schema drift');
                $encrypted=str_ends_with($name,'_encrypted')||$name==='encrypted_value'||$name==='snapshot';
                if($encrypted&&$desired[$name]!==null&&$desired[$name]!==''){
                    // rebase() has already encrypted opaque snapshots with the target key.
                    if($name!=='snapshot'&&$name!=='snapshot_encrypted'){$plain=$this->sourceCrypt->decryptString($desired[$name]);$desired[$name]=$this->targetCrypt->encryptString($plain);}
                }
                $value=$desired[$name];
                if($column['identity']==='YES'||($name==='id'&&in_array($name,$contract['pk'],true))){unset($desired[$name]);continue;}
                if(in_array($name,['created_at','updated_at','revision','password'],true)||in_array($name,$contract['pk'],true))continue;
                if($table==='accounts'&&in_array($name,['active','api_only'],true))continue;
                if($column['type']==='jsonb'||$column['type']==='json')$value=$value===null?null:json_decode($value,true,512,JSON_THROW_ON_ERROR);
                if($encrypted&&$value!==null&&$value!=='')$value=$this->targetCrypt->decryptString($value);
                $newHash=$this->hash($value);
                if(!$current){$base[$name]=[$newHash,$newHash];continue;}
                if(!array_key_exists($name,$current)||!isset($old[$name]))throw new LogicException('Target schema or field baseline drift');
                $targetValue=$current[$name];
                if($column['type']==='jsonb'||$column['type']==='json')$targetValue=$targetValue===null?null:json_decode($targetValue,true,512,JSON_THROW_ON_ERROR);
                if($encrypted&&$targetValue!==null&&$targetValue!=='')$targetValue=$this->targetCrypt->decryptString($targetValue);
                $targetHash=$this->hash($targetValue);
                $decision=self::decision($old[$name][0],$newHash,$old[$name][1],$targetHash);
                if($decision==='conflict'){$this->report['conflicts']++;continue;}
                if($decision==='apply')$changes[$name]=$desired[$name];
                // Preserve the last applied target hash for local-only edits.
                $base[$name]=$decision==='preserve'?$old[$name]:[$newHash,$newHash];
            }
            if(!$current){
                if($table==='accounts'){
                    if($this->target->table('clubunio.accounts')->whereRaw('lower(username)=?',[mb_strtolower($desired['username'])])->exists())throw new LogicException('Existing account collision requires explicit operator decision');
                    $desired['active']=false;$desired['password']='';$desired['api_only']=false;
                }
                $names=implode(',',array_map(fn($c)=>'"'.str_replace('"','""',$c).'"',array_keys($desired)));
                $result=$this->target->selectOne('INSERT INTO clubunio."'.$table.'" ('.$names.') VALUES ('.implode(',',array_fill(0,count($desired),'?')).') RETURNING *',array_values($desired));
                $current=(array)$result;$pk=$this->values($current,$contract['pk']);$counts['inserted']++;
            }elseif($changes){
                if(array_key_exists('revision',$current)&&!in_array('revision',$contract['identity'],true))$changes['revision']=(int)$current['revision']+1;
                if(array_key_exists('updated_at',$current))$changes['updated_at']=$this->target->selectOne('select current_timestamp as value')->value;
                $this->where($this->target,$table,$pk)->update($changes);$current=array_replace($current,$changes);$counts['updated']++;
            }else{$counts['unchanged']++;}
            // Hash the actually persisted representation (numeric/date normalization).
            if(!$state){
                foreach($base as $name=>&$pair){
                    $column=current(array_filter($contract['columns'],fn($c)=>$c['name']===$name));$v=$current[$name];
                    if(in_array($column['type'],['json','jsonb'],true)&&$v!==null)$v=json_decode($v,true,512,JSON_THROW_ON_ERROR);
                    if((str_ends_with($name,'_encrypted')||$name==='encrypted_value'||$name==='snapshot')&&$v!==null&&$v!=='')$v=$this->targetCrypt->decryptString($v);
                    $pair[1]=$this->hash($v);
                }unset($pair);
            }
            $encoded=json_encode($base,JSON_THROW_ON_ERROR);
            if(!$state)$this->target->table('migration_control.svs_state')->insert(['entity'=>$table,'identity_hash'=>$id,'target_pk'=>json_encode($pk,JSON_THROW_ON_ERROR),'baseline'=>$encoded,'mapping_version'=>$this->version]);
            elseif($base!==$old)$this->target->table('migration_control.svs_state')->where('entity',$table)->where('identity_hash',$id)->update(['baseline'=>$encoded]);
            foreach($this->incoming[$table]??[] as $keyColumns){$this->references[$table][$this->hash($this->values($original,$keyColumns))]=$current;}
        }
        $counts['missing_review']=count(array_diff(array_keys($states),array_keys($seen)));
        foreach($counts as $k=>$v)$this->report[$k]+=$v;
        $this->report['tables'][$table]=$counts;
    }

    private function native(string $table,mixed $id): mixed
    {
        if($id===null)return null;
        return ($this->references[$table][$this->hash(['id'=>(int)$id])]??throw new LogicException('Unresolved embedded native reference'))['id'];
    }
    private function rebase(string $table,array $row): array
    {
        if($table==='document_series_templates'){
            $d=json_decode($row['definition'],true,512,JSON_THROW_ON_ERROR);$d['type_id']=$this->native('document_types',$d['type_id']);
            $row['definition']=json_encode($d,JSON_THROW_ON_ERROR);$row['definition_hash']=DocumentSeriesRenderer::hash($d);
        }
        if(in_array($table,['document_series','document_series_recipients','member_change_requests'],true)){
            $column=$table==='member_change_requests'?'snapshot_encrypted':'snapshot';
            $d=json_decode($this->sourceCrypt->decryptString($row[$column]),true,512,JSON_THROW_ON_ERROR);
            if($table==='document_series'){
                $d['template_id']=$this->native('document_series_templates',$d['template_id']);
                $d['template_hash']=$this->target->table('clubunio.document_series_templates')->where('id',$d['template_id'])->sole()->definition_hash;
            }elseif($table==='document_series_recipients'){
                $d['member_id']=$this->native('members',$d['member_id']);$d['definition']['type_id']=$this->native('document_types',$d['definition']['type_id']);
            }else foreach(['before','changes'] as $section)foreach(['country_id'=>'countries','nationality_id'=>'countries','gender_id'=>'genders','billing_recipient_id'=>'billing_recipients'] as $field=>$parent)if(isset($d[$section][$field])&&$d[$section][$field]!=='')$d[$section][$field]=$this->native($parent,$d[$section][$field]);
            $row[$column]=$this->targetCrypt->encryptString(json_encode($d,JSON_THROW_ON_ERROR));
            if(isset($row['snapshot_hash']))$row['snapshot_hash']=DocumentSeriesRenderer::hash($d);
        }
        return $row;
    }
}

<?php
declare(strict_types=1);
namespace App\ClubUnio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/** Explicit legacy business mappings. No events, signatures, approvals or deliveries. */
final class SvsSupplementImporter
{
    private int $tenant;
    public function __construct(private Connection $db) {}
    private function ref(string $table,mixed $legacy): ?int
    {
        if($legacy===null||$legacy==='')return null;
        return (int)$this->db->table('clubunio.'.$table)->where('tenant_id',$this->tenant)->where('legacy_id',$legacy)->sole()->id;
    }
    private function actor(mixed $username): ?int
    {
        if(!is_string($username)||trim($username)==='')return null;
        $rows=$this->db->table('clubunio.accounts')->whereRaw('lower(username)=?',[mb_strtolower(trim($username))])->get();
        return count($rows)===1?(int)$rows[0]->id:null;
    }
    private function time(mixed $value): ?string{return $value===null||$value===''?null:CarbonImmutable::parse((string)$value,'Europe/Zurich')->utc()->toISOString();}
    private function data(object $row): array{$v=(array)$row;unset($v['_migration_row']);return $v;}
    private function hash(array $value): string{return DocumentSeriesRenderer::hash($value);}
    private function provenance(string $source,object $row,string $key,bool $readOnly=true): array
    {
        $data=$this->data($row);
        return ['legacy_source_table'=>$source,'legacy_source_id'=>(int)$data[$key],'legacy_source_hash'=>$this->hash($data),
            'legacy_payload_encrypted'=>Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)), 'legacy_read_only'=>$readOnly];
    }
    private function insert(string $table,array $data): int{return (int)$this->db->table('clubunio.'.$table)->insertGetId(['tenant_id'=>$this->tenant]+$data);}
    private function definition(array $source,?int $legacyType=null): array
    {
        if($legacyType!==null)$type=$this->ref('document_types',$legacyType);
        else{
            $code=(string)$source['DokumentTyp'];
            $types=$this->db->table('clubunio.document_types')->where('tenant_id',$this->tenant)->where(fn($q)=>$q->whereRaw('lower(code)=?',[mb_strtolower($code)])->orWhereRaw('lower(name)=?',[mb_strtolower($code)]))->get();
            if(count($types)!==1)throw new LogicException('Unresolved exact document type');$type=$types[0]->id;
        }
        $items=[];
        foreach($source['Positionen']??[] as $p)$items[]=['description'=>$p['Artikel'],'reference'=>$p['Artikelnummer']??'',
            'quantity'=>$p['Menge'],'price_cents'=>$p['PreisRappen'],'size'=>$p['Groesse']??'', 'initials'=>$p['Kuerzel']??'','brand'=>$p['Marke']??''];
        return app(DocumentSeriesRenderer::class)->definition(['name'=>$source['Name'],'title'=>$source['Titel'],'text'=>$source['Text'],
            'type_id'=>(int)$type,'signature_required'=>(bool)$source['UnterschriftNotwendig'],'items'=>$items]);
    }
    private function template(array $definition,array $provenance,?int $author,int $version,string $date): int
    {
        return $this->insert('document_series_templates',$provenance+['created_by'=>$author,
            'family_key'=>'legacy-'.substr($provenance['legacy_source_hash'],0,48),'version'=>max(1,$version),'name'=>$definition['name'],
            'definition'=>json_encode($definition,JSON_THROW_ON_ERROR),'definition_hash'=>$this->hash($definition),'created_at'=>$date,'updated_at'=>$date]);
    }
    public function run(): array
    {
        if(!StageMigrationTarget::allows($this->db))throw new LogicException('Disposable projection only');
        $this->tenant=(int)$this->db->table('clubunio.tenants')->where('slug','sv-sissach')->sole()->id;
        $counts=[];
        foreach($this->db->table('legacy_prod.AppAnkuendigungen')->orderBy('AnkuendigungID')->get() as $r){
            $this->insert('mobile_app_announcements',$this->provenance('AppAnkuendigungen',$r,'AnkuendigungID')+[
                'title'=>$r->Betreff,'body'=>$r->Meldung,'audience'=>'members','active'=>false,'starts_at'=>$this->time($r->GueltigVon),
                'ends_at'=>$this->time($r->GueltigBis),'created_by'=>null,'updated_by'=>null,'created_at'=>$this->time($r->ErstelltAm),'updated_at'=>$this->time($r->ErstelltAm)]);
        }
        foreach($this->db->table('legacy_prod.DokumentVorlagen')->orderBy('VorlageID')->get() as $r){
            $d=$this->definition(json_decode($r->Definition,true,512,JSON_THROW_ON_ERROR));
            // No known original creator: the source modifier stays in encrypted provenance.
            $this->template($d,$this->provenance('DokumentVorlagen',$r,'VorlageID'),null,(int)$r->Revision,$this->time($r->CreatedAt));
        }
        $series=[];$seriesDefinitions=[];$seriesHashes=[];
        foreach($this->db->table('legacy_prod.DokumentSerien')->orderBy('SerieID')->get() as $r){
            $raw=json_decode($r->Snapshot,true,512,JSON_THROW_ON_ERROR);$d=$this->definition($raw['Definition'],(int)$raw['DokumentTypID']);
            // Freeze the definition captured at issue time, never today's editable template.
            $provenance=$this->provenance('DokumentSerien',$r,'SerieID');
            $template=$this->template($d,$provenance,$this->actor($r->CreatedBy),1,$this->time($r->CreatedAt));
            $snapshot=['template_id'=>$template,'template_hash'=>$this->hash($d),'legacy_read_only'=>true];
            $series[$r->SerieID]=$this->insert('document_series',$provenance+['created_by'=>$this->actor($r->CreatedBy),'template_id'=>$template,
                'request_key'=>'legacy-'.$r->SerieID.'-'.substr($provenance['legacy_source_hash'],0,32),'request_hash'=>$provenance['legacy_source_hash'],
                'snapshot'=>Crypt::encryptString(json_encode($snapshot,JSON_THROW_ON_ERROR)),'snapshot_hash'=>$this->hash($snapshot),'created_at'=>$this->time($r->CreatedAt),'updated_at'=>$this->time($r->CreatedAt)]);
            $seriesDefinitions[$r->SerieID]=$d;
            $seriesHashes[$r->SerieID]=$provenance['legacy_source_hash'];
        }
        $receipts=[];
        foreach($this->db->table('legacy_prod.MitgliederQuittungen')->orderBy('QuittungID')->get() as $r){
            $original=$this->ref('member_documents',$r->OriginalAnhangID);$signed=$this->ref('member_documents',$r->SigniertAnhangID);
            foreach([$r->OriginalSha256,$r->SigniertSha256] as $h)if($h!==null&&$h!==''&&!preg_match('/\A[a-f0-9]{64}\z/i',$h))throw new LogicException('Invalid source document checksum');
            $receipts[$r->QuittungID]=$this->insert('legacy_material_receipts',$this->provenance('MitgliederQuittungen',$r,'QuittungID')+[
                'member_id'=>$this->ref('members',$r->MitgliederID),'source_id'=>$r->QuittungID,'source_hash'=>$this->hash($this->data($r)),
                'title'=>$r->Titel,'source_state'=>$r->Status,'signed_name'=>$r->SigniertName,'signed_at'=>$this->time($r->SigniertAm),
                'original_document_id'=>$original,'signed_document_id'=>$signed,'original_sha256'=>strtolower($r->OriginalSha256),
                'signed_sha256'=>$r->SigniertSha256?strtolower($r->SigniertSha256):null,'imported_by'=>null,'integrity_state'=>'source_metadata_only',
                'created_at'=>$this->time($r->ErstelltAm),'updated_at'=>$this->time($r->ErstelltAm)]);
        }
        foreach($this->db->table('legacy_prod.DokumentSerienEmpfaenger')->orderBy('EmpfaengerID')->get() as $r){
            $raw=json_decode($r->Snapshot,true,512,JSON_THROW_ON_ERROR);$values=$raw['Werte'];
            $d=app(DocumentSeriesRenderer::class)->personalize($seriesDefinitions[$r->SerieID],$values);
            $snapshot=['member_id'=>$this->ref('members',$r->MitgliederID),'member_name'=>trim(($values['Mitglied.Vorname']??'').' '.($values['Mitglied.Nachname']??'')),
                'club'=>'SV Sissach','definition'=>$d,'values'=>$values,'date'=>$values['Dokument.Datum']??null,'portal_visible'=>false,'legacy_read_only'=>true];
            $receipt=$r->QuittungID?$this->db->table('clubunio.legacy_material_receipts')->where('id',$receipts[$r->QuittungID])->sole():null;
            $provenance=$this->provenance('DokumentSerienEmpfaenger',$r,'EmpfaengerID');
            $provenance['legacy_source_hash']=$this->hash(['source'=>$this->data($r),'series_source_hash'=>$seriesHashes[$r->SerieID],'receipt_source_hash'=>$receipt?->legacy_source_hash]);
            $this->insert('document_series_recipients',$provenance+[
                'series_id'=>$series[$r->SerieID],'member_id'=>$this->ref('members',$r->MitgliederID),
                'snapshot'=>Crypt::encryptString(json_encode($snapshot,JSON_THROW_ON_ERROR)),'snapshot_hash'=>$this->hash($snapshot),
                'legacy_receipt_id'=>$receipt?->id,'document_id'=>$receipt?->original_document_id,'signed_document_id'=>$receipt?->signed_document_id,
                'original_sha256'=>$receipt?->original_sha256,'signed_sha256'=>$receipt?->signed_sha256,
                'last_error'=>'legacy_metadata_only']);
        }
        // FIELDS labels are UI labels; legacy keys use the exact original database names.
        $fields=['Vorname'=>'first_name','Nachname'=>'last_name','Geburtsdatum'=>'birth_date','Email'=>'email','Strasse'=>'street','HausNr'=>'house_number','PLZ'=>'postal_code','Ort'=>'city','Land'=>'country_id','Nationalitaet'=>'nationality_id','Geschlecht'=>'gender_id','TelefonPrivat'=>'phone_private','TelefonGeschaeft'=>'phone_business','Mobile'=>'mobile','Mutter'=>'mother_name','EmailMutter'=>'mother_email','MobileMutter'=>'mother_mobile','Vater'=>'father_name','EmailVater'=>'father_email','MobileVater'=>'father_mobile','RechnungAn'=>'billing_recipient_id'];
        foreach($this->db->table('legacy_prod.MitgliederAenderungsantraege')->orderBy('AenderungsantragID')->get() as $r){
            $before=[];$changes=[];$sourceOld=json_decode($r->AltwerteJson,true,512,JSON_THROW_ON_ERROR);$sourceNew=json_decode($r->NeuwerteJson,true,512,JSON_THROW_ON_ERROR);
            foreach(json_decode($r->AenderungenJson,true,512,JSON_THROW_ON_ERROR) as $change){
                $field=$fields[$change['field']]??throw new LogicException('Unknown member change field');
                $before[$field]=$this->lookupValue($field,$sourceOld[$change['field']]??$change['old']);
                $changes[$field]=$this->lookupValue($field,$sourceNew[$change['field']]??$change['new']);
            }
            $state=match($r->Status){'GENEHMIGT'=>'approved','ABGELEHNT'=>'rejected','ZURUECKGEZOGEN'=>'withdrawn','OFFEN','PENDING'=>'pending',default=>throw new LogicException('Unknown legacy request state')};
            $this->insert('member_change_requests',$this->provenance('MitgliederAenderungsantraege',$r,'AenderungsantragID')+[
                'member_id'=>$this->ref('members',$r->MitgliederID),'requested_by'=>$this->actor($r->AntragstellerUsername),'reviewed_by'=>$this->actor($r->EntscheidVon),
                'status_id'=>$this->db->table('clubunio.member_change_statuses')->where('tenant_id',$this->tenant)->where('code',$state)->sole()->id,
                'state'=>$state,'snapshot_encrypted'=>Crypt::encryptString(json_encode(['before'=>$before,'changes'=>$changes],JSON_THROW_ON_ERROR)),
                'member_version'=>$r->DatensatzVersion,'created_at'=>$this->time($r->CreatedAt),'updated_at'=>$this->time($r->UpdatedAt),
                'reviewed_at'=>$this->time($r->EntscheidAm),'decision_note'=>$r->EntscheidBegruendung]);
        }
        foreach($this->db->table('legacy_prod.TeamTerminGaeste')->orderBy('GastID')->get() as $r)$this->insert('team_guests',$this->provenance('TeamTerminGaeste',$r,'GastID',false)+[
            'event_id'=>$this->ref('team_events',$r->TeamTerminID),'first_name'=>$r->Vorname,'last_name'=>$r->Nachname,'status'=>$r->TeilnahmeStatus,
            'attendance'=>$r->AnwesenheitsStatus,'comment_encrypted'=>Crypt::encryptString((string)$r->Kommentar),
            'attendance_comment_encrypted'=>Crypt::encryptString((string)$r->AnwesenheitsKommentar),'deleted_at'=>$this->time($r->sdeletedAt),'updated_at'=>$this->time($r->UpdatedAt)]);
        foreach($this->db->table('legacy_prod.Verbandsspiele')->orderBy('matchId')->get() as $r)$this->insert('sfv_schedule',$this->provenance('Verbandsspiele',$r,'matchId',false)+[
            'match_id'=>$r->matchId,'season_id'=>$r->seasonId,'team_a_id'=>$r->teamAId,'team_b_id'=>$r->teamBId,'title'=>$r->teamNameA.' - '.$r->teamNameB,
            'starts_at'=>$this->time($r->matchDate),'location'=>$r->stadiumPlaygroundName,'state'=>$r->matchState,'match_type'=>$r->matchType,
            'home'=>(bool)$r->IsHomeGame,'host_name'=>$r->teamNameA,'guest_name'=>$r->teamNameB,'club_team_season_id'=>$this->ref('team_seasons',$r->TeamSaisonID),
            'updated_at'=>$this->time($r->LastSyncAt)]);
        foreach($this->db->table('legacy_prod.TrainingPlannerAuditLog')->orderBy('TrainingPlannerAuditLogID')->get() as $r){
            $converted=app(LegacyTrainingPlanImporter::class)->convert($r);
            foreach(['input_json','plan_json'] as $field)if(isset($converted[$field]))$converted[$field]=json_encode($converted[$field],JSON_THROW_ON_ERROR);
            $this->insert('legacy_training_plans',$this->provenance('TrainingPlannerAuditLog',$r,'TrainingPlannerAuditLogID')+$converted+[
                'source_id'=>$r->TrainingPlannerAuditLogID,'source_hash'=>$this->hash($this->data($r)),'source_status'=>$r->Status,'source_username'=>$r->Username,
                'source_team_name'=>$r->TeamName,'suggested_owner_id'=>$this->actor($r->Username),'suggested_team_season_id'=>$this->ref('team_seasons',$r->SharedTeamSaisonID),
                'created_at'=>$this->time($r->CreatedAt),'updated_at'=>$this->time($r->UpdatedAt??$r->CreatedAt)]);
        }
        $this->expenses();
        foreach(['mobile_app_announcements','document_series_templates','document_series','document_series_recipients','legacy_material_receipts','member_change_requests','team_guests','sfv_schedule','legacy_training_plans','svs_expense_season_reviews'] as $t)$counts[$t]=$this->db->table('clubunio.'.$t)->where('tenant_id',$this->tenant)->count();
        return $counts;
    }
    private function lookupValue(string $field,mixed $value): mixed
    {
        if($value===null||$value==='')return $value;
        $lookup=match($field){'country_id'=>['countries','code'],'nationality_id'=>['countries','iso3'],'gender_id'=>['genders','code'],'billing_recipient_id'=>['billing_recipients','code'],default=>null};
        if(!$lookup)return $value;
        $rows=$this->db->table('clubunio.'.$lookup[0])->where('tenant_id',$this->tenant)->where($lookup[1],$value)->get();
        if(count($rows)!==1)throw new LogicException('Unresolved historical member lookup');return (int)$rows[0]->id;
    }
    private function expenses(): void
    {
        $rules=json_decode(file_get_contents(base_path('deploy/svs-expense-season-rules-v1.json')),true,512,JSON_THROW_ON_ERROR);
        $bad=$this->db->select('select e.* from clubunio.expenses e join clubunio.team_seasons ts on ts.tenant_id=e.tenant_id and ts.id=e.team_season_id where e.tenant_id=? and e.season_id<>ts.season_id',[$this->tenant]);
        foreach($bad as $expense){
            $r=$this->db->table('legacy_prod.Spesenbelege')->where('SpesenID',$expense->legacy_id)->sole();
            $rule=$rules[(string)$r->SpesenID]??throw new LogicException('Unreviewed expense mismatch');
            $old=$this->db->table('legacy_prod.TeamsSaison')->where('TeamSaisonID',$r->TeamSaisonID)->sole();
            $new=$this->db->table('legacy_prod.TeamsSaison')->where('TeamID',$old->TeamID)->where('SaisonID',$r->SaisonID)->whereNull('sdeletedAt')->sole();
            if([(int)$r->SaisonID,(int)$r->TeamSaisonID,(int)$old->TeamID,(int)$new->TeamSaisonID]!==$rule)throw new LogicException('Reviewed expense mapping no longer matches source');
            $target=$this->ref('team_seasons',$new->TeamSaisonID);
            $this->db->table('clubunio.expenses')->where('id',$expense->id)->where('tenant_id',$this->tenant)->update(['team_season_id'=>$target]);
            $this->insert('svs_expense_season_reviews',['expense_id'=>$expense->id,'source_expense_id'=>$r->SpesenID,'source_season_id'=>$r->SaisonID,
                'source_assignment_id'=>$r->TeamSaisonID,'source_team_id'=>$old->TeamID,'target_assignment_legacy_id'=>$new->TeamSaisonID,
                'source_hash'=>$this->hash($this->data($r)),'rule_version'=>'svs-season-v1','decision'=>'unique_same_team_same_receipt_season']);
        }
    }
}

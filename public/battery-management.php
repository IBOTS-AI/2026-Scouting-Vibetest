<?php
declare(strict_types=1);
function batteryMetricFields(): array {
 return ['resting_voltage'=>['Resting voltage','V',0,100,'0.01'],'loaded_voltage'=>['Loaded voltage','V',0,100,'0.01'],'test_current'=>['Test current','A',0,1000,'0.1'],'resistance'=>['Internal resistance','mΩ',0,1000,'0.01'],'capacity'=>['Measured capacity','Ah',0,1000,'0.01'],'charge_percent'=>['State of charge','%',0,100,'0.1'],'temperature_c'=>['Battery temperature','°C',-50,150,'0.1'],'cycle_count'=>['Cycle count','cycles',0,100000,'1']];
}
function batteryCheckTypes(): array {return ['Inspection','Load test','Capacity test','Robot use','Charging','Other'];}
function batteryConditions(): array {return ['Not assessed','OK','Monitor','Needs attention'];}
function batteryManager(): bool {return role('admin','mentor');}
function batteryText(array $data,string $key,int $max,bool $required=false): string {
 $value=$data[$key]??'';if(!is_string($value)||strlen($value)>$max||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$key).'.');
 $value=trim($value);if($required&&$value==='')throw new InvalidArgumentException('Enter '.str_replace('_',' ',$key).'.');return $value;
}
function batteryDate(array $data,string $key,bool $required=false): ?string {
 $value=batteryText($data,$key,10,$required);if($value==='')return null;
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||(int)$date->format('Y')<1||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$key).'.');return $value;
}
function batteryNumber($value,string $label,float $min,float $max,bool $integer=false): ?float {
 if($value===''||$value===null)return null;
 if(!is_string($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<$min||(float)$value>$max||($integer&&floor((float)$value)!==(float)$value))throw new InvalidArgumentException('Enter a valid '.$label.' between '.$min.' and '.$max.'.');
 return (float)$value;
}
function validateBattery(array $post): array {
 $data=[];foreach(['label'=>80,'brand'=>100,'model'=>100,'serial_number'=>120,'notes'=>10000] as $key=>$max)$data[$key]=batteryText($post,$key,$max,$key==='label');
 foreach(['purchased_on','in_service_on'] as $key)$data[$key]=batteryDate($post,$key);
 $data['rated_ah']=batteryNumber($post['rated_ah']??'','rated capacity',0.01,1000);return $data;
}
function validateBatteryCheck(array $post): array {
 $data=['recorded_on'=>batteryDate($post,'recorded_on',true),'check_type'=>batteryText($post,'check_type',40,true),'condition'=>batteryText($post,'condition',40,true),'match_ref'=>batteryText($post,'match_ref',80),'notes'=>batteryText($post,'notes',10000)];
 if(!in_array($data['check_type'],batteryCheckTypes(),true)||!in_array($data['condition'],batteryConditions(),true))throw new InvalidArgumentException('Choose a valid check type and condition.');
 $raw=$post['readings']??[];if(!is_array($raw)||array_diff(array_keys($raw),array_keys(batteryMetricFields())))throw new InvalidArgumentException('Invalid battery readings.');
 $data['readings']=[];foreach(batteryMetricFields() as $key=>[$label,$unit,$min,$max]){$v=batteryNumber($raw[$key]??'',$label,$min,$max,$key==='cycle_count');if($v!==null)$data['readings'][$key]=$v;}
 if(!$data['readings']&&$data['notes']==='')throw new InvalidArgumentException('Enter at least one reading or a note.');
 return $data;
}
function batteryId($id): string {if(!is_string($id)||!preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i',$id))throw new InvalidArgumentException('Choose a valid battery or record.');return $id;}
function batteryAudit(string $type,string $id,string $uid,$before,$after): void {
 query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),$type,$id,$uid,$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
}
function batteryPost(string $action,string $uid): void {
 $batteryId='';
 try{
  if(!in_array($action,['battery_save','battery_status','battery_check'],true)){http_response_code(403);exit('Forbidden');}
  if($action!=='battery_check'&&!batteryManager()){http_response_code(403);exit('Admins and mentors manage battery inventory.');}
  if($action==='battery_save'){
   $batteryId=($_POST['id']??'')!==''?batteryId($_POST['id']):'';$data=validateBattery($_POST);
   db()->beginTransaction();$old=$batteryId?query('SELECT * FROM batteries WHERE id=? FOR UPDATE',[$batteryId])->fetch(PDO::FETCH_ASSOC):false;
   if($batteryId&&!$old)throw new InvalidArgumentException('Battery not found.');
   if($old&&($_POST['version']??'')!==$old['updated_at'])throw new InvalidArgumentException('This battery changed. Reload before saving your edits.');
   if(query('SELECT id FROM batteries WHERE lower(label)=lower(?) AND id<>?::uuid',[$data['label'],$batteryId?:'00000000-0000-0000-0000-000000000000'])->fetchColumn())throw new InvalidArgumentException('That battery label is already in use, including retired batteries.');
   $batteryId=$batteryId?:uuid();
   query('INSERT INTO batteries(id,label,brand,model,serial_number,purchased_on,in_service_on,rated_ah,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET label=EXCLUDED.label,brand=EXCLUDED.brand,model=EXCLUDED.model,serial_number=EXCLUDED.serial_number,purchased_on=EXCLUDED.purchased_on,in_service_on=EXCLUDED.in_service_on,rated_ah=EXCLUDED.rated_ah,notes=EXCLUDED.notes,updated_at=now()',[$batteryId,$data['label'],$data['brand'],$data['model'],$data['serial_number'],$data['purchased_on'],$data['in_service_on'],$data['rated_ah'],$data['notes'],$uid]);
   batteryAudit('battery',$batteryId,$uid,$old?:null,query('SELECT * FROM batteries WHERE id=?',[$batteryId])->fetch(PDO::FETCH_ASSOC));
   db()->commit();$_SESSION['flash']='Battery saved.';
  }elseif($action==='battery_status'){
   $batteryId=batteryId($_POST['id']??'');$target=batteryText($_POST,'status',10,true);if(!in_array($target,['active','retired'],true))throw new InvalidArgumentException('Invalid battery status.');
   $reason=batteryText($_POST,'retirement_note',1000,$target==='retired');
   db()->beginTransaction();$old=query('SELECT * FROM batteries WHERE id=? FOR UPDATE',[$batteryId])->fetch(PDO::FETCH_ASSOC);if(!$old)throw new InvalidArgumentException('Battery not found.');
   if(($_POST['version']??'')!==$old['updated_at'])throw new InvalidArgumentException('This battery changed. Reload before changing its status.');
   query("UPDATE batteries SET status=?,retired_at=CASE WHEN ?='retired' THEN now() ELSE NULL END,retirement_note=?,updated_at=now() WHERE id=?",[$target,$target,$target==='retired'?$reason:$old['retirement_note'],$batteryId]);
   batteryAudit('battery',$batteryId,$uid,$old,query('SELECT * FROM batteries WHERE id=?',[$batteryId])->fetch(PDO::FETCH_ASSOC));db()->commit();$_SESSION['flash']=$target==='retired'?'Battery retired. Its complete history is retained.':'Battery returned to active inventory.';
  }else{
   $batteryId=batteryId($_POST['battery_id']??'');$data=validateBatteryCheck($_POST);$id=($_POST['id']??'')!==''?batteryId($_POST['id']):'';
   $eventId=batteryText($_POST,'event_id',36);if($eventId!==''){$eventId=batteryId($eventId);if(!query('SELECT id FROM events WHERE id=?',[$eventId])->fetchColumn())throw new InvalidArgumentException('Choose an existing event.');}
   db()->beginTransaction();if(!query('SELECT id FROM batteries WHERE id=? FOR SHARE',[$batteryId])->fetchColumn())throw new InvalidArgumentException('Battery not found.');
   $old=$id?query('SELECT * FROM battery_checks WHERE id=? AND battery_id=? FOR UPDATE',[$id,$batteryId])->fetch(PDO::FETCH_ASSOC):false;
   if($id&&!$old)throw new InvalidArgumentException('Battery record not found.');
   if($old&&!batteryManager()&&$old['recorded_by']!==$uid){db()->rollBack();http_response_code(403);exit('You may only edit your own battery checks.');}
   if($old&&($_POST['version']??'')!==$old['updated_at'])throw new InvalidArgumentException('This check changed. Reload before editing it.');
   $id=$id?:uuid();query('INSERT INTO battery_checks(id,battery_id,recorded_on,check_type,condition,event_id,match_ref,readings,notes,recorded_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET recorded_on=EXCLUDED.recorded_on,check_type=EXCLUDED.check_type,condition=EXCLUDED.condition,event_id=EXCLUDED.event_id,match_ref=EXCLUDED.match_ref,readings=EXCLUDED.readings,notes=EXCLUDED.notes,updated_at=now()',[$id,$batteryId,$data['recorded_on'],$data['check_type'],$data['condition'],$eventId?:null,$data['match_ref'],json_encode((object)$data['readings'],JSON_THROW_ON_ERROR),$data['notes'],$uid]);
   batteryAudit('battery_check',$id,$uid,$old?:null,query('SELECT * FROM battery_checks WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC));db()->commit();$_SESSION['flash']='Battery check saved.';
  }
  unset($_SESSION['battery_draft']);$_SESSION['flash_type']='success';go('batteries&id='.$batteryId);
 }catch(InvalidArgumentException $ex){if(db()->inTransaction())db()->rollBack();$_SESSION['flash']=$ex->getMessage();$_SESSION['flash_type']='warn';$_SESSION['battery_draft']=['action'=>$action,'data'=>$_POST];go('batteries'.($batteryId?'&id='.$batteryId:'&new=1'));}
 catch(PDOException $ex){if(db()->inTransaction())db()->rollBack();if($ex->getCode()!=='23505')throw $ex;$_SESSION['flash']='That battery label is already in use. Choose a different label.';$_SESSION['battery_draft']=['action'=>$action,'data'=>$_POST];go('batteries'.(($action==='battery_save'&&($_POST['id']??'')==='')?'&new=1':($batteryId?'&id='.$batteryId:'&new=1')));}
}
function batteryDisplay($number,string $suffix=''): string {return $number===null||$number===''?'—':h(rtrim(rtrim(number_format((float)$number,2,'.',''),'0'),'.')).($suffix!==''?' '.h($suffix):'');}
function batteryBadge(string $condition): string {$class=['OK'=>'ok','Monitor'=>'monitor','Needs attention'=>'attention','Not assessed'=>'unknown','active'=>'ok','retired'=>'retired'][$condition]??'unknown';return '<span class="battery-badge '.$class.'">'.h(ucfirst($condition)).'</span>';}

<?php
declare(strict_types=1);
function batteryValue(array $data,string $key): string {return is_scalar($data[$key]??null)?(string)$data[$key]:'';}
function batteryInput(array $data,string $key,string $label,string $type='text',string $extra=''): void {echo '<label>'.h($label).'<input name="'.h($key).'" type="'.h($type).'" value="'.h(batteryValue($data,$key)).'" '.$extra.'></label>';}
function renderBatteryEditor(array $battery,array $draft=[]): void {
 $values=$draft?:$battery;$id=$battery['id']??'';
 echo '<form method="post" action="/?p=battery_save" class="battery-editor">'.csrf().'<input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="version" value="'.h($battery['updated_at']??'').'"><div class="battery-form-grid">';
 batteryInput($values,'label','Battery label','text','required maxlength="80" placeholder="USS Arizona"');batteryInput($values,'brand','Brand','text','maxlength="100"');batteryInput($values,'model','Model','text','maxlength="100"');batteryInput($values,'purchased_on','Purchase date','date');batteryInput($values,'rated_ah','Rated capacity (Ah)','number','min="0.01" max="1000" step="0.01"');
 echo '</div><p class="battery-help">Ship photos match the battery label automatically, for example Arizona, USS Missouri, or B01 - Iowa.</p><label>Battery notes<textarea name="notes" aria-label="Battery notes" maxlength="10000" rows="3">'.h(batteryValue($values,'notes')).'</textarea></label><button>Save battery</button></form>';
}
$draft=$_SESSION['battery_draft']??null;unset($_SESSION['battery_draft']);
$id=$_GET['id']??'';$battery=false;
if($id!==''){
 try{$id=batteryId($id);}catch(InvalidArgumentException $ex){http_response_code(404);exit('Battery not found');}
 $battery=query('SELECT * FROM batteries WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$battery){http_response_code(404);exit('Battery not found');}
}
page($battery?'Battery '.$battery['label']:'Batteries',true,false);
echo '<div class="battery-page"><div class="battery-header"><div>';
if($battery)echo '<a href="/?p=batteries">← All batteries</a><div class="battery-ship-heading">'.batteryShipThumbnail($battery['label'],true).'<div><h1>'.h($battery['label']).' '.batteryBadge($battery['status']).'</h1><p>'.h(trim($battery['brand'].' '.$battery['model'])).'</p></div></div>';
else echo '<h1>Batteries</h1><p>Inventory, performance checks, and service history across all events.</p>';
echo '</div>';if(!$battery&&batteryManager())echo '<a class="button" href="/?p=batteries&new=1#battery-editor">+ Add battery</a>';echo '</div>';
if(!$battery){
 $counts=query("SELECT count(*) AS total,count(*) FILTER(WHERE status='active') AS active,count(*) FILTER(WHERE status='retired') AS retired FROM batteries")->fetch(PDO::FETCH_ASSOC);
 echo '<div class="battery-stats"><div><strong>'.h($counts['active']).'</strong><span>Active batteries</span></div><div><strong>'.h($counts['retired']).'</strong><span>Retired batteries</span></div><div><strong>'.h(query('SELECT count(*) FROM battery_checks')->fetchColumn()).'</strong><span>Recorded checks</span></div></div>';
 if(batteryManager()&&(isset($_GET['new'])||($draft['action']??'')==='battery_save')){echo '<section id="battery-editor"><h2>Add battery</h2>';renderBatteryEditor(['rated_ah'=>'18'],($draft['action']??'')==='battery_save'?$draft['data']:[]);echo '</section>';}
 $status=in_array($_GET['status']??'active',['active','retired','all'],true)?($_GET['status']??'active'):'active';
 $sql="SELECT b.*,last_check.recorded_on,last_check.condition,(SELECT count(*) FROM battery_checks c WHERE c.battery_id=b.id) AS checks";
 foreach(['resting_voltage','resistance','capacity'] as $metric)$sql.=",(SELECT readings->>'".$metric."' FROM battery_checks c WHERE c.battery_id=b.id AND jsonb_typeof(readings->'".$metric."')='number' ORDER BY recorded_on DESC,created_at DESC,id DESC LIMIT 1) AS ".$metric;
 $sql.=" FROM batteries b LEFT JOIN LATERAL (SELECT recorded_on,condition FROM battery_checks c WHERE c.battery_id=b.id ORDER BY recorded_on DESC,created_at DESC,id DESC LIMIT 1) last_check ON true";
 if($status!=='all')$sql.=' WHERE b.status=?';$sql.=' ORDER BY lower(b.label),b.id';
 $rows=query($sql,$status==='all'?[]:[$status])->fetchAll(PDO::FETCH_ASSOC);
 echo '<section><div class="battery-toolbar"><form method="get"><input type="hidden" name="p" value="batteries"><label>Show<select name="status" onchange="this.form.submit()">';foreach(['active'=>'Active batteries','retired'=>'Retired batteries','all'=>'All batteries'] as $value=>$label)echo '<option value="'.h($value).'"'.($status===$value?' selected':'').'>'.h($label).'</option>';echo '</select></label><noscript><button>Apply</button></noscript></form><label>Find a battery<input id="batterySearch" type="search" placeholder="Ship name, brand, or model"></label></div>';
 if(!$rows)echo '<p class="battery-empty">'.($counts['total']?'No batteries in this view.':'No batteries yet. An admin or mentor can add your first battery.').'</p>';
 else{
  echo '<p class="battery-help">Metrics show each battery’s latest recorded value. Open a battery for dates and its full history. Click a column heading to sort.</p><div class="scroll"><table id="batteryTable"><thead><tr>';
  foreach(['Battery','Status','Latest check','Condition','Resting (V)','Resistance (mΩ)','Capacity (Ah)','Checks'] as $i=>$label)echo '<th scope="col"><button type="button" data-battery-sort="'.h($i).'"'.($i===0?' aria-label="Sort by battery label"':'').'>'.h($label).'</button></th>';
  echo '</tr></thead><tbody>';
  foreach($rows as $row){echo '<tr data-battery-search="'.h(strtolower(implode(' ',array_map(fn($key)=>(string)$row[$key],['label','brand','model'])))).'"'.($row['status']==='retired'?' class="battery-retired"':'').'><td data-sort="'.h($row['label']).'"><div class="battery-identity">'.batteryShipThumbnail($row['label']).'<div><a href="/?p=batteries&id='.h($row['id']).'"><strong>'.h($row['label']).'</strong></a><small>'.h(trim($row['brand'].' '.$row['model'])).'</small></div></div></td><td>'.batteryBadge($row['status']).'</td><td>'.h($row['recorded_on']??'—').'</td><td>'.($row['condition']?batteryBadge($row['condition']):'—').'</td>';foreach(['resting_voltage','resistance','capacity'] as $key)echo '<td data-sort="'.h($row[$key]??'').'">'.batteryDisplay($row[$key]).'</td>';echo '<td data-sort="'.h($row['checks']).'">'.h($row['checks']).'</td></tr>';}
  echo '</tbody></table></div><p id="batterySearchStatus" role="status"></p>';
 }
 echo '</section>';
}else{
 $history=query('SELECT c.*,u.name AS recorded_name,e.name AS event_name,e.event_key FROM battery_checks c JOIN users u ON u.id=c.recorded_by LEFT JOIN events e ON e.id=c.event_id WHERE c.battery_id=? ORDER BY c.recorded_on DESC,c.created_at DESC,c.id DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);
 foreach($history as &$check)$check['values']=json_decode($check['readings'],true)?:[];unset($check);
 $latest=[];foreach($history as $check)foreach(batteryMetricFields() as $key=>$definition)if(!isset($latest[$key])&&isset($check['values'][$key])&&is_numeric($check['values'][$key]))$latest[$key]=['value'=>$check['values'][$key],'date'=>$check['recorded_on']];
 if($battery['status']==='retired')echo '<aside class="notice">Retired '.h(substr($battery['retired_at']??'',0,10)).' · '.nl2br(h($battery['retirement_note'])).'. History and checks remain available.</aside>';
 echo '<section><h2>Battery details</h2><div class="battery-detail-grid"><p><b>Purchase date</b><br>'.h($battery['purchased_on']??'—').'</p><p><b>Rated capacity</b><br>'.batteryDisplay($battery['rated_ah'],'Ah').'</p><p><b>Recorded checks</b><br>'.count($history).'</p></div>';
 echo batteryShipCredit($battery['label']);
 if($battery['notes']!=='')echo '<p class="battery-notes">'.nl2br(h($battery['notes'])).'</p>';
 if(batteryManager()){
  echo '<details class="admin-rollup" id="battery-editor"'.(($draft['action']??'')==='battery_save'?' open':'').'><summary>Edit battery</summary><div class="pit-editor-body">';renderBatteryEditor($battery,($draft['action']??'')==='battery_save'?$draft['data']:[]);echo '</div></details>';
  if($battery['status']==='active')echo '<details class="admin-rollup"><summary>Retire battery</summary><div class="pit-editor-body"><p>Retiring keeps the battery and its complete service history.</p><form method="post" action="/?p=battery_status">'.csrf().'<input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="version" value="'.h($battery['updated_at']).'"><input type="hidden" name="status" value="retired"><label>Reason for retirement<textarea name="retirement_note" aria-label="Reason for retirement" required maxlength="1000">'.h(($draft['action']??'')==='battery_status'?batteryValue($draft['data'],'retirement_note'):'').'</textarea></label><button>Retire battery</button></form></div></details>';
  else echo '<form method="post" action="/?p=battery_status">'.csrf().'<input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="version" value="'.h($battery['updated_at']).'"><input type="hidden" name="status" value="active"><button>Return to active inventory</button></form>';
 }
 echo '</section>';
 if($latest){echo '<section><h2>Latest recorded readings</h2><div class="battery-metrics">';foreach(batteryMetricFields() as $key=>[$label,$unit])if(isset($latest[$key]))echo '<div><span>'.h($label).'</span><strong>'.batteryDisplay($latest[$key]['value'],$unit).'</strong><small>'.h($latest[$key]['date']).'</small></div>';echo '</div></section>';}
 $editId=$_GET['check']??'';$edit=false;if($editId!==''){try{$editId=batteryId($editId);}catch(InvalidArgumentException $ex){$editId='';}foreach($history as $row)if($row['id']===$editId&&(batteryManager()||$row['recorded_by']===$_SESSION['user']['id']))$edit=$row;}
 if(($draft['action']??'')==='battery_check'){
  $draftId=batteryValue($draft['data'],'id');if($draftId!==''&&!$edit)foreach($history as $row)if($row['id']===$draftId&&(batteryManager()||$row['recorded_by']===$_SESSION['user']['id']))$edit=$row;
 }
 $form=$edit?:['recorded_on'=>date('Y-m-d'),'check_type'=>'Inspection','condition'=>'Not assessed','event_id'=>$e['id']??'','readings'=>[]];
 $form['readings']=$edit?$edit['values']:[];
 if(($draft['action']??'')==='battery_check')$form=array_merge($form,$draft['data']);
 echo '<section id="battery-check"><h2>'.($edit?'Edit check':'Add performance check or note').'</h2><form method="post" action="/?p=battery_check" class="battery-check-form">'.csrf().'<input type="hidden" name="battery_id" value="'.h($id).'"><input type="hidden" name="id" value="'.h($edit['id']??'').'"><input type="hidden" name="version" value="'.h($edit['updated_at']??'').'"><div class="battery-form-grid">';
 batteryInput($form,'recorded_on','Date','date','required'.(!$edit&&($draft['action']??'')!=='battery_check'?' data-battery-today':''));
 foreach(['check_type'=>['Type',batteryCheckTypes()],'condition'=>['Observed condition',batteryConditions()]] as $key=>[$label,$options]){echo '<label>'.h($label).'<select name="'.h($key).'" aria-label="'.h($label).'">';foreach($options as $option)echo '<option'.(batteryValue($form,$key)===$option?' selected':'').'>'.h($option).'</option>';echo '</select></label>';}
 echo '<label>Event (optional)<select name="event_id" aria-label="Event (optional)"><option value="">No event / shop use</option>';foreach(query('SELECT id,name,event_key FROM events ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $eventOption)echo '<option value="'.h($eventOption['id']).'"'.(batteryValue($form,'event_id')===$eventOption['id']?' selected':'').'>'.h($eventOption['name'].' · '.$eventOption['event_key']).'</option>';echo '</select></label>';batteryInput($form,'match_ref','Match / use reference','text','maxlength="80" placeholder="Q16, practice, or shop test"');echo '</div><p>Enter readings from your tester and observations. Leave measurements you did not take blank.</p><div class="battery-form-grid">';
 $readings=is_array($form['readings']??null)?$form['readings']:[];
 foreach(batteryMetricFields() as $key=>[$label,$unit,$min,$max,$step])echo '<label>'.h($label.' ('.$unit.')').'<input type="number" name="readings['.h($key).']" min="'.h($min).'" max="'.h($max).'" step="'.h($step).'" value="'.h(batteryValue($readings,$key)).'"></label>';
 echo '</div><label>Check notes<textarea name="notes" aria-label="Check notes" maxlength="10000" rows="3" placeholder="Tester readings, voltage sag, connection issues, damage, charging behavior, or match observations">'.h(batteryValue($form,'notes')).'</textarea></label><button>'.($edit?'Save check changes':'Save check').'</button>'.($edit?' <a href="/?p=batteries&id='.h($id).'#battery-check">Cancel edit</a>':'').'</form></section>';
 echo '<section><h2>Service and performance history</h2>';if(!$history)echo '<p>No checks yet. Record your first readings or note above.</p>';
 foreach($history as $row){echo '<article class="battery-history"><div class="battery-history-heading"><h3>'.h($row['recorded_on']).' · '.h($row['check_type']).'</h3>'.batteryBadge($row['condition']).'</div><p class="battery-help">Recorded by '.h($row['recorded_name']).($row['event_name']?' · '.h($row['event_name']):'').($row['match_ref']!==''?' · '.h($row['match_ref']):'').'</p><div class="battery-history-readings">';foreach(batteryMetricFields() as $key=>[$label,$unit])if(isset($row['values'][$key]))echo '<span><b>'.h($label).'</b> '.batteryDisplay($row['values'][$key],$unit).'</span>';echo '</div>';if($row['notes']!=='')echo '<p class="battery-notes">'.nl2br(h($row['notes'])).'</p>';if(batteryManager()||$row['recorded_by']===$_SESSION['user']['id'])echo '<a href="/?p=batteries&id='.h($id).'&check='.h($row['id']).'#battery-check">Edit check</a>';echo '</article>';}
 echo '</section>';
}
echo '</div><script src="'.h(asset('battery-page.js')).'" defer></script>';endpage();

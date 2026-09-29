<?php
declare(strict_types=1);
session_start();
function db(): PDO { static $db; if (!$db) { $db = new PDO(getenv('DATABASE_URL'), getenv('DB_USER'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec(file_get_contents('/var/www/sql/schema.sql')); if (!(int)$db->query('SELECT count(*) FROM users')->fetchColumn()) { $q=$db->prepare('INSERT INTO users(id,name,password_hash,role) VALUES(?,?,?,?)');$q->execute([uuid(),'admin',password_hash('change-me-now',PASSWORD_DEFAULT),'admin']); } } return $db; }
function uuid(): string { $x=bin2hex(random_bytes(16));return substr($x,0,8).'-'.substr($x,8,4).'-4'.substr($x,13,3).'-'.dechex((hexdec($x[16])&3)|8).substr($x,17,3).'-'.substr($x,20); }
function query(string $sql,array $args=[]): PDOStatement { $q=db()->prepare($sql);$q->execute($args);return $q; }
function h($v): string { return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8'); }
function go(string $p): never { header('Location: /?p='.$p);exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function asset(string $name): string { $path=__DIR__.'/'.$name;$hash=is_file($path)?hash_file('sha256',$path):false;return '/'.$name.'?v='.($hash?substr($hash,0,12):'missing'); }
function role(...$roles): bool { return in_array($_SESSION['user']['role']??'', $roles,true); }
function event(): ?array { return query('SELECT * FROM events WHERE active=true ORDER BY name LIMIT 1')->fetch(PDO::FETCH_ASSOC)?:null; }
function field(array $d,string $k): string {return h($d[$k]??'');}
function catalog(int $year,bool $refresh=false): array {
 $year=max(2015,min((int)date('Y')+1,$year));
 $cached=query('SELECT * FROM event_catalog WHERE year=? ORDER BY name',[$year])->fetchAll(PDO::FETCH_ASSOC);
 if($refresh || !$cached) {
  $ctx=stream_context_create(['http'=>['timeout'=>12,'user_agent'=>'2370 Scouting local prototype (event selector)']]);
  $html=@file_get_contents("https://frc-events.firstinspires.org/$year/Events/EventList",false,$ctx);
  if($html && preg_match_all('~<a\s+href="/'.$year.'/([A-Za-z0-9]+)"\s+title="Event Information">([^<]+)</a>\s*<span id="detail2">\s*<br\s*/>\s*([^<]+)</span>~si',$html,$found,PREG_SET_ORDER)) {
   foreach($found as $m) {
    $name=html_entity_decode(trim($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $location=html_entity_decode(trim($m[3]),ENT_QUOTES|ENT_HTML5,'UTF-8');
    preg_match('/,\s*([A-Z]{2,3})\s+(?:USA|Canada)\s*$/',$location,$state);
    query('INSERT INTO event_catalog(year,code,name,location,state_code,fetched_at) VALUES(?,?,?,?,?,now()) ON CONFLICT(year,code) DO UPDATE SET name=EXCLUDED.name,location=EXCLUDED.location,state_code=EXCLUDED.state_code,fetched_at=now()',[$year,strtoupper($m[1]),$name,$location,$state[1]??'']);
   }
   $cached=query('SELECT * FROM event_catalog WHERE year=? ORDER BY name',[$year])->fetchAll(PDO::FETCH_ASSOC);
  } elseif($refresh || !$cached) $_SESSION['flash']='Could not load the FIRST event list. Cached choices remain available if previously loaded.';
 }
 return $cached;
}

function districts(int $year,bool $refresh=false): array {
 $rows=query('SELECT * FROM districts WHERE year=? ORDER BY name',[$year])->fetchAll(PDO::FETCH_ASSOC);
 if($refresh || !$rows) {
  $ctx=stream_context_create(['http'=>['timeout'=>12,'user_agent'=>'2370 Scouting local prototype (event selector)']]);
  $html=@file_get_contents("https://frc-events.firstinspires.org/$year/districts",false,$ctx);
  if($html && preg_match_all('~href="/'.$year.'/district/([A-Za-z0-9]+)".*?<div class="col-11 col-md-4">([^<]+)</div>~si',$html,$found,PREG_SET_ORDER)) {
   foreach($found as $m)query('INSERT INTO districts(year,code,name,fetched_at) VALUES(?,?,?,now()) ON CONFLICT(year,code) DO UPDATE SET name=EXCLUDED.name,fetched_at=now()',[$year,strtoupper($m[1]),html_entity_decode(trim($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')]);
   $rows=query('SELECT * FROM districts WHERE year=? ORDER BY name',[$year])->fetchAll(PDO::FETCH_ASSOC);
  }
 }
 return $rows;
}
function districtCodes(int $year,string $district,bool $refresh=false): array {
 $codes=query('SELECT event_code FROM district_events WHERE year=? AND district_code=?',[$year,$district])->fetchAll(PDO::FETCH_COLUMN);
 if($refresh || !$codes) {
  $ctx=stream_context_create(['http'=>['timeout'=>12,'user_agent'=>'2370 Scouting local prototype (event selector)']]);
  $html=@file_get_contents("https://frc-events.firstinspires.org/$year/Events/EventList?filter=".rawurlencode($district),false,$ctx);
  if($html && preg_match_all('~href="/'.$year.'/([A-Za-z0-9]+)"\s+title="Event Information"~si',$html,$matches)) {
   db()->beginTransaction();
   try {
    query('DELETE FROM district_events WHERE year=? AND district_code=?',[$year,$district]);
    foreach(array_unique(array_map('strtoupper',$matches[1])) as $code)query('INSERT INTO district_events(year,district_code,event_code) VALUES(?,?,?)',[$year,$district,$code]);
    db()->commit();
   } catch(Throwable $e) {db()->rollBack();throw $e;}
   $codes=query('SELECT event_code FROM district_events WHERE year=? AND district_code=?',[$year,$district])->fetchAll(PDO::FETCH_COLUMN);
  }
 }
 return $codes;
}
function officialData(int $year,string $code): array {
 $base="https://frc-events.firstinspires.org/$year/".rawurlencode($code);
 $ctx=stream_context_create(['http'=>['timeout'=>15,'user_agent'=>'2370 Scouting local prototype (official event import)']]);
 $eventHtml=@file_get_contents($base,false,$ctx);
 $qualHtml=@file_get_contents($base.'/qualifications',false,$ctx);
 $teams=[];$matches=[];
 if($eventHtml && preg_match_all('~<div class="col-3 col-md-1 fw-bold">\s*(\d+)\s*</div>\s*<div class="col-6">\s*([^<]+)\s*</div>~si',$eventHtml,$found,PREG_SET_ORDER))foreach($found as $m)$teams[(int)$m[1]]=html_entity_decode(trim($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');
 if($qualHtml && preg_match_all('~<tr id="match(\d+)a">(.*?)</tr>~si',$qualHtml,$rows,PREG_SET_ORDER))foreach($rows as $row){preg_match_all('~href="/'.$year.'/team/(\d+)"~si',$row[2],$numbers);$six=array_slice(array_map('intval',$numbers[1]),0,6);if(count($six)===6&&min($six)>0)$matches[(int)$row[1]]=$six;}
 return [$teams,$matches,(bool)$eventHtml,(bool)$qualHtml];
}
function saveOfficialData(string $eventId,array $teams,array $matches): int {
 foreach($teams as $number=>$name)query('INSERT INTO teams(event_id,number,name) VALUES(?,?,?) ON CONFLICT(event_id,number) DO UPDATE SET name=EXCLUDED.name',[$eventId,$number,$name]);
 $protected=0;
 foreach($matches as $number=>$six){
  $mid=query('INSERT INTO matches(id,event_id,match_number) VALUES(?,?,?) ON CONFLICT(event_id,match_number) DO UPDATE SET match_number=EXCLUDED.match_number RETURNING id',[uuid(),$eventId,$number])->fetchColumn();
  foreach(['R1','R2','R3','B1','B2','B3'] as $i=>$pos){
   $team=$six[$i];query('INSERT INTO teams(event_id,number) VALUES(?,?) ON CONFLICT DO NOTHING',[$eventId,$team]);
   $old=query('SELECT id,team_number FROM slots WHERE match_id=? AND position=?',[$mid,$pos])->fetch(PDO::FETCH_ASSOC);
   if($old && (int)$old['team_number']!==$team && query('SELECT count(*) FROM scouting WHERE slot_id=?',[$old['id']])->fetchColumn()){$protected++;continue;}
   query('INSERT INTO slots(id,match_id,position,team_number) VALUES(?,?,?,?) ON CONFLICT(match_id,position) DO UPDATE SET team_number=EXCLUDED.team_number',[uuid(),$mid,$pos,$team]);
  }
 }
 return $protected;
}
function eventType(string $name): string {
 if(stripos($name,'FIRST Championship')!==false) return 'worlds';
 if(stripos($name,'Regional')!==false) return 'regional';
 return 'district';
}
function metricAverage(array $reports,string $key,?float $max=null): ?float { $values=[];foreach($reports as $d){$v=$d[$key]??null;if($v!==null&&$v!==''&&is_numeric($v)&&(float)$v>=0&&($max===null||(float)$v<=$max))$values[]=(float)$v;}return $values?array_sum($values)/count($values):null; }
function picked($value): bool { return in_array($value,[true,'t','1',1],true); }
function pickBuckets(): array { return ['S+','A','B','C','DNP']; }
function pickRows(string $eventId): array { return query('SELECT t.number,t.name,ph.uploaded_at,p.rank,p.note,p.do_not_pick,p.picked,p.bucket,pit.data AS pit_data FROM teams t LEFT JOIN picklist p ON p.event_id=t.event_id AND p.team_number=t.number LEFT JOIN pit ON pit.event_id=t.event_id AND pit.team_number=t.number LEFT JOIN team_photos ph ON ph.event_id=t.event_id AND ph.team_number=t.number WHERE t.event_id=? ORDER BY COALESCE(p.rank,1000000+t.number),t.number',[$eventId])->fetchAll(PDO::FETCH_ASSOC); }
function robotMeta(array $data): string { $type=$data['robot_meta']??'';return $type==='Other'?trim((string)($data['robot_meta_other']??'')):$type; }
function savePickLayout(string $eventId,array $layout): void {
 $held=array_fill_keys(pickBuckets(),[]);$positions=array_fill_keys(pickBuckets(),0);
 foreach(pickRows($eventId) as $row){$bucket=in_array($row['bucket']??'B',pickBuckets(),true)?($row['bucket']??'B'):'B';if(picked($row['picked']))$held[$bucket][]=[$positions[$bucket],(int)$row['number']];$positions[$bucket]++;}
 foreach($layout as $bucket=>$numbers){
  $ordered=$numbers;
  foreach($held[$bucket] as [$position,$number])array_splice($ordered,min($position,count($ordered)),0,[$number]);
  foreach($ordered as $i=>$number)query('INSERT INTO picklist(event_id,team_number,rank,bucket,do_not_pick) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET rank=EXCLUDED.rank,bucket=EXCLUDED.bucket,do_not_pick=EXCLUDED.do_not_pick',[$eventId,$number,$i+1,$bucket,$bucket==='DNP'?'true':'false']);
 }
}
function heatColor(?float $value,?float $min,?float $max): string {
 if($value===null||$min===null||$max===null||$min===$max)return '';
 $stops=[[244,166,166],[248,194,122],[246,234,146],[167,220,167],[158,200,238]];
 $position=max(0,min(4,($value-$min)/($max-$min)*4));
 $i=min(3,(int)floor($position));$fraction=$position-$i;
 $rgb=[];for($channel=0;$channel<3;$channel++)$rgb[]=round($stops[$i][$channel]+($stops[$i+1][$channel]-$stops[$i][$channel])*$fraction);
 return 'background-color:rgb('.implode(',',$rgb).')';
}
function cleanAutoPath(string $raw): array {
 if(strlen($raw)>600000){http_response_code(400);exit('Autonomous path is too large');}
 $strokes=json_decode($raw,true);
 if(!is_array($strokes)||count($strokes)>30){http_response_code(400);exit('Invalid autonomous path');}
 $clean=[];
 foreach($strokes as $stroke){
  if(!is_array($stroke)||count($stroke)<1||count($stroke)>1000){http_response_code(400);exit('Invalid autonomous path');}
  $points=[];
  foreach($stroke as $point){
   if(!is_array($point)||count($point)!==2||!isset($point[0],$point[1])||!is_numeric($point[0])||!is_numeric($point[1])||!is_finite((float)$point[0])||!is_finite((float)$point[1])||(float)$point[0]<0||(float)$point[0]>1||(float)$point[1]<0||(float)$point[1]>1){http_response_code(400);exit('Invalid autonomous path');}
   $points[]=[round((float)$point[0],4),round((float)$point[1],4)];
  }
  $clean[]=$points;
 }
 return $clean;
}
function pathWidget(array $strokes,bool $editable): void {
 echo '<section class="path-widget"><h2>Autonomous path</h2><p>'.($editable?'Draw the robot’s route during autonomous. ':'Saved autonomous route. ').'The same field background is used on team and strategy pages.'.(role('admin')?' <a href="/?p=admin#field-background">Upload a field background</a>.':'').'</p><canvas id="autoPathCanvas" width="800" height="480" data-strokes="'.h(json_encode($strokes)).'" aria-label="Autonomous field path"></canvas>';
 if($editable)echo '<input type="hidden" name="auto_path" id="autoPathInput" value="'.h(json_encode($strokes)).'"><div class="path-actions"><button type="button" id="pathUndo">Undo last stroke</button><button type="button" id="pathClear">Clear path</button></div>';
 echo '</section>';
}
function page(string $title,bool $wide=false): void { $active=$GLOBALS['e']??null;$year='';if($active&&preg_match('/^(\d{4})/',(string)($active['event_key']??''),$match))$year=$match[1];echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).' · Scouting</title><link rel="stylesheet" href="'.h(asset('style.css')).'"><header><div class="header-identity"><strong>2370 · Scouting</strong>'.($active?'<span class="selected-event">Selected Event: '.h(trim($year.' '.$active['name'])).'</span>':'').'</div><nav><a href="/?p=home">Dashboard</a><a href="/?p=matches">Matches</a><a href="/?p=teams">Teams</a><a href="/?p=strategy">Strategy</a><a href="/?p=picks">Pick list</a><a href="/?p=admin">Admin</a><a href="/?p=logout">Log out</a></nav></header><main'.($wide?' class="wide"':'').'><h1>'.h($title).'</h1>'; if(isset($_SESSION['flash'])) {echo '<aside class="'.h($_SESSION['flash_type']??'notice').'">'.h($_SESSION['flash']).'</aside>';unset($_SESSION['flash'],$_SESSION['flash_type']);} }
function endpage(): void {echo '</main></html>';}
try { db(); } catch(Throwable $e) { http_response_code(503);exit('Database unavailable. Start Docker Compose and try again.'); }
$p=$_GET['p']??'home';
if($p==='logout'){session_destroy();header('Location: /');exit;}
if($_SERVER['REQUEST_METHOD']==='POST') {
 if($p==='login') { $u=query('SELECT * FROM users WHERE name=?',[trim($_POST['name']??'')])->fetch(PDO::FETCH_ASSOC);if($u&&password_verify($_POST['password']??'',$u['password_hash'])) {session_regenerate_id(true);$_SESSION['user']=['id'=>$u['id'],'name'=>$u['name'],'role'=>$u['role'],'position'=>$u['position']];$_SESSION['csrf']=bin2hex(random_bytes(16));go('home');} $_SESSION['flash']='Invalid login';go('login'); }
 if(!isset($_SESSION['user']) || !hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(403);exit('Invalid session');}
 $e=event();$uid=$_SESSION['user']['id'];
 if($p==='event'&&role('admin')) { $year=(int)($_POST['year']??0);$code=strtoupper(trim($_POST['event_code']??''));$selected=query('SELECT * FROM event_catalog WHERE year=? AND code=?',[$year,$code])->fetch(PDO::FETCH_ASSOC);if(!$selected){http_response_code(400);exit('Choose an event from the list');}[$teams,$matches,$teamPage,$schedulePage]=officialData($year,$code);db()->beginTransaction();try{query('UPDATE events SET active=false');$id=query('INSERT INTO events(id,name,event_key,active) VALUES(?,?,?,true) ON CONFLICT(event_key) DO UPDATE SET name=EXCLUDED.name,active=true RETURNING id',[uuid(),$selected['name'],(string)$year.strtolower($code)])->fetchColumn();$protected=saveOfficialData($id,$teams,$matches);db()->commit();}catch(Throwable $ex){db()->rollBack();throw $ex;}$_SESSION['flash_type']='success';$_SESSION['flash']='Selected event: '.$selected['name'].': imported '.count($teams).' teams and '.count($matches).' qualification matches.'.(!$teamPage||!$schedulePage?' Some official pages were unavailable; use Refresh official data later.':'').($protected?' '.$protected.' scouted positions kept their prior team assignments.':'');go('admin');}
 if($p==='refresh_event'&&role('admin')&&$e&&preg_match('/^(\d{4})([a-z0-9]+)$/',$e['event_key']??'',$parts)){[$teams,$matches,$teamPage,$schedulePage]=officialData((int)$parts[1],strtoupper($parts[2]));db()->beginTransaction();try{$protected=saveOfficialData($e['id'],$teams,$matches);db()->commit();}catch(Throwable $ex){db()->rollBack();throw $ex;}$_SESSION['flash']='Refreshed '.count($teams).' teams and '.count($matches).' qualification matches.'.(!$teamPage||!$schedulePage?' Some official pages were unavailable.':'').($protected?' '.$protected.' scouted positions retained.':'');go('admin');}
 if($p==='seed_wpi'&&role('admin')&&$e&&$e['event_key']==='2026mawor') {
  // Bundle the official 2026 schedule so demo data also works without Internet access.
  $fixture=json_decode(file_get_contents('/var/www/fixtures/wpi-2026.json'),true,512,JSON_THROW_ON_ERROR);
  $teams=$fixture['teams'];$matches=$fixture['matches'];
  db()->beginTransaction();
  try {
   saveOfficialData($e['id'],$teams,$matches);
   $slots=query('SELECT s.id,s.team_number,sc.id AS record_id,sc.data FROM slots s JOIN matches m ON m.id=s.match_id LEFT JOIN scouting sc ON sc.slot_id=s.id WHERE m.event_id=? ORDER BY m.match_number,s.position FOR UPDATE OF s',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
   $new=0;$replaced=0;$kept=0;
   foreach($slots as $slot) {
    $prior=$slot['data']??null;
    if($slot['record_id'] && !((json_decode($prior,true)?:[])['demo']??false)){$kept++;continue;}
    $auto=random_int(10,100);$teleop=random_int(20,400);
    $data=['demo'=>true,'match_score'=>$auto+$teleop,'auto_score'=>$auto,'teleop_score'=>$teleop,'defense_rating'=>random_int(0,5),'defensive_vulnerability'=>random_int(0,5),'notes'=>'Demo data — synthetic scores for dashboard testing.'];
    $encoded=json_encode($data,JSON_THROW_ON_ERROR);
    $recordId=$slot['record_id']??uuid();
    query('INSERT INTO scouting(id,slot_id,scout_id,data,status) VALUES(?,?,?,?,\'submitted\') ON CONFLICT(slot_id) DO UPDATE SET data=EXCLUDED.data,status=\'submitted\',scout_id=EXCLUDED.scout_id,updated_at=now(),version=scouting.version+1,sync_state=\'pending\' ',[$recordId,$slot['id'],$uid,$encoded]);
    query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'scouting',$recordId,$uid,$prior,$encoded]);
    query('UPDATE slots SET status=\'submitted\' WHERE id=?',[$slot['id']]);
    if($slot['record_id'])$replaced++;else $new++;
   }
   $metaOptions=[['Big Dumper',''],['Turret',''],['Other','Dual intake'],['Other','Articulating hood']];
   $pitAdded=0;$pitReplaced=0;$pitKept=0;
   foreach(array_keys($teams) as $teamNumber) {
    $existing=query('SELECT id,data FROM pit WHERE event_id=? AND team_number=?',[$e['id'],(int)$teamNumber])->fetch(PDO::FETCH_ASSOC);
    $prior=$existing['data']??null;
    if($existing&&!((json_decode($prior,true)?:[])['demo']??false)){$pitKept++;continue;}
    [$type,$custom]=$metaOptions[random_int(0,count($metaOptions)-1)];
    $data=['demo'=>true,'robot_meta'=>$type,'robot_meta_other'=>$custom];
    $encoded=json_encode($data,JSON_THROW_ON_ERROR);$recordId=$existing['id']??uuid();
    query('INSERT INTO pit(id,event_id,team_number,author_id,data) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET data=EXCLUDED.data,author_id=EXCLUDED.author_id,updated_at=now(),sync_state=\'pending\' ',[$recordId,$e['id'],(int)$teamNumber,$uid,$encoded]);
    query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'pit',$recordId,$uid,$prior,$encoded]);
    if($existing)$pitReplaced++;else $pitAdded++;
   }
   db()->commit();
  } catch(Throwable $ex) {db()->rollBack();throw $ex;}
  $covered=query('SELECT count(DISTINCT s.team_number) FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status=\'submitted\' ',[$e['id']])->fetchColumn();
  $_SESSION['flash_type']='success';
  $_SESSION['flash']="WPI demo data ready: $covered of ".count($teams)." teams have reports; $new new reports, $replaced regenerated demo reports, $kept existing reports preserved. Robot Meta: $pitAdded added, $pitReplaced regenerated, $pitKept manual pit records preserved. Open Dashboard or Pick list to explore.";
  go('admin');
 }
 if($p==='user'&&role('admin')) {if(strlen($_POST['password']??'')<10) {$_SESSION['flash']='Password must be at least 10 characters';go('admin');}query('INSERT INTO users(id,name,password_hash,role,position) VALUES(?,?,?,?,?)',[uuid(),trim($_POST['name']),password_hash($_POST['password'],PASSWORD_DEFAULT),$_POST['role'],$_POST['position']?:null]);go('admin');}
 if($p==='upload_field'&&role('admin')&&$e){
  $upload=$_FILES['field_image']??null;
  if(!$upload||$upload['error']!==UPLOAD_ERR_OK||$upload['size']>4*1024*1024||$upload['size']<1||!is_uploaded_file($upload['tmp_name'])){$_SESSION['flash']='Choose a JPEG, PNG, or WebP field image under 4 MB.';go('admin');}
  $details=@getimagesize($upload['tmp_name']);$mime=$details['mime']??'';
  if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$_SESSION['flash']='Choose a JPEG, PNG, or WebP field image.';go('admin');}
  query('INSERT INTO event_field_images(event_id,mime,photo_base64) VALUES(?,?,?) ON CONFLICT(event_id) DO UPDATE SET mime=EXCLUDED.mime,photo_base64=EXCLUDED.photo_base64,uploaded_at=now()',[$e['id'],$mime,base64_encode(file_get_contents($upload['tmp_name']))]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Autonomous field image updated for '.$e['name'].'.';go('admin');
 }
 if($p==='strategy'&&$e&&role('admin','mentor','drive')){
  $id=(string)($_POST['id']??'');$old=$id?query('SELECT * FROM strategy_plans WHERE id=? AND event_id=?',[$id,$e['id']])->fetch(PDO::FETCH_ASSOC):false;
  if($id&&!$old){http_response_code(404);exit('Strategy plan not found');}
  $id=$old['id']??uuid();$title=trim((string)($_POST['title']??''));$notes=trim((string)($_POST['notes']??''));
  if($title===''||strlen($title)>120||strlen($notes)>10000){http_response_code(400);exit('Invalid strategy title or notes');}
  $teams=[];$paths=json_decode((string)($_POST['strategy_paths']??'[]'),true);
  if(!is_array($paths)||count($paths)!==3){http_response_code(400);exit('Invalid strategy paths');}
  $seen=[];$cleanPaths=[];
  for($i=0;$i<3;$i++){
   $number=(int)($_POST['team_'.$i]??0);$color=(string)($_POST['color_'.$i]??'');
   if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color)){http_response_code(400);exit('Invalid team color');}
   if($number&&(!query('SELECT 1 FROM teams WHERE event_id=? AND number=?',[$e['id'],$number])->fetchColumn()||isset($seen[$number]))){http_response_code(400);exit('Choose distinct teams from this event');}
   if($number)$seen[$number]=true;
   $teams[]=['number'=>$number,'color'=>$color];
   $cleanPaths[]=cleanAutoPath(json_encode($paths[$i]??[],JSON_THROW_ON_ERROR));
  }
  if(!$seen){http_response_code(400);exit('Select at least one team');}
  $teamJson=json_encode($teams,JSON_THROW_ON_ERROR);$pathJson=json_encode($cleanPaths,JSON_THROW_ON_ERROR);
  query('INSERT INTO strategy_plans(id,event_id,title,teams,paths,notes,author_id) VALUES(?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET title=EXCLUDED.title,teams=EXCLUDED.teams,paths=EXCLUDED.paths,notes=EXCLUDED.notes,author_id=EXCLUDED.author_id,updated_at=now()',[$id,$e['id'],$title,$teamJson,$pathJson,$notes,$uid]);
  query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'strategy',$id,$uid,$old?json_encode(['title'=>$old['title'],'teams'=>json_decode($old['teams'],true),'paths'=>json_decode($old['paths'],true),'notes'=>$old['notes']]):null,json_encode(['title'=>$title,'teams'=>$teams,'paths'=>$cleanPaths,'notes'=>$notes])]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Strategy plan saved.';go('strategy&id='.$id);
 }
 if($p==='strategy_delete'&&$e&&role('admin','mentor','drive')){
  $id=(string)($_POST['id']??'');
  if(!preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i',$id)){http_response_code(400);exit('Invalid strategy plan');}
  db()->beginTransaction();
  try{
   $old=query('SELECT * FROM strategy_plans WHERE id=? AND event_id=? FOR UPDATE',[$id,$e['id']])->fetch(PDO::FETCH_ASSOC);
   if(!$old){db()->rollBack();http_response_code(404);exit('Strategy plan not found');}
   query('DELETE FROM strategy_plans WHERE id=? AND event_id=?',[$id,$e['id']]);
   query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'strategy',$id,$uid,json_encode(['title'=>$old['title'],'teams'=>json_decode($old['teams'],true),'paths'=>json_decode($old['paths'],true),'notes'=>$old['notes']]),json_encode(['deleted'=>true])]);
   db()->commit();
  }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
  $_SESSION['flash_type']='success';$_SESSION['flash']='Strategy plan deleted.';go('strategy');
 }
 if($p==='import'&&role('admin')&&$e) { $f=fopen($_FILES['csv']['tmp_name'],'r');$head=array_map('strtolower',fgetcsv($f));$n=0;while(($row=fgetcsv($f))!==false){$r=array_combine($head,$row);$m=(int)($r['match']??0);if(!$m)continue;$mid=query('INSERT INTO matches(id,event_id,match_number) VALUES(?,?,?) ON CONFLICT(event_id,match_number) DO UPDATE SET match_number=EXCLUDED.match_number RETURNING id',[uuid(),$e['id'],$m])->fetchColumn();foreach(['R1','R2','R3','B1','B2','B3'] as $pos){$num=(int)($r[strtolower($pos)]??0);if(!$num)continue;query('INSERT INTO teams(event_id,number) VALUES(?,?) ON CONFLICT DO NOTHING',[$e['id'],$num]);query('INSERT INTO slots(id,match_id,position,team_number) VALUES(?,?,?,?) ON CONFLICT(match_id,position) DO UPDATE SET team_number=EXCLUDED.team_number WHERE NOT EXISTS(SELECT 1 FROM scouting WHERE slot_id=slots.id AND status=\'submitted\')',[uuid(),$mid,$pos,$num]);}$n++;}fclose($f);$_SESSION['flash']="Imported $n matches";go('matches'); }
 if($p==='scout'&&$e) {
  $slot=query('SELECT s.*,m.event_id FROM slots s JOIN matches m ON m.id=s.match_id WHERE s.id=?',[$_POST['slot']??''])->fetch(PDO::FETCH_ASSOC);
  if(!$slot||$slot['event_id']!==$e['id']){http_response_code(404);exit('Slot not found');}
  $old=query('SELECT * FROM scouting WHERE slot_id=?',[$slot['id']])->fetch(PDO::FETCH_ASSOC);
  if($old&&$old['status']==='submitted'&&!role('admin','mentor')){http_response_code(403);exit('Submitted record is locked');}
  if($old&&(int)$old['version']!==(int)($_POST['version']??0)){http_response_code(409);exit('This record changed. Reload before saving.');}
  $data=[];
  foreach(['auto_score','teleop_score','defense_rating','defensive_vulnerability'] as $k){
   $value=trim((string)($_POST[$k]??''));$rating=in_array($k,['defense_rating','defensive_vulnerability'],true);$limit=$rating?5:9999;
   if($value!==''&&(!is_numeric($value)||(float)$value<0||(float)$value>$limit||($rating&&abs((float)$value*10-round((float)$value*10))>0.00001))){http_response_code(400);exit('Invalid numeric scouting value');}
   $data[$k]=$value;
  }
  $data['match_score']=$data['auto_score']!==''&&$data['teleop_score']!==''?(float)$data['auto_score']+(float)$data['teleop_score']:'';
  $data['auto_path']=cleanAutoPath((string)($_POST['auto_path']??'[]'));
  foreach(['endgame','defense','penalties','breakdown','notes'] as $k)$data[$k]=trim((string)($_POST[$k]??''));
  $status=isset($_POST['submit'])?'submitted':'draft';$id=$old['id']??uuid();$prior=$old['data']??null;$encoded=json_encode($data,JSON_THROW_ON_ERROR);
  query('INSERT INTO scouting(id,slot_id,scout_id,data,status) VALUES(?,?,?,?,?) ON CONFLICT(slot_id) DO UPDATE SET data=EXCLUDED.data,status=EXCLUDED.status,scout_id=EXCLUDED.scout_id,updated_at=now(),version=scouting.version+1,sync_state=\'pending\' ',[$id,$slot['id'],$uid,$encoded,$status]);
  query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'scouting',$id,$uid,$prior,$encoded]);
  query('UPDATE slots SET status=? WHERE id=?',[$status,$slot['id']]);go('matches');
 }
 if($p==='upload_photo'&&$e) { $num=(int)($_POST['team']??0);$team=query('SELECT number FROM teams WHERE event_id=? AND number=?',[$e['id'],$num])->fetch(PDO::FETCH_ASSOC);if(!$team){http_response_code(404);exit('Team not found');}$upload=$_FILES['photo']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK||$upload['size']>4*1024*1024||$upload['size']<1||!is_uploaded_file($upload['tmp_name'])){$_SESSION['flash']='Choose a JPEG, PNG, or WebP photo under 4 MB.';go('pit&n='.$num);} $details=@getimagesize($upload['tmp_name']);$mime=$details['mime']??'';if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$_SESSION['flash']='This image format is not supported. Choose JPEG, PNG, or WebP.';go('pit&n='.$num);} $data=base64_encode(file_get_contents($upload['tmp_name']));query('INSERT INTO team_photos(event_id,team_number,mime,photo_base64) VALUES(?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET mime=EXCLUDED.mime,photo_base64=EXCLUDED.photo_base64,uploaded_at=now()',[$e['id'],$num,$mime,$data]);$_SESSION['flash']='Robot photo saved.';go('pit&n='.$num);}
 if($p==='pit'&&$e) { $num=(int)($_POST['team']??0);if($num<1)exit('Invalid team');$meta=(string)($_POST['robot_meta']??'');$custom=trim((string)($_POST['robot_meta_other']??''));if(!in_array($meta,['','Big Dumper','Turret','Other'],true)||($meta==='Other'&&($custom===''||strlen($custom)>80))){http_response_code(400);exit('Choose Robot Meta or enter custom text under 80 characters');}$data=['robot_meta'=>$meta,'robot_meta_other'=>$meta==='Other'?$custom:''];foreach(['robot','drivetrain','dimensions','weight','mechanisms','scoring','autonomous','endgame','strategy','reliability','requirements','notes'] as $k)$data[$k]=trim((string)($_POST[$k]??''));query('INSERT INTO teams(event_id,number) VALUES(?,?) ON CONFLICT DO NOTHING',[$e['id'],$num]);$old=query('SELECT * FROM pit WHERE event_id=? AND team_number=?',[$e['id'],$num])->fetch(PDO::FETCH_ASSOC);$id=$old['id']??uuid();query('INSERT INTO pit(id,event_id,team_number,author_id,data) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET data=EXCLUDED.data,author_id=EXCLUDED.author_id,updated_at=now(),sync_state=\'pending\' ',[$id,$e['id'],$num,$uid,json_encode($data)]);query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'pit',$id,$uid,$old['data']??null,json_encode($data)]);go('team&n='.$num); }
 if(in_array($p,['pick_status','pick_order','pick_note','pick_dnp'],true)&&$e&&role('admin','mentor','drive')) {
  $rows=pickRows($e['id']);$numbers=array_map('intval',array_column($rows,'number'));
  if($p==='pick_order'){
   $layout=json_decode((string)($_POST['order']??''),true);$flat=[];
   if(!is_array($layout)||array_keys($layout)!==pickBuckets()){http_response_code(400);exit('Invalid pick buckets');}
   foreach($layout as $bucket=>$teams){if(!is_array($teams)||!array_is_list($teams)){http_response_code(400);exit('Invalid pick bucket');}foreach($teams as $team)$flat[]=$team;}
   $available=array_map('intval',array_column(array_values(array_filter($rows,fn($row)=>!picked($row['picked']))),'number'));
   if(count($flat)!==count($available)||count(array_filter($flat,'is_int'))!==count($flat)||count(array_unique($flat))!==count($available)||array_diff($available,$flat)||array_diff($flat,$available)){http_response_code(400);exit('Invalid pick order');}
   db()->beginTransaction();try{savePickLayout($e['id'],$layout);db()->commit();}catch(Throwable $ex){db()->rollBack();throw $ex;}
   go('picks');
  }
  $num=(int)($_POST['team']??0);if(!in_array($num,$numbers,true)){http_response_code(400);exit('Choose a team from this event');}
  $current=array_values(array_filter($rows,fn($row)=>(int)$row['number']===$num))[0];
  $bucket=in_array($current['bucket']??'B',pickBuckets(),true)?($current['bucket']??'B'):'B';
  if($p==='pick_status')query('INSERT INTO picklist(event_id,team_number,rank,bucket,picked) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET picked=EXCLUDED.picked',[$e['id'],$num,1000000+$num,$bucket,isset($_POST['picked'])?'true':'false']);
  elseif($p==='pick_dnp'){
   $target=isset($_POST['dnp'])?'DNP':'B';
   $rank=max(10000000,(int)query('SELECT COALESCE(MAX(rank),0)+1 FROM picklist WHERE event_id=? AND bucket=?',[$e['id'],$target])->fetchColumn());
   query('INSERT INTO picklist(event_id,team_number,rank,bucket,do_not_pick) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET rank=EXCLUDED.rank,bucket=EXCLUDED.bucket,do_not_pick=EXCLUDED.do_not_pick',[$e['id'],$num,$rank,$target,$target==='DNP'?'true':'false']);
  }else{
   $note=trim((string)($_POST['note']??''));if(strlen($note)>1000){http_response_code(400);exit('Note is too long');}
   query('INSERT INTO picklist(event_id,team_number,rank,bucket,note) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET note=EXCLUDED.note',[$e['id'],$num,1000000+$num,$bucket,$note]);
  }
  go('picks');
 }
 http_response_code(403);exit('Forbidden');
}
if(!isset($_SESSION['user'])) {echo '<link rel="stylesheet" href="/style.css"><main><h1>Scouting sign in</h1><form method="post" action="/?p=login"><label>Username<input name="name" required autofocus></label><label>Password<input name="password" type="password" required></label><button>Sign in</button></form><p>First run: admin / change-me-now. Change this password before use.</p></main>';exit;}
$e=event();
if($p==='field_image'){$image=$e?query('SELECT mime,photo_base64 FROM event_field_images WHERE event_id=?',[$e['id']])->fetch(PDO::FETCH_ASSOC):false;if(!$image){http_response_code(404);exit;}header('Content-Type: '.$image['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-cache');echo base64_decode($image['photo_base64']);exit;}
if($p==='robot_photo'){ $num=(int)($_GET['n']??0);$photo=$e?query('SELECT mime,photo_base64 FROM team_photos WHERE event_id=? AND team_number=?',[$e['id'],$num])->fetch(PDO::FETCH_ASSOC):false;if(!$photo){http_response_code(404);exit;}header('Content-Type: '.$photo['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');echo base64_decode($photo['photo_base64']);exit;}
if($p==='home'){page('Pit Scouting Dashboard',true);echo '<p>'.h($e['name']??'No event selected').'</p><aside class="warn">Cloud sync is not configured. Local entries remain in PostgreSQL; remote backup is not active.</aside>';if($e){
 $teams=query('SELECT number,name FROM teams WHERE event_id=? ORDER BY number',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
 $reports=query('SELECT s.team_number,sc.data FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status=\'submitted\' ORDER BY sc.updated_at DESC',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
 $byTeam=[];foreach($reports as $report)$byTeam[$report['team_number']][]=json_decode($report['data'],true)?:[];
 $pits=query('SELECT team_number,data FROM pit WHERE event_id=?',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);$pitNotes=[];foreach($pits as $pit)$pitNotes[$pit['team_number']]=(json_decode($pit['data'],true)['notes']??'');
 $keys=['match_score','auto_score','teleop_score','defense_rating','defensive_vulnerability'];$rows=[];$bounds=[];foreach($teams as $team){$number=$team['number'];$entries=$byTeam[$number]??[];$metrics=[];foreach($keys as $key){$v=metricAverage($entries,$key,in_array($key,['defense_rating','defensive_vulnerability'],true)?5.0:null);$metrics[$key]=$v;if($v!==null)$bounds[$key][]=$v;}$lastNote='';foreach($entries as $entry)if(trim((string)($entry['notes']??''))!==''){$lastNote=trim($entry['notes']);break;}$notes=trim((string)($pitNotes[$number]??''));if($lastNote)$notes.=($notes?' | ':'').$lastNote;$rows[]=['number'=>$number,'name'=>$team['name'],'metrics'=>$metrics,'notes'=>$notes];}
 echo '<p>Scores are scouted estimates for each robot. Averages use submitted records with a value entered; defense and vulnerability range from 0 to 5. For vulnerability, 5 means not vulnerable.<br>'.count($reports).' submitted match reports across '.count($byTeam).' teams.</p>';if(!$reports)echo '<aside class="warn">No submitted match reports for this event yet. For the 2026 WPI event, open Admin and click Load WPI demo data.</aside>';echo '<div class="scroll"><table class="dashboard-table" id="teamDashboard"><thead><tr>';$headers=['Team #','Team name','Avg match score','Avg autonomous','Avg teleop','Avg defensive ability','Avg defensive vulnerability','Notes'];foreach($headers as $i=>$label)echo '<th><button type="button" class="sort-head" data-col="'.h($i).'" aria-label="Sort by '.h($label).'">'.h($label).' <span aria-hidden="true">↕</span></button></th>';echo '</tr></thead><tbody>';foreach($rows as $row){$n=h($row['number']);echo '<tr><td data-value="'.$n.'"><a href="/?p=team&n='.$n.'">'.$n.'</a></td><td data-value="'.h(strtolower($row['name'])).'">'.h($row['name']).'</td>';foreach($keys as $key){$v=$row['metrics'][$key];$display=$v===null?'—':number_format($v,1,'.','');$values=$bounds[$key]??[];$style=$values?heatColor($v,min($values),max($values)):'';echo '<td class="metric" data-value="'.h($v===null?'':$v).'"'.($style?' style="'.h($style).'"':'').'>'.h($display).'</td>';}echo '<td class="notes-cell" data-value="'.h(strtolower($row['notes'])).'">'.h($row['notes']?:'—').'</td></tr>';}echo '</tbody></table></div><p class="dashboard-help">Click a column heading to sort. <span id="dashboardControlsStatus" role="status">Loading table controls…</span></p><script src="'.h(asset('dashboard.js')).'" defer></script>';}endpage();}
elseif($p==='admin'){if(!role('admin')){http_response_code(403);exit('Admins only');}page('Administration');$year=(int)($_GET['year']??date('Y'));$year=max(2015,min((int)date('Y')+1,$year));$refresh=isset($_GET['refresh']);$list=catalog($year,$refresh);$districtList=districts($year,$refresh);$type=in_array($_GET['type']??'district',['district','regional','worlds'],true)?($_GET['type']??'district'):'district';$district=(string)($_GET['district']??'NE');$known=array_column($districtList,'code');if(!in_array($district,$known,true))$district=$known[0]??'';$allowed=$type==='district'&&$district?districtCodes($year,$district,$refresh):[];$filtered=array_values(array_filter($list,function($item)use($type,$allowed){return $type==='district'?in_array($item['code'],$allowed,true):eventType($item['name'])===$type;}));if(!$list)echo '<aside class="warn">No event list is cached for this year. Check the server Internet connection and refresh the event list.</aside>';echo '<div class="cards"><section><h2>Event Selection</h2><form method="get"><input type="hidden" name="p" value="admin"><label>Year<select name="year" onchange="this.form.submit()">';for($y=(int)date('Y');$y>=2015;$y--)echo '<option value="'.h($y).'"'.($y===$year?' selected':'').'>'.h($y).'</option>';echo '</select></label><label>Competition type<select name="type" onchange="this.form.submit()">';foreach(['district'=>'Districts','regional'=>'Regionals','worlds'=>'Worlds'] as $value=>$label)echo '<option value="'.h($value).'"'.($value===$type?' selected':'').'>'.h($label).'</option>';echo '</select></label>';if($type==='district'){echo '<label>FIRST district<select name="district" onchange="this.form.submit()">';foreach($districtList as $item)echo '<option value="'.h($item['code']).'"'.($item['code']===$district?' selected':'').'>'.h($item['name']).'</option>';echo '</select></label>';}else echo '<input type="hidden" name="district" value="'.h($district).'">';echo '</form><p>'.count($filtered).' events shown. <a href="/?p=admin&year='.h($year).'&type='.h($type).'&district='.h($district).'&refresh=1">Refresh FIRST event list</a></p><form method="post" action="/?p=event">'.csrf().'<input type="hidden" name="year" value="'.h($year).'"><label>Event<select name="event_code" required><option value="">Choose an event</option>';foreach($filtered as $item)echo '<option value="'.h($item['code']).'">'.h($item['name']).' — '.h($item['location']).'</option>';echo '</select></label><button'.(!$filtered?' disabled':'').'>Select event</button></form></section><section><h2>Add user</h2><form method="post" action="/?p=user">'.csrf().'<label>Username<input name="name" required></label><label>Password<input type="password" name="password" minlength="10" required></label><label>Role<select name="role"><option>scout</option><option>pit</option><option>drive</option><option>mentor</option><option>admin</option></select></label><label>Position<select name="position"><option value="">None</option>';foreach(['R1','R2','R3','B1','B2','B3'] as $x)echo '<option>'.h($x).'</option>';echo '</select></label><button>Create account</button></form></section></div>';if($e)echo '<section><h2>Import schedule</h2><p>CSV columns: match,r1,r2,r3,b1,b2,b3. Existing submitted slots are protected.</p><form method="post" action="/?p=import" enctype="multipart/form-data">'.csrf().'<input type="file" name="csv" accept=".csv" required><button>Import CSV</button></form></section>';if($e){$tc=query('SELECT count(*) FROM teams WHERE event_id=?',[$e['id']])->fetchColumn();$mc=query('SELECT count(*) FROM matches WHERE event_id=?',[$e['id']])->fetchColumn();echo '<section><h2>Active event</h2><p class="event-confirmation">Selected event: <strong>'.h($e['name']).'</strong></p><p>'.h($tc).' teams · '.h($mc).' qualification matches loaded</p>';if(preg_match('/^(\d{4})([a-z0-9]+)$/',$e['event_key']??''))echo '<form method="post" action="/?p=refresh_event">'.csrf().'<button>Refresh official data</button></form>';if(($e['event_key']??'')==='2026mawor')echo '<h3>WPI test data</h3><p>Generate synthetic match reports for every loaded team. Autonomous: 10–100; teleop: 20–400; total: their sum. Defense and vulnerability: 0–5. Existing real reports are preserved. Clicking again regenerates demo reports.</p><form method="post" action="/?p=seed_wpi">'.csrf().'<button>Load WPI demo data</button></form>';echo '<h3 id="field-background">Autonomous field background</h3><p>Upload one field image for this event. It appears behind every match path and the team overlay.</p>';if(query('SELECT count(*) FROM event_field_images WHERE event_id=?',[$e['id']])->fetchColumn())echo '<img class="field-preview" src="/?p=field_image" alt="Current autonomous field background">';echo '<form method="post" action="/?p=upload_field" enctype="multipart/form-data">'.csrf().'<label>Field image (JPEG, PNG, or WebP, up to 4 MB)<input type="file" name="field_image" accept="image/jpeg,image/png,image/webp" required></label><button>Upload field image</button></form></section>'; }echo '<h2>Users</h2>';foreach(query('SELECT name,role,position FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $u)echo '<p>'.h($u['name']).' · '.h($u['role']).' '.h($u['position']).'</p>';endpage();}
elseif($p==='matches'){page('Match schedule',true);if($e){echo '<p>'.h($e['name']).'</p><div class="scroll"><table class="match-table"><thead><tr><th>Match</th>';foreach(['R1','R2','R3','B1','B2','B3'] as $pos){$alliance=$pos[0]==='R'?'red':'blue';echo '<th class="'.h($alliance).'-head">'.h($pos).'</th>';}echo '</tr></thead><tbody>';foreach(query('SELECT * FROM matches WHERE event_id=? ORDER BY match_number',[$e['id']]) as $m){echo '<tr><th>Q'.h($m['match_number']).'</th>';foreach(['R1','R2','R3','B1','B2','B3'] as $pos){$alliance=$pos[0]==='R'?'red':'blue';$s=query('SELECT * FROM slots WHERE match_id=? AND position=?',[$m['id'],$pos])->fetch(PDO::FETCH_ASSOC);echo '<td class="'.h($alliance).'-cell">';if($s)echo '<div class="slot-line"><strong>'.h($s['team_number']).'</strong><a class="scout-button" href="/?p=scout&id='.h($s['id']).'">Scout</a></div><small>'.h($s['status']).'</small>';else echo '—';echo '</td>';}echo '</tr>';}echo '</tbody></table></div>';}endpage();}
elseif($p==='scout'){
 $s=query('SELECT s.*,m.match_number,m.event_id,m.video_url,sc.data,sc.version,sc.status AS record_status FROM slots s JOIN matches m ON m.id=s.match_id LEFT JOIN scouting sc ON sc.slot_id=s.id WHERE s.id=?',[$_GET['id']??''])->fetch(PDO::FETCH_ASSOC);
 if(!$s||!$e||$s['event_id']!==$e['id']){http_response_code(404);exit('Slot not found');}
 $d=json_decode($s['data']??'{}',true)?:[];$editable=$s['record_status']!=='submitted'||role('admin','mentor');
 page('Q'.$s['match_number'].' · '.$s['position'].' · Team '.$s['team_number']);
 if($s['video_url'])echo '<p><a href="'.h($s['video_url']).'">Watch match video</a></p>';
 if(!$editable){pathWidget(is_array($d['auto_path']??null)?$d['auto_path']:[],false);echo '<p>Submitted and locked. Ask a mentor to correct this record.</p>';foreach($d as $k=>$v)if(!in_array($k,['auto_path','demo','match_score'],true))echo '<p><b>'.h($k).':</b> '.h($v).'</p>';}
 else{
  echo '<form method="post" action="/?p=scout" class="scout-form">'.csrf().'<input type="hidden" name="slot" value="'.h($s['id']).'"><input type="hidden" name="version" value="'.h($s['version']??0).'">';
  pathWidget(is_array($d['auto_path']??null)?$d['auto_path']:[],true);
  foreach(['auto_score'=>'Autonomous score','teleop_score'=>'Teleop score'] as $k=>$label)echo '<label>'.h($label).'<input type="number" min="0" max="9999" step="1" name="'.h($k).'" value="'.(is_numeric($d[$k]??null)?field($d,$k):'').'"></label>';
  foreach(['defense_rating'=>'Defensive Ability','defensive_vulnerability'=>'Defensive Vulnerability (5 = not vulnerable)'] as $k=>$label){$value=is_numeric($d[$k]??null)?max(0,min(5,(float)$d[$k])):0;echo '<label class="rating-label">'.h($label).' <output for="'.h($k).'" id="'.h($k).'Value">'.h(number_format($value,1)).'</output><input type="range" min="0" max="5" step="0.1" name="'.h($k).'" id="'.h($k).'" value="'.h($value).'"></label>';}
  foreach(['endgame'=>'Endgame','defense'=>'Defense observations','penalties'=>'Penalties','breakdown'=>'Breakdown / reliability','notes'=>'Observations'] as $k=>$label)echo '<label>'.h($label).'<textarea name="'.h($k).'">'.field($d,$k).'</textarea></label>';
  echo '<button>Save draft</button> <button name="submit" value="1">Submit</button></form>';
 }
 echo '<script src="'.h(asset('auto-path.js')).'" defer></script>';endpage();
}
elseif($p==='teams'){page('Teams');if($e){echo '<p>'.h($e['name']).' · Browse teams and start pit scouting.</p><div class="team-grid">';foreach(query('SELECT t.number,t.name,ph.uploaded_at,(SELECT count(*) FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=t.event_id AND s.team_number=t.number AND sc.status=\'submitted\') AS reports FROM teams t LEFT JOIN team_photos ph ON ph.event_id=t.event_id AND ph.team_number=t.number WHERE t.event_id=? ORDER BY t.number',[$e['id']]) as $t){$n=h($t['number']);echo '<article class="team-card">';if($t['uploaded_at'])echo '<img class="team-photo" src="/?p=robot_photo&n='.$n.'&v='.rawurlencode($t['uploaded_at']).'" alt="Robot photo for team '.$n.'" loading="lazy">';else echo '<div class="team-photo placeholder" aria-label="No robot photo yet">Robot photo</div>';echo '<div class="team-card-body"><h2><a href="/?p=team&n='.$n.'">'.$n.' · '.($t['name']?h($t['name']):'Unnamed team').'</a></h2><p>'.h($t['reports']).' match reports</p><a class="button" href="/?p=pit&n='.$n.'">Pit Scout</a></div></article>';}echo '</div>';}endpage();}
elseif($p==='team'||$p==='pit'){
 $n=(int)($_GET['n']??0);if(!$e||!$n)go('teams');
 $team=query('SELECT number,name FROM teams WHERE event_id=? AND number=?',[$e['id'],$n])->fetch(PDO::FETCH_ASSOC);
 if(!$team){http_response_code(404);exit('Team not found');}
 $record=query('SELECT * FROM pit WHERE event_id=? AND team_number=?',[$e['id'],$n])->fetch(PDO::FETCH_ASSOC);
 $d=json_decode($record['data']??'{}',true)?:[];
 $photo=query('SELECT uploaded_at FROM team_photos WHERE event_id=? AND team_number=?',[$e['id'],$n])->fetch(PDO::FETCH_ASSOC);
 page('Team '.$n.' · '.($team['name']?:'Unnamed team'));
 if($p==='pit'){
  echo '<section class="photo-widget"><h2>Robot photo</h2>';
  if($photo)echo '<img class="pit-photo" src="/?p=robot_photo&n='.h($n).'&v='.rawurlencode($photo['uploaded_at']).'" alt="Robot photo for team '.h($n).'">';
  else echo '<div class="pit-photo placeholder">Add a robot photo</div>';
  echo '<form method="post" action="/?p=upload_photo" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="team" value="'.h($n).'"><label>Choose robot photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label><button>Upload photo</button><small>JPEG, PNG, or WebP · up to 4 MB</small></form></section><h2>Pit scouting</h2><form method="post" action="/?p=pit">'.csrf().'<input type="hidden" name="team" value="'.h($n).'">';
  $meta=$d['robot_meta']??'';
  echo '<label>Robot Meta<select name="robot_meta" onchange="const custom=this.form.elements.robot_meta_other;custom.parentElement.hidden=this.value!==\'Other\';custom.required=this.value===\'Other\'"><option value="">Choose Robot Meta</option>';
  foreach(['Big Dumper','Turret','Other'] as $option)echo '<option value="'.h($option).'"'.($meta===$option?' selected':'').'>'.h($option).'</option>';
  echo '</select></label><label'.($meta==='Other'?'':' hidden').'>Custom Robot Meta<input name="robot_meta_other" type="text" maxlength="80" value="'.h($d['robot_meta_other']??'').'"'.($meta==='Other'?' required':'').'></label>';
  foreach(['robot'=>'Robot identity','drivetrain'=>'Drivetrain','dimensions'=>'Dimensions','weight'=>'Weight','mechanisms'=>'Mechanisms','scoring'=>'Scoring capabilities','autonomous'=>'Autonomous','endgame'=>'Endgame','strategy'=>'Preferred strategy','reliability'=>'Reliability','requirements'=>'Special requirements','notes'=>'Notes'] as $k=>$label)echo '<label>'.h($label).'<textarea name="'.h($k).'">'.field($d,$k).'</textarea></label>';
  echo '<button>Save pit record</button></form><p><a href="/?p=team&n='.h($n).'">View team profile</a></p>';
 }else{
  echo '<p><a class="button" href="/?p=pit&n='.h($n).'">Pit Scout</a></p>';
  if($photo)echo '<img class="pit-photo" src="/?p=robot_photo&n='.h($n).'&v='.rawurlencode($photo['uploaded_at']).'" alt="Robot photo for team '.h($n).'">';
  $reports=query('SELECT m.match_number,s.position,sc.data FROM slots s JOIN matches m ON m.id=s.match_id JOIN scouting sc ON sc.slot_id=s.id WHERE m.event_id=? AND s.team_number=? AND sc.status=\'submitted\' ORDER BY m.match_number',[$e['id'],$n])->fetchAll(PDO::FETCH_ASSOC);
  $paths=[];foreach($reports as $r){$v=json_decode($r['data'],true)?:[];if(!empty($v['auto_path'])&&is_array($v['auto_path']))$paths[]=['label'=>'Q'.$r['match_number'].' · '.$r['position'],'strokes'=>$v['auto_path']];}
  echo '<section class="path-widget"><h2>Combined autonomous paths</h2>';
  if($paths)echo '<p>Each match has its own color. Paths are drawn over the same field image.</p><canvas id="teamPathsCanvas" width="800" height="480" data-paths="'.h(json_encode($paths)).'" aria-label="Combined autonomous paths for team '.h($n).'"></canvas><div id="pathLegend" class="path-legend"></div>';
  else echo '<p>No autonomous paths have been saved for this team yet.</p>';
  echo '</section><h2>Pit notes</h2><section>';
  if(robotMeta($d)!=='')echo '<p><b>Robot Meta:</b> '.h(robotMeta($d)).'</p>';
  foreach($d as $key=>$value)if(!in_array($key,['demo','robot_meta','robot_meta_other'],true)&&$value!=='')echo '<p><b>'.h(ucfirst($key)).':</b> '.h($value).'</p>';
  if(!$d)echo '<p>No pit record yet.</p>';echo '</section><h2>Match reports</h2>';
  foreach($reports as $r){$v=json_decode($r['data'],true)?:[];echo '<section><b>Q'.h($r['match_number']).' '.h($r['position']).'</b><p>'.h($v['notes']??'').'</p></section>';}
  echo '<script src="'.h(asset('auto-path.js')).'" defer></script>';
 }
 endpage();
}
elseif($p==='strategy'){
 page('Strategy',true);
 if(!$e){echo '<p>Select an event in Admin to create strategy plans.</p>';endpage();}
 else{
  $plans=query('SELECT id,title,updated_at FROM strategy_plans WHERE event_id=? ORDER BY updated_at DESC',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
  $id=(string)($_GET['id']??'');$plan=$id?query('SELECT * FROM strategy_plans WHERE id=? AND event_id=?',[$id,$e['id']])->fetch(PDO::FETCH_ASSOC):false;
  if($id&&!$plan){http_response_code(404);exit('Strategy plan not found');}
  $editor=role('admin','mentor','drive');$choices=query('SELECT number,name FROM teams WHERE event_id=? ORDER BY number',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
  $partners=$plan?json_decode($plan['teams'],true):[['number'=>0,'color'=>'#cf1836'],['number'=>0,'color'=>'#0069b4'],['number'=>0,'color'=>'#008b69']];
  $paths=$plan?json_decode($plan['paths'],true):[[],[],[]];
  echo '<p>'.h($e['name']).' · Select three alliance partners, choose a color for each, draw planned routes, and write match notes.</p><div class="strategy-layout"><div>';
  if($editor){
   echo '<form method="post" action="/?p=strategy" class="strategy-form">'.csrf().'<input type="hidden" name="id" value="'.h($plan['id']??'').'"><label>Plan title<input name="title" maxlength="120" value="'.h($plan['title']??''). '" placeholder="Example: Qualification 12 red alliance" required></label><div class="strategy-partners">';
   foreach($partners as $i=>$partner){
    echo '<div><label>Alliance partner '.h($i+1).'<select name="team_'.h($i).'" data-partner="'.h($i).'"><option value="0">Choose team</option>';
    foreach($choices as $choice)echo '<option value="'.h($choice['number']).'"'.((int)$partner['number']===(int)$choice['number']?' selected':'').'>'.h($choice['number'].' · '.$choice['name']).'</option>';
    echo '</select></label><label>Path color<input type="color" name="color_'.h($i).'" data-color="'.h($i).'" value="'.h($partner['color']).'"></label></div>';
   }
   echo '</div><h2>Planned paths</h2><p>Choose the partner to draw, then draw on the field. Undo and Clear affect the selected partner.</p><div class="strategy-tools">';
   for($i=0;$i<3;$i++)echo '<button type="button" class="strategy-layer" data-layer="'.h($i).'">Partner '.h($i+1).'</button>';
   echo '<button type="button" id="strategyUndo">Undo stroke</button><button type="button" id="strategyClear">Clear partner path</button></div>';
   echo '<canvas id="strategyCanvas" width="800" height="480" data-plan="'.h(json_encode(['teams'=>$partners,'paths'=>$paths])).'" aria-label="Strategy field drawing"></canvas><input type="hidden" name="strategy_paths" id="strategyPaths" value="'.h(json_encode($paths)).'"><label>Plan notes<textarea name="notes" rows="7">'.h($plan['notes']??'').'</textarea></label><button>Save strategy plan</button></form>';
  }elseif($plan){
   echo '<h2>'.h($plan['title']).'</h2><canvas id="strategyCanvas" width="800" height="480" data-plan="'.h(json_encode(['teams'=>$partners,'paths'=>$paths])).'" aria-label="Strategy field drawing"></canvas><div class="path-legend">';
   foreach($partners as $partner)if($partner['number'])echo '<span><i style="background:'.h($partner['color']).'"></i>Team '.h($partner['number']).'</span>';
   echo '</div><p>'.nl2br(h($plan['notes'])).'</p>';
  }else echo '<p>Choose a saved plan to view it.</p>';
  echo '</div><aside class="strategy-list"><h2>Saved plans</h2>';
  if($editor)echo '<p><a class="button" href="/?p=strategy">New plan</a></p>';
  foreach($plans as $item){
   echo '<div class="strategy-plan-row'.($plan&&$plan['id']===$item['id']?' current':'').'"><div class="strategy-plan-name"><strong>'.h($item['title']).'</strong><small>'.h($item['updated_at']).'</small></div><a class="button strategy-load" href="/?p=strategy&id='.h($item['id']).'" aria-label="Load plan '.h($item['title']).'">Load</a>';
   if($editor)echo '<form class="strategy-delete-form" method="post" action="/?p=strategy_delete" onsubmit="return confirm(&quot;Delete this strategy plan?&quot;)">'.csrf().'<input type="hidden" name="id" value="'.h($item['id']).'"><button type="submit" class="strategy-delete" aria-label="Delete plan '.h($item['title']).'" title="Delete plan '.h($item['title']).'"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6m4-6v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form>';
   echo '</div>';
  }
  if(!$plans)echo '<p>No plans saved yet.</p>';
  echo '</aside></div><script src="'.h(asset('strategy.js')).'" defer></script>';endpage();
 }
}
elseif($p==='picks'){
 page('Alliance pick list',true);
 if(!$e){echo '<p>Select an event in Admin to view its pick list.</p>';endpage();}
 else{
  $cards=pickRows($e['id']);$editor=role('admin','mentor','drive');
  $reports=query('SELECT s.team_number,sc.data FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status=\'submitted\' ORDER BY sc.updated_at DESC',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
  $byTeam=[];foreach($reports as $report)$byTeam[$report['team_number']][]=json_decode($report['data'],true)?:[];
  $groups=array_fill_keys(pickBuckets(),[]);$already=[];
  foreach($cards as $card){$bucket=in_array($card['bucket']??'B',pickBuckets(),true)?($card['bucket']??'B'):'B';if(picked($card['picked']))$already[]=$card;else $groups[$bucket][]=$card;}
  echo '<div class="pick-board-scroll"><div class="pick-board" id="pickBoard">';
  foreach(array_merge($groups,['Already Picked'=>$already]) as $bucket=>$bucketCards){
   if($bucket==='Already Picked')echo '</div></div>';
   echo '<section class="pick-bucket'.($bucket==='Already Picked'?' already-picked':'').'" data-bucket="'.h($bucket).'"><div class="pick-bucket-head"><h2>'.h($bucket).'</h2><span>'.h(count($bucketCards)).' teams</span></div><div class="pick-cards" data-bucket="'.h($bucket).'">';
   foreach($bucketCards as $card){
   $num=(int)$card['number'];$isPicked=picked($card['picked']);$isDnp=($card['bucket']??'B')==='DNP';
   $entries=$byTeam[$num]??[];$metrics=[];
   foreach(['match_score'=>'Avg Match Score','auto_score'=>'Avg Autonomous','teleop_score'=>'Avg Teleop','defense_rating'=>'Avg Defensive Ability','defensive_vulnerability'=>'Avg Defensive Vulnerability'] as $key=>$label)$metrics[$label]=metricAverage($entries,$key,in_array($key,['defense_rating','defensive_vulnerability'],true)?5.0:null);
   echo '<article class="pick-card'.($isPicked?' picked':'').($isDnp?' do-not-pick':'').'" data-team="'.h($num).'"'.($editor&&!$isPicked?' draggable="true"':'').'><div class="pick-card-check">';
   if($editor&&!$isPicked)echo '<button type="button" class="pick-drag" aria-label="Drag team '.h($num).' to reorder or change bucket" title="Drag to reorder; arrow keys also work"><span aria-hidden="true">⋮</span></button>';
   echo '</div>';
   echo '<div class="pick-card-title"><h2><a href="/?p=team&n='.h($num).'">'.h($num).' · '.h($card['name']?:'Unnamed team').'</a></h2></div>';
   echo '<div class="pick-card-picked">';
   if($editor)echo '<form method="post" action="/?p=pick_status">'.csrf().'<input type="hidden" name="team" value="'.h($num).'"><label title="Mark team picked"><input type="checkbox" name="picked" value="1" aria-label="Team '.h($num).' picked"'.($isPicked?' checked':'').' onchange="this.form.requestSubmit()"><span>Picked</span></label></form>';
   else echo '<span>'.($isPicked?'✓ Picked':'').'</span>';
   echo '</div>';
   echo '<button type="button" class="pick-expand" aria-expanded="false" aria-controls="pick-details-'.h($num).'" aria-label="Show details for team '.h($num).'" title="Show details"><span aria-hidden="true">⌄</span></button>';
   echo '<div class="pick-card-content" id="pick-details-'.h($num).'" hidden>';
   if($card['uploaded_at'])echo '<img class="pick-card-photo" src="/?p=robot_photo&n='.h($num).'&v='.rawurlencode($card['uploaded_at']).'" alt="Robot photo for team '.h($num).'" loading="lazy">';
   else echo '<div class="pick-card-photo placeholder pick-photo-empty" role="img" aria-label="No robot photo for team '.h($num).'"><svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path d="M24 5v6m-4-6h8M10 18h28v23H10zM6 24h4m28 0h4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18" cy="27" r="2" fill="currentColor"/><circle cx="30" cy="27" r="2" fill="currentColor"/><path d="M18 35h12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg><span>No photo</span></div>';
   echo '<div class="pick-stats">';
   $meta=robotMeta(json_decode($card['pit_data']??'{}',true)?:[]);
   echo '<span class="pick-stat"><span>Robot Meta:</span><strong>'.h($meta?:'—').'</strong></span>';
   foreach($metrics as $label=>$value)echo '<span class="pick-stat"><span>'.h($label).':</span><strong>'.h($value===null?'—':number_format($value,1)).'</strong></span>';
   echo '<span class="pick-stat"><span>Reports:</span><strong>'.h(count($entries)).'</strong></span></div><div class="pick-card-flags">';
   if($editor)echo '<form method="post" action="/?p=pick_dnp">'.csrf().'<input type="hidden" name="team" value="'.h($num).'"><label><input type="checkbox" name="dnp" value="1" aria-label="Do Not Pick team '.h($num).'"'.(picked($card['do_not_pick'])?' checked':'').' onchange="this.form.requestSubmit()"><span>Do Not Pick</span></label></form>';
   else echo '<span>'.(picked($card['do_not_pick'])?'Do Not Pick':'').'</span>';
   echo '</div></div></article>';
   }
   echo '</div></section>';
  }
  if(!$cards)echo '<p>No teams have been loaded for this event.</p>';
  if($editor)echo '<form id="pickOrderForm" method="post" action="/?p=pick_order">'.csrf().'<input type="hidden" name="order" id="pickOrder"></form>';
  echo '<script src="'.h(asset('pick-list.js')).'" defer></script>';
  endpage();
 }
}
else go('home');

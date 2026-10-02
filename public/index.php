<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/team-charts.php';
function db(): PDO { static $db; if (!$db) { $db = new PDO(getenv('DATABASE_URL'), getenv('DB_USER'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec(file_get_contents('/var/www/sql/schema.sql')); if (!(int)$db->query('SELECT count(*) FROM users')->fetchColumn()) { $q=$db->prepare('INSERT INTO users(id,name,password_hash,role) VALUES(?,?,?,?)');$q->execute([uuid(),'admin',password_hash('change-me-now',PASSWORD_DEFAULT),'admin']); } } return $db; }
function uuid(): string { $x=bin2hex(random_bytes(16));return substr($x,0,8).'-'.substr($x,8,4).'-4'.substr($x,13,3).'-'.dechex((hexdec($x[16])&3)|8).substr($x,17,3).'-'.substr($x,20); }
function query(string $sql,array $args=[]): PDOStatement { $q=db()->prepare($sql);$q->execute($args);return $q; }
function h($v): string { return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8'); }
function go(string $p): never { header('Location: /?p='.$p);exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function asset(string $name): string { $path=__DIR__.'/'.$name;$hash=is_file($path)?hash_file('sha256',$path):false;return '/'.$name.'?v='.($hash?substr($hash,0,12):'missing'); }
function role(...$roles): bool { return in_array($_SESSION['user']['role']??'', $roles,true); }
function event(): ?array { return query('SELECT * FROM events WHERE active=true ORDER BY name LIMIT 1')->fetch(PDO::FETCH_ASSOC)?:null; }
function siteSettings(): array { static $settings;return $settings??=query('SELECT dark_mode,logo_mime,logo_uploaded_at,site_title,highlighted_team FROM site_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC); }
function teamNumber($number): string { $highlighted=(int)$number===(int)siteSettings()['highlighted_team'];return '<span class="team-number'.($highlighted?' highlighted-team':'').'"'.($highlighted?' title="Team running this scouting system"':'').'>'.h($number).'</span>'; }
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
 $playoffHtml=@file_get_contents($base.'/playoffs',false,$ctx);
 $teams=[];$matches=[];$videos=[];$playoffs=[];
 if($eventHtml && preg_match_all('~<div class="col-3 col-md-1 fw-bold">\s*(\d+)\s*</div>\s*<div class="col-6">\s*([^<]+)\s*</div>~si',$eventHtml,$found,PREG_SET_ORDER))foreach($found as $m)$teams[(int)$m[1]]=html_entity_decode(trim($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');
 if($qualHtml && preg_match_all('~<tr\b[^>]*\bid="match(\d+)a"[^>]*>(.*?)</tr>~si',$qualHtml,$rows,PREG_SET_ORDER))foreach($rows as $row){preg_match_all('~href="/'.$year.'/team/(\d+)"~si',$row[2],$numbers);$six=array_slice(array_map('intval',$numbers[1]),0,6);if(count($six)===6&&min($six)>0){$number=(int)$row[1];$matches[$number]=$six;$videos[$number]=preg_match('~\btitle=["\']Match Video Available["\']~i',$row[2])?$base.'/qualifications/'.$number:null;}}
 $playoffs=$playoffHtml?parsePlayoffs($playoffHtml,$year,$code):[];
 return [$teams,$matches,(bool)$eventHtml,(bool)$qualHtml,$videos,$playoffs,(bool)$playoffHtml];
}
function saveOfficialData(string $eventId,array $teams,array $matches,?array $videos=null,string $stage='qualification',array $labels=[]): int {
 foreach($teams as $number=>$name)query('INSERT INTO teams(event_id,number,name) VALUES(?,?,?) ON CONFLICT(event_id,number) DO UPDATE SET name=EXCLUDED.name',[$eventId,$number,$name]);
 $protected=0;
 foreach($matches as $number=>$six){
  $mid=$videos===null
   ?query('INSERT INTO matches(id,event_id,stage,match_number,label) VALUES(?,?,?,?,?) ON CONFLICT(event_id,stage,match_number) DO UPDATE SET label=COALESCE(EXCLUDED.label,matches.label) RETURNING id',[uuid(),$eventId,$stage,$number,$labels[$number]??null])->fetchColumn()
   :query('INSERT INTO matches(id,event_id,stage,match_number,label,video_url) VALUES(?,?,?,?,?,?) ON CONFLICT(event_id,stage,match_number) DO UPDATE SET label=COALESCE(EXCLUDED.label,matches.label),video_url=EXCLUDED.video_url RETURNING id',[uuid(),$eventId,$stage,$number,$labels[$number]??null,$videos[$number]??null])->fetchColumn();
  foreach(['R1','R2','R3','B1','B2','B3'] as $i=>$pos){
   $team=$six[$i];query('INSERT INTO teams(event_id,number) VALUES(?,?) ON CONFLICT DO NOTHING',[$eventId,$team]);
   $old=query('SELECT id,team_number FROM slots WHERE match_id=? AND position=?',[$mid,$pos])->fetch(PDO::FETCH_ASSOC);
   if($old && (int)$old['team_number']!==$team && query('SELECT count(*) FROM scouting WHERE slot_id=?',[$old['id']])->fetchColumn()){$protected++;continue;}
   query('INSERT INTO slots(id,match_id,position,team_number) VALUES(?,?,?,?) ON CONFLICT(match_id,position) DO UPDATE SET team_number=EXCLUDED.team_number',[uuid(),$mid,$pos,$team]);
  }
 }
 return $protected;
}
function parsePlayoffs(string $html,int $year,string $code): array {
 $base="https://frc-events.firstinspires.org/$year/".rawurlencode($code);$result=[];
 if(!preg_match_all('~<tr\b[^>]*\bid="match(\d+)a"[^>]*>(.*?)</tr>\s*<tr\b[^>]*>(.*?)</tr>~si',$html,$rows,PREG_SET_ORDER))return [];
 foreach($rows as $row){
  $number=(int)$row[1];
  if(!preg_match('~<a\b[^>]*href="/'.$year.'/'.preg_quote($code,'~').'/playoffs/'.$number.'"[^>]*>(.*?)</a>~si',$row[2],$link))continue;
  $label=trim(preg_replace('/\s+/u',' ',str_replace("\xc2\xa0",' ',html_entity_decode(strip_tags($link[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'))));
  preg_match_all('~href="/'.$year.'/team/(\d+)"~si',$row[3],$numbers);$six=array_slice(array_map('intval',$numbers[1]),0,6);
  $alliances=[];$scores=[];
  preg_match_all('~<td\b[^>]*>(.*?)</td>~si',$row[2],$cells);
  foreach($cells[1] as $cell){if(preg_match('/Alliance\s+(\d+)/i',strip_tags($cell),$m))$alliances[]=(int)$m[1];if(preg_match('~<h3\b[^>]*>\s*(\d+)\s*</h3>~si',$cell,$m))$scores[]=(int)$m[1];}
  $round=preg_match('/\(R(\d+)\)/',$label,$m)?(int)$m[1]:null;
  $result[$number]=['teams'=>count($six)===6&&min($six)>0?$six:[],'label'=>$label?:'Match '.$number,'round'=>$round,'red_alliance'=>$alliances[0]??null,'blue_alliance'=>$alliances[1]??null,'red_score'=>$scores[0]??null,'blue_score'=>$scores[1]??null,'video_url'=>preg_match('~\btitle=["\']Match Video Available["\']~i',$row[2])?$base.'/playoffs/'.$number:null];
 }
 if(($result[1]['red_alliance']??null)===1&&($result[1]['blue_alliance']??null)===8){
  $sources=[5=>'Loser M1 · Loser M2',6=>'Loser M3 · Loser M4',7=>'Winner M1 · Winner M2',8=>'Winner M3 · Winner M4',9=>'Loser M7 · Winner M6',10=>'Loser M8 · Winner M5',11=>'Winner M7 · Winner M8',12=>'Winner M10 · Winner M9',13=>'Loser M11 · Winner M12'];
  foreach($result as $number=>&$match){$match['source']=$sources[$number]??(stripos($match['label'],'Final')!==false?'Winner M11 · Winner M13':null);}unset($match);
 }
 return $result;
}
function savePlayoffs(string $eventId,array $playoffs): int {
 $matches=[];$videos=[];$labels=[];
 foreach($playoffs as $number=>$row)if(count($row['teams'])===6){$matches[$number]=$row['teams'];$videos[$number]=$row['video_url'];$labels[$number]=$row['label'];}
 $protected=saveOfficialData($eventId,[],$matches,$videos,'elimination',$labels);
 foreach($playoffs as $number=>$row)query("INSERT INTO matches(id,event_id,stage,match_number,label,video_url,official_result) VALUES(?,?,'elimination',?,?,?,?) ON CONFLICT(event_id,stage,match_number) DO UPDATE SET label=EXCLUDED.label,video_url=EXCLUDED.video_url,official_result=EXCLUDED.official_result",[uuid(),$eventId,$number,$row['label'],$row['video_url'],json_encode($row,JSON_THROW_ON_ERROR)]);
 return $protected;
}
function renderFirstStyleBracket(array $rows): void {
 // FIRST's eight-alliance layout: upper bracket at the top, lower bracket below.
 $matches=[];$finals=[];
 foreach($rows as $row){if(stripos($row['label']??'','Final')!==false)$finals[]=$row;else $matches[(int)$row['match_number']]=$row;}
 $layout=[1=>[0,80],2=>[0,190],3=>[0,300],4=>[0,410],7=>[1,135],8=>[1,355],5=>[1,465],6=>[1,575],10=>[2,465],9=>[2,575],11=>[3,245],12=>[3,520],13=>[4,465]];
 $origins=[5=>'Loser M1 · Loser M2',6=>'Loser M3 · Loser M4',7=>'Winner M1 · Winner M2',8=>'Winner M3 · Winner M4',9=>'Loser M7 · Winner M6',10=>'Loser M8 · Winner M5',11=>'Winner M7 · Winner M8',12=>'Winner M10 · Winner M9',13=>'Loser M11 · Winner M12'];
 $paths=[
  'M200 80 H230 V190 H200','M230 135 H260',
  'M200 300 H230 V410 H200','M230 355 H260',
  'M460 135 H490 V355 H460','M490 245 H780',
  'M460 465 H520','M460 575 H520',
  'M720 465 H750 V575 H720','M750 520 H780',
  'M980 520 H1010 V465 H1040',
  'M980 245 H1270 V465 H1240','M1270 355 H1300'
 ];
 echo '<div class="playoff-bracket first-style-bracket" aria-label="Playoff bracket"><div class="first-bracket-board"><svg class="first-bracket-lines" width="1500" height="640" viewBox="0 0 1500 640" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
 foreach($paths as $path)echo '<path d="'.h($path).'" fill="none" stroke="#9aa3ad" stroke-width="2" stroke-linejoin="round"/>';
 echo '</svg>';
 foreach(['Round 1','Round 2','Round 3','Round 4','Round 5','Finals'] as $i=>$title)echo '<h3 class="first-round-title" style="left:'.h($i*260/1500*100).'%">'.h($title).'</h3>';
 foreach($layout as $number=>[$column,$center]){
  $match=$matches[$number]??null;$data=$match?json_decode($match['official_result']??'{}',true):[];$data=$data?:[];
  $round=[1=>1,2=>1,3=>1,4=>1,5=>2,6=>2,7=>2,8=>2,9=>3,10=>3,11=>4,12=>4,13=>5][$number];
  $label=$match?matchLabel($match):'Match '.$number.' (R'.$round.')';
  echo '<article class="first-match-card" data-match="'.h($number).'" style="left:'.h($column*260/1500*100).'%;top:'.h($center-54).'px"><div class="first-match-header">';
  if($match)echo '<a href="/?p=match&stage=elimination&n='.h($number).'">'.h($label).'</a>';else echo '<strong>'.h($label).'</strong>';
  if($match&&$match['video_url'])echo '<a class="video-link" href="'.h($match['video_url']).'" target="_blank" rel="noopener noreferrer" title="Watch match video" aria-label="Watch '.h($label).' video">▶</a>';
  echo '</div>';
  $six=$data['teams']??[];
  if(!$six&&$match){$slots=query('SELECT position,team_number FROM slots WHERE match_id=?',[$match['id']])->fetchAll(PDO::FETCH_KEY_PAIR);foreach(['R1','R2','R3','B1','B2','B3'] as $pos)$six[]=$slots[$pos]??0;}
  foreach(['red','blue'] as $i=>$color){$alliance=$data[$color.'_alliance']??null;$score=$data[$color.'_score']??null;
   echo '<div class="first-alliance '.$color.'"><div><strong>'.($alliance?'Alliance '.h($alliance):'TBD').'</strong><div class="first-team-links">';
   foreach(array_filter(array_slice($six,$i*3,3)) as $team)echo '<a href="/?p=team&n='.h($team).'">'.teamNumber($team).'</a>';
   echo '</div></div><strong>'.($score===null?'—':h($score)).'</strong></div>';
  }
  if(isset($origins[$number]))echo '<small class="first-match-source">'.h($origins[$number]).'</small>';
  echo '</article>';
 }
 echo '<article class="first-finals-card" style="left:86.666667%;top:280px"><h3>FINALS</h3>';
 $allianceNumbers=[];$wins=[];
 foreach($finals as $final){$data=json_decode($final['official_result']??'{}',true)?:[];foreach(['red','blue'] as $color){$alliance=$data[$color.'_alliance']??null;if($alliance&&!in_array($alliance,$allianceNumbers,true))$allianceNumbers[]=$alliance;}if(isset($data['red_score'],$data['blue_score'])&&$data['red_score']!==$data['blue_score']){$winner=$data[$data['red_score']>$data['blue_score']?'red_alliance':'blue_alliance']??null;if($winner)$wins[$winner]=($wins[$winner]??0)+1;}}
 for($i=0;$i<2;$i++){$alliance=$allianceNumbers[$i]??null;echo '<div class="first-alliance '.($i?'blue':'red').'"><strong>'.($alliance?'Alliance '.h($alliance):'TBD').'</strong><strong>'.($alliance?h($wins[$alliance]??0):'—').'</strong></div>';}
 foreach($finals as $final){$data=json_decode($final['official_result']??'{}',true)?:[];echo '<div class="first-final-result"><a href="/?p=match&stage=elimination&n='.h($final['match_number']).'">'.h(matchLabel($final)).'</a><span>'.h($data['red_score']??'—').'–'.h($data['blue_score']??'—').'</span>';if($final['video_url'])echo '<a class="video-link" href="'.h($final['video_url']).'" target="_blank" rel="noopener noreferrer" aria-label="Watch '.h(matchLabel($final)).' video">▶</a>';echo '</div>';}
 echo '<small class="first-match-source">Winner M11 · Winner M13</small></article></div></div>';
}
function renderPlayoffBracket(array $rows): void {
 foreach($rows as $row)if((int)$row['match_number']===4&&preg_match('/^Match 4 \(R1\)$/',$row['label']??'')){renderFirstStyleBracket($rows);return;}
 $rounds=[];$sources=[];
 foreach($rows as $row)if((int)$row['match_number']===4&&preg_match('/^Match 4 \(R1\)$/',$row['label']??'')){
  $sources=[5=>'Loser M1 · Loser M2',6=>'Loser M3 · Loser M4',7=>'Winner M1 · Winner M2',8=>'Winner M3 · Winner M4',9=>'Loser M7 · Winner M6',10=>'Loser M8 · Winner M5',11=>'Winner M7 · Winner M8',12=>'Winner M10 · Winner M9',13=>'Loser M11 · Winner M12'];break;
 }
 foreach($rows as $row){$data=json_decode($row['official_result']??'{}',true)?:[];$round=$data['round']??(preg_match('/\(R(\d+)\)/',$row['label']??'',$m)?(int)$m[1]:null);$group=$round?'Round '.$round:(stripos($row['label']??'','Final')!==false?'Finals':'Playoffs');$rounds[$group][]=$row;}
 $positions=[];$height=max(500,max(array_map('count',$rounds))*220+44);$width=count($rounds)*205+max(0,count($rounds)-1)*72;$column=0;
 foreach($rounds as $matches){foreach($matches as $i=>$match){$positions[(int)$match['match_number']]=['x'=>$column*277,'y'=>44+($height-44)/count($matches)*($i+.5)-95];}$column++;}
 $edges=[];
 foreach($rounds as $matches)foreach($matches as $match){
  $data=json_decode($match['official_result']??'{}',true)?:[];$label=matchLabel($match);$source=$data['source']??'';if(!$source&&$sources)$source=$sources[(int)$match['match_number']]??(stripos($label,'Final')!==false?'Winner M11 · Winner M13':'');
  if(preg_match_all('/(Winner|Loser) M(\d+)/',$source,$links,PREG_SET_ORDER))foreach($links as $port=>$link){$from=$positions[(int)$link[2]]??null;$to=$positions[(int)$match['match_number']];if(!$from||$to['x']<=$from['x'])continue;
   $sx=$from['x']+205;$sy=$from['y']+95;$tx=$to['x'];$ty=$to['y']+($port===0?66:133);$kind=strtolower($link[1]);
   if($tx-$sx<100){$mid=$sx+($tx-$sx)*($port===0?.4:.6);$path="M$sx $sy H$mid V$ty H".($tx-3);}else{$lane=5+(count($edges)%4)*7;$path="M$sx $sy H".($sx+18)." V$lane H".($tx-18)." V$ty H".($tx-3);}
   $edges[]=['kind'=>$kind,'path'=>$path,'title'=>$link[1].' of match '.$link[2].' advances to '.$label];
  }
 }
 echo '<div class="playoff-legend"><span class="winner-route">Solid green arrow: winner advances</span><span class="loser-route">Dashed orange arrow: loser advances</span></div><div class="playoff-bracket" aria-label="Playoff bracket"><div class="playoff-board" style="width:'.h($width).'px;height:'.h($height).'px"><svg class="playoff-connectors" width="'.h($width).'" height="'.h($height).'" viewBox="0 0 '.h($width).' '.h($height).'" aria-hidden="true" focusable="false"><defs><marker id="playoff-winner" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0 0 L10 5 L0 10 Z" class="winner-arrow" fill="#23864a"/></marker><marker id="playoff-loser" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0 0 L10 5 L0 10 Z" class="loser-arrow" fill="#c76a17"/></marker></defs>';
 foreach($edges as $edge)echo '<path class="'.h($edge['kind']).'-edge" d="'.h($edge['path']).'" fill="none" stroke="'.($edge['kind']==='winner'?'#23864a':'#c76a17').'" stroke-width="3"'.($edge['kind']==='loser'?' stroke-dasharray="6 4"':'').' marker-end="url(#playoff-'.h($edge['kind']).')"><title>'.h($edge['title']).'</title></path>';
 echo '</svg>';$column=0;
 foreach($rounds as $round=>$matches){echo '<div class="playoff-round" style="left:'.h($column++*277).'px"><h3>'.h($round).'</h3>';
  foreach($matches as $match){$data=json_decode($match['official_result']??'{}',true)?:[];$label=matchLabel($match);if(empty($data['source'])&&$sources)$data['source']=$sources[(int)$match['match_number']]??(stripos($label,'Final')!==false?'Winner M11 · Winner M13':'');$red=$data['red_score']??null;$blue=$data['blue_score']??null;
   echo '<article class="playoff-card" style="top:'.h($positions[(int)$match['match_number']]['y']).'px" data-match="'.h($match['match_number']).'" data-source="'.h($data['source']??'').'"><div class="playoff-card-header"><a href="/?p=match&stage=elimination&n='.h($match['match_number']).'">'.h($label).'</a>';
   if($match['video_url'])echo '<a class="video-link" href="'.h($match['video_url']).'" target="_blank" rel="noopener noreferrer" aria-label="Watch '.h($label).' video" title="Watch match video"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M8 5.5v13l10-6.5z"/></svg></a>';
   echo '</div>';
   $six=$data['teams']??[];
   if(!$six){$slots=query('SELECT position,team_number FROM slots WHERE match_id=?',[$match['id']])->fetchAll(PDO::FETCH_KEY_PAIR);foreach(['R1','R2','R3','B1','B2','B3'] as $pos)$six[]=$slots[$pos]??0;}
   foreach(['red','blue'] as $i=>$color){$score=$i?$blue:$red;$opponent=$i?$red:$blue;$alliance=$data[$color.'_alliance']??null;
    echo '<div class="playoff-alliance '.$color.($score!==null&&$opponent!==null&&$score>$opponent?' winner':'').'"><div><strong>'.($alliance?'Alliance '.h($alliance):ucfirst($color).' alliance').'</strong><div class="playoff-teams">';
    $teams=array_filter(array_slice($six,$i*3,3));if(!$teams)echo '<span>Teams TBD</span>';foreach($teams as $team)echo '<a href="/?p=team&n='.h($team).'">'.teamNumber($team).'</a>';
    echo '</div></div><strong class="playoff-score">'.($score===null?'—':h($score)).'</strong></div>';
   }
   echo '<small class="playoff-state">'.($red!==null&&$blue!==null?'Official result':'Awaiting result').'</small>';if(!empty($data['source']))echo '<small class="playoff-state">'.h($data['source']).'</small>';echo '</article>';
  }echo '</div>';
 }echo '</div></div>';
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
function pitTags(array $data): array {
 $tags=[];foreach((is_array($data['tags']??null)?$data['tags']:[]) as $tag)if(is_string($tag)&&trim($tag)!==''){$tag=trim($tag);if(strlen($tag)<=40&&!in_array(strtolower($tag),array_map('strtolower',$tags),true))$tags[]=$tag;}
 return array_slice($tags,0,12);
}
function pitChoiceFields(): array {return ['robot_meta'=>['Robot Meta',['Big Dumper','Turret','Other']],'intake'=>['Intake',['Ground intake','Source intake','Dual intake','None']],'shooter_type'=>['Shooter Type',['Turret','Fixed','Hooded','Flywheel','None']],'tags'=>['Tag Management',['Defense','Passing','L3 Climber','Offense']],'driver_experience'=>['Driver Experience Level',['New','Developing','Experienced','Veteran']],'human_player_experience'=>['Human Player Experience Level',['New','Developing','Experienced','Veteran']]];}
function pitChoices(): array {
 $saved=json_decode(query('SELECT pit_choices FROM site_settings WHERE id=1')->fetchColumn()?:'{}',true)?:[];
 $choices=[];foreach(pitChoiceFields() as $key=>[, $defaults])$choices[$key]=isset($saved[$key])&&is_array($saved[$key])?$saved[$key]:$defaults;
 return $choices;
}
function pitChoicesWithSaved(array $choices,string $key,string $saved): array {
 $options=$choices[$key];if($saved!==''&&!in_array($saved,$options,true))$options[]=$saved;return $options;
}
function pitTagColors(): array {
 static $colors;if($colors!==null)return $colors;
 $saved=json_decode(query('SELECT pit_choices FROM site_settings WHERE id=1')->fetchColumn()?:'{}',true)?:[];
 return $colors=is_array($saved['tag_colors']??null)?$saved['tag_colors']:[];
}
function tagColor(string $tag): string {
 $defaults=['Defense'=>'#d9edff','Passing'=>'#fff0c6','L3 Climber'=>'#eee3ff','Offense'=>'#ffe1e4'];
 $color=pitTagColors()[$tag]??($defaults[$tag]??'#d9f3f0');return is_string($color)&&preg_match('/^#[0-9a-fA-F]{6}$/',$color)?$color:'#d9f3f0';
}
function tagStyle(string $tag): string {
 $color=tagColor($tag);$r=hexdec(substr($color,1,2));$g=hexdec(substr($color,3,2));$b=hexdec(substr($color,5,2));
 return ' style="background:'.h($color).';border-color:'.h($color).';color:'.(($r*299+$g*587+$b*114)/1000>150?'#172438':'#ffffff').'"';
}
function pitChoiceUsage(string $key,string $value): array {
 if($key==='tags'){
  $encoded=json_encode([$value],JSON_THROW_ON_ERROR);
  return [(int)query("SELECT count(*) FROM pit WHERE data->'tags' @> CAST(? AS jsonb)",[$encoded])->fetchColumn(),(int)query("SELECT count(*) FROM scouting WHERE data->'tags' @> CAST(? AS jsonb)",[$encoded])->fetchColumn()];
 }
 return [(int)query('SELECT count(*) FROM pit WHERE data ->> CAST(? AS text) = ?',[$key,$value])->fetchColumn(),(int)query('SELECT count(*) FROM scouting WHERE data ->> CAST(? AS text) = ?',[$key,$value])->fetchColumn()];
}
function tagTone(string $tag): int {
 $presets=['Defense'=>0,'Passing'=>1,'L3 Climber'=>2,'Offense'=>3];
 return $presets[$tag]??(4+crc32(strtolower($tag))%4);
}
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
 echo '<section class="path-widget"><h2>Autonomous path</h2><p>'.($editable?'Draw the robot’s route during autonomous. ':'Saved autonomous route. ').'The same field background is used on team and strategy pages.'.(role('admin')?' <a href="/?p=admin#field-background">Upload a field background</a>.':'').'</p><div class="drawing-stage"><div class="drawing-stage-bar"><strong>Autonomous field</strong><button type="button" class="canvas-fullscreen" aria-pressed="false">Full screen</button></div><canvas id="autoPathCanvas" width="800" height="480" data-strokes="'.h(json_encode($strokes)).'" aria-label="Autonomous field path"></canvas>';
 if($editable)echo '<input type="hidden" name="auto_path" id="autoPathInput" value="'.h(json_encode($strokes)).'"><div class="path-actions"><button type="button" id="pathUndo">Undo last stroke</button><button type="button" id="pathClear">Clear path</button></div>';
 echo '</div></section>';
}
function page(string $title,bool $wide=false,bool $showHeading=true): void {
 $active=$GLOBALS['e']??null;$settings=siteSettings();$year='';
 if($active&&preg_match('/^(\d{4})/',(string)($active['event_key']??''),$match))$year=$match[1];
 echo '<!doctype html><html lang="en"'.(picked($settings['dark_mode'])?' class="dark-mode"':'').'><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).' · '.h($settings['site_title']).'</title><link rel="stylesheet" href="'.h(asset('style.css')).'"><header><div class="header-identity">';
 if($settings['logo_uploaded_at'])echo '<img class="site-logo" src="/?p=site_logo&v='.rawurlencode($settings['logo_uploaded_at']).'" alt="Scouting logo">';
 echo '<strong>'.h($settings['site_title']).'</strong></div>'.($active?'<span class="selected-event">Selected Event: '.h(trim($year.' '.$active['name'])).'</span>':'<span class="selected-event" aria-hidden="true"></span>').'<details class="nav-menu" id="navigationMenu"><summary aria-label="Navigation menu"><span aria-hidden="true">☰</span><span class="sr-only">Menu</span></summary><nav aria-label="Main navigation"><a href="/?p=home">Dashboard</a><a href="/?p=matches">Matches</a><a href="/?p=teams">Pit Scouting</a><a href="/?p=strategy">Strategy</a><a href="/?p=picks">Pick List</a><a href="/?p=admin">Admin</a><a href="/?p=logout">Log out</a></nav></details></header><script src="'.h(asset('navigation.js')).'" defer></script><main'.($wide?' class="wide"':'').'>'.($showHeading?'<h1>'.h($title).'</h1>':'');
 if(isset($_SESSION['flash'])) {echo '<aside class="'.h($_SESSION['flash_type']??'notice').'">'.h($_SESSION['flash']).'</aside>';unset($_SESSION['flash'],$_SESSION['flash_type']);}
}
function renderMatchSection(string $eventId,string $stage): void {
 $rows=query('SELECT * FROM matches WHERE event_id=? AND stage=? ORDER BY match_number',[$eventId,$stage])->fetchAll(PDO::FETCH_ASSOC);
 $title=$stage==='qualification'?'Qualification Matches':'Elimination Matches';
 echo '<details class="match-section '.($stage==='elimination'?'elimination-section':'qualification-section').'" open><summary><span>'.h($title).'</span>';
 if($stage==='elimination'){echo '<button class="refresh-playoffs" type="submit" form="refreshPlayoffsForm" onclick="event.stopPropagation()">Refresh Playoff Data</button>';}
 echo '<small>'.count($rows).' matches</small></summary>';
 if($stage==='elimination')echo '<form id="refreshPlayoffsForm" class="refresh-playoffs-form" method="post" action="/?p=refresh_playoffs">'.csrf().'</form>';

 if(!$rows){echo '<p class="empty-matches">No '.h(strtolower($title)).' have been loaded yet. Refresh official data when FIRST publishes them.</p></details>';return;}
 if($stage==='elimination')renderPlayoffBracket($rows);
 echo '<div class="scroll"><table class="match-table"><colgroup><col class="match-number-col"><col class="match-video-col"><col span="6"></colgroup><thead><tr><th>Match</th><th>Video</th>';
 foreach(['R1','R2','R3','B1','B2','B3'] as $pos)echo '<th class="'.($pos[0]==='R'?'red':'blue').'-head">'.h($pos).'</th>';
 echo '</tr></thead><tbody>';
 foreach($rows as $m){
  $number=(int)$m['match_number'];$label=matchLabel($m);
  echo '<tr><th scope="row"><a class="match-number-link" href="/?p=match&stage='.h($stage).'&n='.$number.'" aria-label="View '.h($title).' '.h($label).'">'.h($label).'</a></th><td class="match-video-cell">';
  if($m['video_url'])echo '<a class="video-link" href="'.h($m['video_url']).'" target="_blank" rel="noopener noreferrer" aria-label="Watch '.h($label).' video" title="Watch match video"><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.5v13l10-6.5z"/></svg></a>';
  else echo '<span class="no-video">No Video</span>';
  echo '</td>';
  foreach(['R1','R2','R3','B1','B2','B3'] as $pos){
   $alliance=$pos[0]==='R'?'red':'blue';$slot=query('SELECT s.*,sc.status AS scouting_status FROM slots s LEFT JOIN scouting sc ON sc.slot_id=s.id WHERE s.match_id=? AND s.position=?',[$m['id'],$pos])->fetch(PDO::FETCH_ASSOC);
   echo '<td class="'.$alliance.'-cell">';
   if($slot)echo '<div class="slot-line"><a class="team-performance-link" href="/?p=team&n='.h($slot['team_number']).'" aria-label="View performance for team '.h($slot['team_number']).'">'.teamNumber($slot['team_number']).'</a><a class="scout-button '.($slot['scouting_status']==='submitted'?'scouted':'unscouted').'" href="/?p=scout&id='.h($slot['id']).'">Scout</a><span class="scouting-status">'.($slot['scouting_status']==='submitted'?'Scouted 1 time':'Not Scouted Yet').'</span></div>';
   else echo '—';
   echo '</td>';
  }
  echo '</tr>';
 }
 echo '</tbody></table></div></details>';
}
function endpage(): void {echo '</main></html>';}
function matchLabel(array $match): string {return ($match['stage']??'qualification')==='elimination'?($match['label']?:'Elimination Match '.$match['match_number']):'Q'.$match['match_number'];}
try { db(); } catch(Throwable $e) { http_response_code(503);exit('Database unavailable. Start Docker Compose and try again.'); }
$p=$_GET['p']??'home';
if($p==='logout'){session_destroy();header('Location: /');exit;}
if($_SERVER['REQUEST_METHOD']==='POST') {
 if($p==='login') { $u=query('SELECT * FROM users WHERE name=?',[trim($_POST['name']??'')])->fetch(PDO::FETCH_ASSOC);if($u&&password_verify($_POST['password']??'',$u['password_hash'])) {session_regenerate_id(true);$_SESSION['user']=['id'=>$u['id'],'name'=>$u['name'],'role'=>$u['role'],'position'=>$u['position']];$_SESSION['csrf']=bin2hex(random_bytes(16));go('home');} $_SESSION['flash']='Invalid login';go('login'); }
 if(!isset($_SESSION['user']) || !hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(403);exit('Invalid session');}
 $e=event();$uid=$_SESSION['user']['id'];
 if($p==='event'&&role('admin')) { $year=(int)($_POST['year']??0);$code=strtoupper(trim($_POST['event_code']??''));$selected=query('SELECT * FROM event_catalog WHERE year=? AND code=?',[$year,$code])->fetch(PDO::FETCH_ASSOC);if(!$selected){http_response_code(400);exit('Choose an event from the list');}[$teams,$matches,$teamPage,$schedulePage,$videos,$playoffs,$playoffPage]=officialData($year,$code);db()->beginTransaction();try{query('UPDATE events SET active=false');$id=query('INSERT INTO events(id,name,event_key,active) VALUES(?,?,?,true) ON CONFLICT(event_key) DO UPDATE SET name=EXCLUDED.name,active=true RETURNING id',[uuid(),$selected['name'],(string)$year.strtolower($code)])->fetchColumn();$protected=saveOfficialData($id,$teams,$matches,$schedulePage?$videos:null);if($playoffPage)$protected+=savePlayoffs($id,$playoffs);db()->commit();}catch(Throwable $ex){db()->rollBack();throw $ex;}$_SESSION['flash_type']='success';$_SESSION['flash']='Selected event: '.$selected['name'].': imported '.count($teams).' teams and '.count($matches).' qualification matches and '.count($playoffs).' elimination matches.'.(!$teamPage||!$schedulePage?' Some official pages were unavailable; use Refresh official data later.':'').($protected?' '.$protected.' scouted positions kept their prior team assignments.':'');go('admin');}
 if($p==='refresh_event'&&role('admin')&&$e&&preg_match('/^(\d{4})([a-z0-9]+)$/',$e['event_key']??'',$parts)){[$teams,$matches,$teamPage,$schedulePage,$videos,$playoffs,$playoffPage]=officialData((int)$parts[1],strtoupper($parts[2]));db()->beginTransaction();try{$protected=saveOfficialData($e['id'],$teams,$matches,$schedulePage?$videos:null);if($playoffPage)$protected+=savePlayoffs($e['id'],$playoffs);db()->commit();}catch(Throwable $ex){db()->rollBack();throw $ex;}$_SESSION['flash']='Refreshed '.count($teams).' teams and '.count($matches).' qualification matches and '.count($playoffs).' elimination matches.'.(!$teamPage||!$schedulePage?' Some official pages were unavailable.':'').($protected?' '.$protected.' scouted positions retained.':'');go('admin');}
 if($p==='refresh_playoffs'){
  if(!$e||!preg_match('/^(\d{4})([a-z0-9]+)$/',$e['event_key']??'',$parts)){$_SESSION['flash']='Select an official event in Admin first.';go('matches');}
  $year=(int)$parts[1];$code=strtoupper($parts[2]);$ctx=stream_context_create(['http'=>['timeout'=>20,'user_agent'=>'2370 Scouting (playoff refresh)','header'=>"Cache-Control: no-cache\r\n"]]);
  $html=@file_get_contents("https://frc-events.firstinspires.org/$year/".rawurlencode($code).'/playoffs',false,$ctx);
  if(!$html||stripos($html,'Playoff Results')===false){$_SESSION['flash']='FIRST playoff data is unavailable. Existing matches and results were kept.';go('matches');}
  $playoffs=parsePlayoffs($html,$year,$code);
  if(!$playoffs){$_SESSION['flash']='FIRST has not published playoff matches yet, or its page could not be read. Existing data was kept.';go('matches');}
  db()->beginTransaction();try{$protected=savePlayoffs($e['id'],$playoffs);db()->commit();}catch(Throwable $ex){db()->rollBack();throw $ex;}
  $_SESSION['flash_type']='success';$_SESSION['flash']='Refreshed '.count($playoffs).' playoff matches, official scores, and video links.'.($protected?' '.$protected.' scouted positions retained their prior assignments.':'');go('matches');
 }
 if($p==='user'&&role('admin')) {if(strlen($_POST['password']??'')<10) {$_SESSION['flash']='Password must be at least 10 characters';go('admin');}query('INSERT INTO users(id,name,password_hash,role,position) VALUES(?,?,?,?,?)',[uuid(),trim($_POST['name']),password_hash($_POST['password'],PASSWORD_DEFAULT),$_POST['role'],$_POST['position']?:null]);go('admin');}
 if($p==='reset_password'&&role('admin')){
  $target=query('SELECT id,name FROM users WHERE id::text=?',[(string)($_POST['user_id']??'')])->fetch(PDO::FETCH_ASSOC);
  if(!$target){http_response_code(404);exit('Account not found');}
  $password=$_POST['new_password']??null;
  if(!is_string($password)||strlen($password)<10||strlen($password)>72||str_contains($password,"\0")){$_SESSION['flash']='Enter a new password of 10–72 bytes.';go('admin#user-management');}
  query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$target['id']]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Password reset for '.$target['name'].'.';go('admin#user-management');
 }
 if($p==='upload_field'&&role('admin')&&$e){
  $upload=$_FILES['field_image']??null;
  if(!$upload||$upload['error']!==UPLOAD_ERR_OK||$upload['size']>4*1024*1024||$upload['size']<1||!is_uploaded_file($upload['tmp_name'])){$_SESSION['flash']='Choose a JPEG, PNG, or WebP field image under 4 MB.';go('admin');}
  $details=@getimagesize($upload['tmp_name']);$mime=$details['mime']??'';
  if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$_SESSION['flash']='Choose a JPEG, PNG, or WebP field image.';go('admin');}
  query('INSERT INTO event_field_images(event_id,mime,photo_base64) VALUES(?,?,?) ON CONFLICT(event_id) DO UPDATE SET mime=EXCLUDED.mime,photo_base64=EXCLUDED.photo_base64,uploaded_at=now()',[$e['id'],$mime,base64_encode(file_get_contents($upload['tmp_name']))]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Autonomous field image updated for '.$e['name'].'.';go('admin');
 }
 if($p==='highlighted_team'&&role('admin')){
  $number=filter_var($_POST['highlighted_team']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99999]]);
  if($number===false){http_response_code(400);exit('Enter a team number from 1 to 99999');}
  query('UPDATE site_settings SET highlighted_team=? WHERE id=1',[$number]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Highlighted team updated to '.$number.'.';go('admin#highlighted-team-setting');
 }
 if($p==='site_title'&&role('admin')){
  $title=trim((string)($_POST['site_title']??''));
  if($title===''||strlen($title)>320||!preg_match('//u',$title)||preg_match_all('/./us',$title)>80||preg_match('/[\x00-\x1f\x7f]/',$title)){$_SESSION['flash']='Enter a site title of up to 80 characters.';go('admin#appearance');}
  query('UPDATE site_settings SET site_title=? WHERE id=1',[$title]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Site title updated.';go('admin#appearance');
 }
 if($p==='upload_logo'&&role('admin')){
  $upload=$_FILES['logo']??null;
  if(!$upload||$upload['error']!==UPLOAD_ERR_OK||$upload['size']>4*1024*1024||$upload['size']<1||!is_uploaded_file($upload['tmp_name'])){$_SESSION['flash']='Choose a JPEG, PNG, or WebP logo under 4 MB.';go('admin');}
  $details=@getimagesize($upload['tmp_name']);$mime=$details['mime']??'';
  if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$_SESSION['flash']='Choose a JPEG, PNG, or WebP logo.';go('admin');}
  query('UPDATE site_settings SET logo_mime=?,logo_base64=?,logo_uploaded_at=now() WHERE id=1',[$mime,base64_encode(file_get_contents($upload['tmp_name']))]);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Header logo updated.';go('admin');
 }
 if($p==='dark_mode'&&role('admin')){
  $enabled=isset($_POST['dark_mode']);
  query('UPDATE site_settings SET dark_mode=? WHERE id=1',[$enabled?'true':'false']);
  $_SESSION['flash_type']='success';$_SESSION['flash']='Dark Mode '.($enabled?'enabled.':'disabled.');go('admin');
 }
 if($p==='pit_choices'&&role('admin')){
  $submitted=$_POST['choices']??[];$old=pitChoices();$choices=$old;$colors=pitTagColors();
  $scope=$_POST['scope']??'all';if(!in_array($scope,['all','pit','tags'],true)){http_response_code(400);exit('Invalid configuration section');}
  $fields=array_filter(pitChoiceFields(),fn($key)=>$scope==='all'||($scope==='tags'? $key==='tags':$key!=='tags'),ARRAY_FILTER_USE_KEY);
  if(!is_array($submitted)){http_response_code(400);exit('Invalid pit choices');}
  foreach($fields as $key=>[$label]){
   $raw=$submitted[$key]??null;
   if(!is_array($raw)||count($raw)>30){http_response_code(400);exit('Invalid choices for '.$label);}
   $values=[];$seen=[];
   foreach($raw as $i=>$line){if(!is_string($line)){http_response_code(400);exit('Invalid choice');}$value=trim($line);if($value==='')continue;$lower=strtolower($value);if(strlen($value)>($key==='tags'?40:80)||!preg_match('//u',$value)||preg_match('/[\x00-\x1f\x7f]/',$value)){http_response_code(400);exit('Choice too long or invalid for '.$label);}if(isset($seen[$lower])){http_response_code(400);exit('Duplicate choice for '.$label.': '.$value);}$seen[$lower]=true;$values[]=$value;
    if($key==='tags'){$color=$_POST['tag_colors'][$i]??null;if(!is_string($color)||!preg_match('/^#[0-9a-fA-F]{6}$/',$color)){http_response_code(400);exit('Choose a valid tag color');}$colors[$value]=$color;}
   }
   if(!$values||count($values)>30){http_response_code(400);exit('Enter 1 to 30 choices for '.$label);}
   $choices[$key]=$values;
  }
  $removed=[];foreach(pitChoiceFields() as $key=>[$label])foreach(array_diff($old[$key],$choices[$key]) as $value){[$pitCount,$matchCount]=pitChoiceUsage($key,$value);if($pitCount+$matchCount)$removed[]=$label.' “'.$value.'” ('.$pitCount.' pit records, '.$matchCount.' match reports)';}
  if($removed&&($_POST['acknowledge_removal']??'')!=='1'){http_response_code(409);exit('Confirm removal of choices in use. Existing records will be retained: '.implode('; ',$removed));}
  $choices['tag_colors']=$colors;
  query('UPDATE site_settings SET pit_choices=? WHERE id=1',[json_encode($choices,JSON_THROW_ON_ERROR)]);
  $_SESSION['flash_type']='success';$_SESSION['flash']=($scope==='tags'?'Tag Management':'Pit Scouting Configuration').' updated. Existing scouting records were not changed.';go($scope==='tags'?'admin#tag-management':'admin#pit-choices');
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
 if($p==='scout'&&$e) {
  $slot=query('SELECT s.*,m.event_id FROM slots s JOIN matches m ON m.id=s.match_id WHERE s.id=?',[$_POST['slot']??''])->fetch(PDO::FETCH_ASSOC);
  if(!$slot||$slot['event_id']!==$e['id']){http_response_code(404);exit('Slot not found');}
  $old=query('SELECT * FROM scouting WHERE slot_id=?',[$slot['id']])->fetch(PDO::FETCH_ASSOC);
  if($old&&$old['status']==='submitted'&&!role('admin','mentor')){http_response_code(403);exit('Submitted record is locked');}
  if($old&&(int)$old['version']!==(int)($_POST['version']??0)){http_response_code(409);exit('This record changed. Reload before saving.');}
  $data=[];
  foreach(['auto_score','teleop_score','defense_rating','defensive_vulnerability'] as $k){
   $value=trim((string)($_POST[$k]??''));$rating=in_array($k,['defense_rating','defensive_vulnerability'],true);$limit=$rating?5:9999;
   if($rating&&is_numeric($value)&&(float)$value===-0.1)$value='';
   if($value!==''&&(!is_numeric($value)||(float)$value<0||(float)$value>$limit||($rating&&abs((float)$value*10-round((float)$value*10))>0.00001))){http_response_code(400);exit('Invalid numeric scouting value');}
   $data[$k]=$rating&&$value===''?null:$value;
  }
  $data['match_score']=$data['auto_score']!==''&&$data['teleop_score']!==''?(float)$data['auto_score']+(float)$data['teleop_score']:'';
  $data['auto_path']=cleanAutoPath((string)($_POST['auto_path']??'[]'));
  foreach(['endgame','defense','penalties','breakdown','notes'] as $k)$data[$k]=trim((string)($_POST[$k]??''));
  $status='submitted';$id=$old['id']??uuid();$prior=$old['data']??null;$encoded=json_encode($data,JSON_THROW_ON_ERROR);
  query('INSERT INTO scouting(id,slot_id,scout_id,data,status) VALUES(?,?,?,?,?) ON CONFLICT(slot_id) DO UPDATE SET data=EXCLUDED.data,status=EXCLUDED.status,scout_id=EXCLUDED.scout_id,updated_at=now(),version=scouting.version+1,sync_state=\'pending\' ',[$id,$slot['id'],$uid,$encoded,$status]);
  query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'scouting',$id,$uid,$prior,$encoded]);
  query('UPDATE slots SET status=? WHERE id=?',[$status,$slot['id']]);go('matches');
 }
 if($p==='upload_photo'&&$e) { $num=(int)($_POST['team']??0);$team=query('SELECT number FROM teams WHERE event_id=? AND number=?',[$e['id'],$num])->fetch(PDO::FETCH_ASSOC);if(!$team){http_response_code(404);exit('Team not found');}$upload=$_FILES['photo']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK||$upload['size']>4*1024*1024||$upload['size']<1||!is_uploaded_file($upload['tmp_name'])){$_SESSION['flash']='Choose a JPEG, PNG, or WebP photo under 4 MB.';go('pit&n='.$num);} $details=@getimagesize($upload['tmp_name']);$mime=$details['mime']??'';if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){$_SESSION['flash']='This image format is not supported. Choose JPEG, PNG, or WebP.';go('pit&n='.$num);} $data=base64_encode(file_get_contents($upload['tmp_name']));query('INSERT INTO team_photos(event_id,team_number,mime,photo_base64) VALUES(?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET mime=EXCLUDED.mime,photo_base64=EXCLUDED.photo_base64,uploaded_at=now()',[$e['id'],$num,$mime,$data]);$_SESSION['flash']='Robot photo saved.';go('pit&n='.$num);}
 if($p==='pit'&&$e) {
  $num=(int)($_POST['team']??0);if($num<1)exit('Invalid team');
  $old=query('SELECT * FROM pit WHERE event_id=? AND team_number=?',[$e['id'],$num])->fetch(PDO::FETCH_ASSOC);
  $previous=json_decode($old['data']??'{}',true)?:[];$choices=pitChoices();
  $meta=$_POST['robot_meta']??'';$custom=trim((string)($_POST['robot_meta_other']??''));
  if(!is_string($meta)||!in_array($meta,array_merge([''],pitChoicesWithSaved($choices,'robot_meta',(string)($previous['robot_meta']??''))),true)||($meta==='Other'&&($custom===''||strlen($custom)>80))){http_response_code(400);exit('Choose a valid Robot Meta option');}
  $selected=$_POST['tags']??[];
  if(!is_array($selected)||count($selected)>12){http_response_code(400);exit('Choose up to 12 tags');}
  $allowedTags=array_merge($choices['tags'],pitTags($previous));
  foreach($selected as $tag)if(!is_string($tag)||!in_array($tag,$allowedTags,true)||strlen($tag)>40){http_response_code(400);exit('Choose tags from the configured list');}
  $tags=pitTags(['tags'=>$selected]);
  $data=array_intersect_key($previous,array_flip(['robot','dimensions','weight','mechanisms','scoring']));
  $data['robot_meta']=$meta;$data['robot_meta_other']=$meta==='Other'?$custom:'';$data['tags']=$tags;
  foreach(['drivetrain','intake','shooter_type','width','length','height','weight_lbs','autonomous','endgame','strategy','reliability','requirements','notes'] as $k){
   $value=$_POST[$k]??'';if(!is_string($value)){http_response_code(400);exit('Invalid pit field');}
   $data[$k]=trim($value);
   if(in_array($k,['drivetrain','intake','shooter_type','width','length','height','weight_lbs'],true)&&strlen($data[$k])>80){http_response_code(400);exit('Pit field is too long');}
  }
  foreach(['width','length','height','weight_lbs'] as $k)if($data[$k]!==''&&(!is_numeric($data[$k])||(float)$data[$k]<0)){http_response_code(400);exit('Dimensions and weight must be nonnegative numbers');}
  foreach(['intake','shooter_type','driver_experience','human_player_experience'] as $k){$value=$data[$k]??($_POST[$k]??'');if(!is_string($value)||!in_array($value,array_merge([''],pitChoicesWithSaved($choices,$k,(string)($previous[$k]??''))),true)){http_response_code(400);exit('Choose a valid '.pitChoiceFields()[$k][0].' option');}$data[$k]=$value;}
  query('INSERT INTO teams(event_id,number) VALUES(?,?) ON CONFLICT DO NOTHING',[$e['id'],$num]);$id=$old['id']??uuid();$encoded=json_encode($data,JSON_THROW_ON_ERROR);
  query('INSERT INTO pit(id,event_id,team_number,author_id,data) VALUES(?,?,?,?,?) ON CONFLICT(event_id,team_number) DO UPDATE SET data=EXCLUDED.data,author_id=EXCLUDED.author_id,updated_at=now(),sync_state=\'pending\' ',[$id,$e['id'],$num,$uid,$encoded]);
  query('INSERT INTO audit(id,record_type,record_id,actor_id,prior_data,new_data) VALUES(?,?,?,?,?,?)',[uuid(),'pit',$id,$uid,$old['data']??null,$encoded]);go('team&n='.$num);
 }
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
if(!isset($_SESSION['user'])) {echo '<!doctype html><html lang="en"'.(picked(siteSettings()['dark_mode'])?' class="dark-mode"':'').'><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h(siteSettings()['site_title']).' · Sign in</title><link rel="stylesheet" href="'.h(asset('style.css')).'"><main><h1>'.h(siteSettings()['site_title']).' sign in</h1><form method="post" action="/?p=login"><label>Username<input name="name" required autofocus></label><label>Password<input name="password" type="password" required></label><button>Sign in</button></form><p>First run: admin / change-me-now. Change this password before use.</p></main></html>';exit;}
$e=event();
if($p==='site_logo'){$logo=query('SELECT logo_mime,logo_base64 FROM site_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);if(!$logo||!$logo['logo_base64']){http_response_code(404);exit;}header('Content-Type: '.$logo['logo_mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-cache');echo base64_decode($logo['logo_base64']);exit;}
if($p==='field_image'){$image=$e?query('SELECT mime,photo_base64 FROM event_field_images WHERE event_id=?',[$e['id']])->fetch(PDO::FETCH_ASSOC):false;if(!$image){http_response_code(404);exit;}header('Content-Type: '.$image['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-cache');echo base64_decode($image['photo_base64']);exit;}
if($p==='robot_photo'){ $num=(int)($_GET['n']??0);$photo=$e?query('SELECT mime,photo_base64 FROM team_photos WHERE event_id=? AND team_number=?',[$e['id'],$num])->fetch(PDO::FETCH_ASSOC):false;if(!$photo){http_response_code(404);exit;}header('Content-Type: '.$photo['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');echo base64_decode($photo['photo_base64']);exit;}
if($p==='home'){page('Dashboard',true,false);echo '<aside class="warn">Cloud sync is not configured. Local entries remain in PostgreSQL; remote backup is not active.</aside>';if($e){
 $teams=query('SELECT number,name FROM teams WHERE event_id=? ORDER BY number',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
 $reports=query('SELECT s.team_number,sc.data FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status=\'submitted\' ORDER BY sc.updated_at DESC',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
 $byTeam=[];foreach($reports as $report)$byTeam[$report['team_number']][]=json_decode($report['data'],true)?:[];
 $pits=query('SELECT team_number,data FROM pit WHERE event_id=?',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);$pitNotes=[];foreach($pits as $pit)$pitNotes[$pit['team_number']]=(json_decode($pit['data'],true)['notes']??'');
 $keys=['match_score','auto_score','teleop_score','defense_rating','defensive_vulnerability'];$rows=[];$bounds=[];foreach($teams as $team){$number=$team['number'];$entries=$byTeam[$number]??[];$metrics=[];foreach($keys as $key){$v=metricAverage($entries,$key,in_array($key,['defense_rating','defensive_vulnerability'],true)?5.0:null);$metrics[$key]=$v;if($v!==null)$bounds[$key][]=$v;}$lastNote='';foreach($entries as $entry)if(trim((string)($entry['notes']??''))!==''){$lastNote=trim($entry['notes']);break;}$notes=trim((string)($pitNotes[$number]??''));if($lastNote)$notes.=($notes?' | ':'').$lastNote;$rows[]=['number'=>$number,'name'=>$team['name'],'metrics'=>$metrics,'notes'=>$notes];}
 if(!$reports)echo '<aside class="warn">No submitted match reports for this event yet. Scouts can enter reports from the Matches page.</aside>';echo '<div class="scroll"><table class="dashboard-table" id="teamDashboard"><thead><tr>';$headers=['Team #','Team name','Avg match score','Avg autonomous','Avg teleop','Avg defensive ability','Avg defensive vulnerability','Notes'];foreach($headers as $i=>$label)echo '<th><button type="button" class="sort-head" data-col="'.h($i).'" aria-label="Sort by '.h($label).'">'.h($label).' <span aria-hidden="true">↕</span></button></th>';echo '</tr></thead><tbody>';foreach($rows as $row){$n=h($row['number']);echo '<tr><td data-value="'.$n.'"><a href="/?p=team&n='.$n.'">'.$n.'</a></td><td data-value="'.h(strtolower($row['name'])).'">'.h($row['name']).'</td>';foreach($keys as $key){$v=$row['metrics'][$key];$display=$v===null?'—':number_format($v,1,'.','');$values=$bounds[$key]??[];$style=$values?heatColor($v,min($values),max($values)):'';echo '<td class="metric" data-value="'.h($v===null?'':$v).'"'.($style?' style="'.h($style).'"':'').'>'.h($display).'</td>';}echo '<td class="notes-cell" data-value="'.h(strtolower($row['notes'])).'">'.h($row['notes']?:'—').'</td></tr>';}echo '</tbody></table></div><p class="dashboard-help">Click a column heading to sort. <span id="dashboardControlsStatus" role="status">Loading table controls…</span></p><script src="'.h(asset('dashboard.js')).'" defer></script>';}endpage();}
elseif($p==='admin'){if(!role('admin')){http_response_code(403);exit('Admins only');}page('Administration');require __DIR__.'/admin-page.php';endpage();}
elseif($p==='matches'){page('Matches',true,false);if($e){renderMatchSection($e['id'],'qualification');renderMatchSection($e['id'],'elimination');}endpage();}
elseif($p==='match'){
 $number=(int)($_GET['n']??0);$stage=($_GET['stage']??'qualification')==='elimination'?'elimination':'qualification';$match=$e&&$number>0?query('SELECT * FROM matches WHERE event_id=? AND stage=? AND match_number=?',[$e['id'],$stage,$number])->fetch(PDO::FETCH_ASSOC):false;
 if(!$match){http_response_code(404);exit('Match not found');}
 $slots=query('SELECT s.id,s.position,s.team_number,s.status,t.name,p.data AS pit_data,ph.uploaded_at,sc.data AS report_data,sc.status AS report_status FROM slots s LEFT JOIN teams t ON t.event_id=? AND t.number=s.team_number LEFT JOIN pit p ON p.event_id=? AND p.team_number=s.team_number LEFT JOIN team_photos ph ON ph.event_id=? AND ph.team_number=s.team_number LEFT JOIN scouting sc ON sc.slot_id=s.id WHERE s.match_id=?',[$e['id'],$e['id'],$e['id'],$match['id']])->fetchAll(PDO::FETCH_ASSOC);
 $byPosition=[];foreach($slots as $slot)$byPosition[$slot['position']]=$slot;
 $reports=query('SELECT s.team_number,sc.data FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status=\'submitted\' ',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
 $byTeam=[];foreach($reports as $report)$byTeam[$report['team_number']][]=json_decode($report['data'],true)?:[];
 $predicted=[];foreach(['R','B'] as $alliance){$sum=0;$complete=true;for($i=1;$i<=3;$i++){$slot=$byPosition[$alliance.$i]??null;$average=$slot?metricAverage($byTeam[(int)$slot['team_number']]??[],'match_score'):null;if($average===null){$complete=false;break;}$sum+=$average;}$predicted[$alliance]=$complete?round($sum,1):null;}
 $winner=$predicted['R']!==null&&$predicted['B']!==null&&$predicted['R']!==$predicted['B']?($predicted['R']>$predicted['B']?'R':'B'):null;
 page($stage==='qualification'?'Qualification Match Q'.$number:'Elimination '.$match['label'],true);
 echo '<p><a href="/?p=matches">← Matches</a> · '.h($e['name']).'</p>';
 if($match['video_url'])echo '<p><a href="'.h($match['video_url']).'">Watch match video</a></p>';
 echo '<div class="match-alliances">';
 foreach(['R'=>'Red','B'=>'Blue'] as $initial=>$name){
  $alliance=$initial==='R'?'red':'blue';echo '<section class="match-alliance '.h($alliance).'"><h2 class="match-alliance-header"><span class="match-alliance-name">'.h($name).' Alliance</span><span class="match-header-summary"><span class="match-header-score">Predicted Overall Score: <strong>'.h($predicted[$initial]===null?'—':number_format($predicted[$initial],1)).'</strong></span>';
  if($winner===$initial)echo '<span class="predicted-winner">Predicted Winner</span>';
  echo '</span></h2>';
  for($i=1;$i<=3;$i++){
   $position=$initial.$i;$slot=$byPosition[$position]??null;
   if(!$slot){echo '<div class="match-team empty"><h3>'.h($name.' '.$i).' · Team not assigned</h3></div>';continue;}
   $teamNumber=(int)$slot['team_number'];$entries=$byTeam[$teamNumber]??[];$pit=json_decode($slot['pit_data']??'{}',true)?:[];
   echo '<article class="match-team"><div class="match-team-head"><h3><span class="match-position">'.h($name.' '.$i).'</span><a href="/?p=team&n='.h($teamNumber).'">'.teamNumber($teamNumber).' · '.h($slot['name']?:'Unnamed team').'</a></h3>';
   if($slot['uploaded_at'])echo '<img class="match-team-photo" src="/?p=robot_photo&n='.h($teamNumber).'&v='.rawurlencode($slot['uploaded_at']).'" alt="Robot for team '.h($teamNumber).'" loading="lazy">';
   echo '</div>';
   if($tags=pitTags($pit)){echo '<div class="team-tags">';foreach($tags as $tag)echo '<span class="team-tag"'.tagStyle($tag).'>'.h($tag).'</span>';echo '</div>';}
   echo '<div class="match-team-metrics">';
   foreach(['match_score'=>'Avg match','auto_score'=>'Avg auto','teleop_score'=>'Avg teleop','defense_rating'=>'Defensive ability','defensive_vulnerability'=>'Defensive vulnerability'] as $key=>$label){$value=metricAverage($entries,$key,in_array($key,['defense_rating','defensive_vulnerability'],true)?5.0:null);echo '<div><span>'.h($label).'</span><strong>'.h($value===null?'—':number_format($value,1)).'</strong></div>';}
   echo '</div>';
   $matchLabel=matchLabel($match);
   if($slot['report_status']==='submitted'){$current=json_decode($slot['report_data']??'{}',true)?:[];echo '<p class="match-current"><b>'.h($matchLabel).' scouted:</b> Auto '.h(is_numeric($current['auto_score']??null)?$current['auto_score']:'—').' · Teleop '.h(is_numeric($current['teleop_score']??null)?$current['teleop_score']:'—').' · Total '.h(is_numeric($current['match_score']??null)?$current['match_score']:'—').'</p>';}
   else echo '<p class="match-current">'.h($matchLabel).' report not yet submitted.</p>';
   echo '</article>';
  }
  echo '</section>';
 }
 echo '</div>';endpage();
}
elseif($p==='scout'){
 $s=query('SELECT s.*,m.match_number,m.stage,m.label,m.event_id,m.video_url,sc.data,sc.version,sc.status AS record_status FROM slots s JOIN matches m ON m.id=s.match_id LEFT JOIN scouting sc ON sc.slot_id=s.id WHERE s.id=?',[$_GET['id']??''])->fetch(PDO::FETCH_ASSOC);
 if(!$s||!$e||$s['event_id']!==$e['id']){http_response_code(404);exit('Slot not found');}
 $d=json_decode($s['data']??'{}',true)?:[];$editable=$s['record_status']!=='submitted'||role('admin','mentor');
 page(($s['stage']==='elimination'?($s['label']?:'Elimination Match '.$s['match_number']):'Q'.$s['match_number']).' · '.$s['position'].' · Team '.$s['team_number']);
 if($s['video_url'])echo '<p><a href="'.h($s['video_url']).'">Watch match video</a></p>';
 if(!$editable){pathWidget(is_array($d['auto_path']??null)?$d['auto_path']:[],false);echo '<p>Submitted and locked. Ask a mentor to correct this record.</p>';foreach($d as $k=>$v)if(!in_array($k,['auto_path','demo','match_score'],true))echo '<p><b>'.h($k).':</b> '.h(in_array($k,['defense_rating','defensive_vulnerability'],true)&&($v===null||$v==='')?'N/A':$v).'</p>';}
 else{
  echo '<form method="post" action="/?p=scout" class="scout-form">'.csrf().'<input type="hidden" name="slot" value="'.h($s['id']).'"><input type="hidden" name="version" value="'.h($s['version']??0).'">';
  pathWidget(is_array($d['auto_path']??null)?$d['auto_path']:[],true);
  foreach(['auto_score'=>'Autonomous score','teleop_score'=>'Teleop score'] as $k=>$label)echo '<label>'.h($label).'<input type="number" min="0" max="9999" step="1" name="'.h($k).'" value="'.(is_numeric($d[$k]??null)?field($d,$k):'').'"></label>';
  foreach(['defense_rating'=>'Defensive Ability','defensive_vulnerability'=>'Defensive Vulnerability (5 = not vulnerable)'] as $k=>$label){
   $value=is_numeric($d[$k]??null)&&(float)$d[$k]>=0?max(0,min(5,(float)$d[$k])):-0.1;$display=$value<0?'N/A':number_format($value,1);
   echo '<div class="rating-field"><label class="rating-label" for="'.h($k).'">'.h($label).' <output for="'.h($k).'" id="'.h($k).'Value">'.h($display).'</output></label><div class="rating-control"><button type="button" class="rating-na" data-rating="'.h($k).'" aria-label="Set '.h($label).' to not assessed">N/A</button><input type="range" min="-0.1" max="5" step="0.1" name="'.h($k).'" id="'.h($k).'" value="'.h($value).'" aria-valuetext="'.h($display).'" aria-describedby="'.h($k).'Help"></div><small id="'.h($k).'Help">Far left: N/A (not assessed, excluded from averages). Ratings: 0–5 in 0.1 steps.</small></div>';
  }
  foreach(['endgame'=>'Endgame','defense'=>'Defense observations','penalties'=>'Penalties','breakdown'=>'Breakdown / reliability','notes'=>'Observations'] as $k=>$label)echo '<label>'.h($label).'<textarea name="'.h($k).'">'.field($d,$k).'</textarea></label>';
  echo '<button type="submit" name="submit" value="1">Submit</button></form>';
 }
 echo '<script src="'.h(asset('auto-path.js')).'" defer></script>';endpage();
}
elseif($p==='teams'){page('Pit Scouting',false,false);if($e){echo '<div class="team-grid">';foreach(query('SELECT t.number,t.name,ph.uploaded_at,EXISTS(SELECT 1 FROM pit p WHERE p.event_id=t.event_id AND p.team_number=t.number) AS pit_scouted FROM teams t LEFT JOIN team_photos ph ON ph.event_id=t.event_id AND ph.team_number=t.number WHERE t.event_id=? ORDER BY t.number',[$e['id']]) as $t){$n=h($t['number']);echo '<article class="team-card">';if($t['uploaded_at'])echo '<img class="team-photo" src="/?p=robot_photo&n='.$n.'&v='.rawurlencode($t['uploaded_at']).'" alt="Robot photo for team '.$n.'" loading="lazy">';else echo '<div class="team-photo placeholder" aria-label="No robot photo yet">Robot photo</div>';echo '<div class="team-card-body"><h2><a href="/?p=team&n='.$n.'">'.teamNumber($t['number']).' · '.($t['name']?h($t['name']):'Unnamed team').'</a></h2><p>'.(picked($t['pit_scouted'])?'Pit Scouted':'Not Pit Scouted Yet').'</p><a class="button" href="/?p=pit&n='.$n.'">Pit Scout</a></div></article>';}echo '</div>';}endpage();}
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
  $choices=pitChoices();$meta=(string)($d['robot_meta']??'');
  echo '<label>Robot Meta<select name="robot_meta" onchange="const custom=this.form.elements.robot_meta_other;custom.parentElement.hidden=this.value!==\'Other\';custom.required=this.value===\'Other\'"><option value="">Choose Robot Meta</option>';
  foreach(pitChoicesWithSaved($choices,'robot_meta',$meta) as $option)echo '<option value="'.h($option).'"'.($meta===$option?' selected':'').'>'.h($option).'</option>';
  echo '</select></label><label'.($meta==='Other'?'':' hidden').'>Custom Robot Meta<input name="robot_meta_other" type="text" maxlength="80" value="'.h($d['robot_meta_other']??'').'"'.($meta==='Other'?' required':'').'></label>';
  $suggestions=['Swerve','Tank','Mecanum','West Coast Drive'];
  foreach(query('SELECT data FROM pit WHERE event_id=?',[$e['id']]) as $saved){$value=trim((string)((json_decode($saved['data'],true)?:[])['drivetrain']??''));if($value!==''&&!in_array($value,$suggestions,true))$suggestions[]=$value;}
  echo '<label>Drivetrain<input name="drivetrain" list="drivetrain-options" maxlength="80" value="'.field($d,'drivetrain').'" placeholder="Select or type a drivetrain"></label><datalist id="drivetrain-options">';
  foreach($suggestions as $option)echo '<option value="'.h($option).'"></option>';echo '</datalist>';
  echo '<fieldset class="pit-measurements"><legend>Dimensions (inches)</legend><div class="pit-field-row">';
  foreach(['width'=>'Width','length'=>'Length','height'=>'Height'] as $key=>$label)echo '<label>'.h($label).'<input name="'.h($key).'" type="text" inputmode="decimal" maxlength="20" value="'.field($d,$key).'" placeholder="in"></label>';
  echo '</div></fieldset><label class="pit-weight">Weight (lbs)<input name="weight_lbs" type="text" inputmode="decimal" maxlength="20" value="'.field($d,'weight_lbs').'" placeholder="lbs"></label>';
  foreach(['intake'=>'Intake','shooter_type'=>'Shooter Type'] as $key=>$label){echo '<label>'.h($label).'<select name="'.h($key).'"><option value="">Choose '.h($label).'</option>';foreach(pitChoicesWithSaved($choices,$key,(string)($d[$key]??'')) as $option)echo '<option value="'.h($option).'"'.(($d[$key]??'')===$option?' selected':'').'>'.h($option).'</option>';echo '</select></label>';}
  $tags=pitTags($d);$tagOptions=$choices['tags'];foreach($tags as $tag)if(!in_array($tag,$tagOptions,true))$tagOptions[]=$tag;
  echo '<fieldset class="pit-tags"><legend>Tag Management</legend><div class="pit-tag-picker"><label>Add a tag<select id="pitTagChoice"><option value="">Choose tag</option>';
  foreach($tagOptions as $tag)echo '<option value="'.h($tag).'" data-color="'.h(tagColor($tag)).'">'.h($tag).'</option>';
  echo '</select></label><button type="button" id="pitTagAdd">Add tag</button></div><div id="pitSelectedTags" class="pit-selected-tags">';
  foreach($tags as $tag)echo '<span class="team-tag"'.tagStyle($tag).' data-tag="'.h($tag).'"><input type="hidden" name="tags[]" value="'.h($tag).'"><span>'.h($tag).'</span><button type="button" class="pit-tag-remove" aria-label="Remove '.h($tag).'">×</button></span>';
  echo '</div><small>Select up to 12 tags. Admins manage the choices under Admin.</small><p id="pitTagsStatus" role="status"></p></fieldset><script src="'.h(asset('pit-tags.js')).'" defer></script>';
  echo '<label>Autonomous Notes<textarea name="autonomous">'.field($d,'autonomous').'</textarea></label>';
  foreach(['driver_experience'=>'Driver Experience Level','human_player_experience'=>'Human Player Experience Level'] as $key=>$label){echo '<label>'.h($label).'<select name="'.h($key).'"><option value="">Choose level</option>';foreach(pitChoicesWithSaved($choices,$key,(string)($d[$key]??'')) as $option)echo '<option value="'.h($option).'"'.(($d[$key]??'')===$option?' selected':'').'>'.h($option).'</option>';echo '</select></label>';}
  foreach(['endgame'=>'Endgame','strategy'=>'Preferred strategy','reliability'=>'Reliability','requirements'=>'Special requirements','notes'=>'Notes'] as $k=>$label)echo '<label>'.h($label).'<textarea name="'.h($k).'">'.field($d,$k).'</textarea></label>';
  echo '<button>Save pit record</button></form><p><a href="/?p=team&n='.h($n).'">View team profile</a></p>';
 }else{
  echo '<p><a class="button" href="/?p=pit&n='.h($n).'">Pit Scout</a></p>';
  if($photo)echo '<img class="pit-photo" src="/?p=robot_photo&n='.h($n).'&v='.rawurlencode($photo['uploaded_at']).'" alt="Robot photo for team '.h($n).'">';
  $reports=query('SELECT m.match_number,m.stage,m.label,s.position,sc.data FROM slots s JOIN matches m ON m.id=s.match_id JOIN scouting sc ON sc.slot_id=s.id WHERE m.event_id=? AND s.team_number=? AND sc.status=\'submitted\' ORDER BY m.stage DESC,m.match_number',[$e['id'],$n])->fetchAll(PDO::FETCH_ASSOC);
  teamPerformanceCharts($reports);
  $paths=[];foreach($reports as $r){$v=json_decode($r['data'],true)?:[];if(!empty($v['auto_path'])&&is_array($v['auto_path']))$paths[]=['label'=>matchLabel($r).' · '.$r['position'],'strokes'=>$v['auto_path']];}
  echo '<section class="path-widget"><h2>Combined autonomous paths</h2>';
  if($paths)echo '<p>Each match has its own color. Paths are drawn over the same field image.</p><canvas id="teamPathsCanvas" width="800" height="480" data-paths="'.h(json_encode($paths)).'" aria-label="Combined autonomous paths for team '.h($n).'"></canvas><div id="pathLegend" class="path-legend"></div>';
  else echo '<p>No autonomous paths have been saved for this team yet.</p>';
  echo '</section><h2>Pit notes</h2><section>';
  if(robotMeta($d)!=='')echo '<p><b>Robot Meta:</b> '.h(robotMeta($d)).'</p>';
  if($tags=pitTags($d)){echo '<p><b>Tags:</b> <span class="team-tags">';foreach($tags as $tag)echo '<span class="team-tag"'.tagStyle($tag).'>'.h($tag).'</span>';echo '</span></p>';}
  $pitLabels=['drivetrain'=>'Drivetrain','width'=>'Width','length'=>'Length','height'=>'Height','weight_lbs'=>'Weight','intake'=>'Intake','shooter_type'=>'Shooter type','autonomous'=>'Autonomous Notes','endgame'=>'Endgame','strategy'=>'Preferred strategy','reliability'=>'Reliability','requirements'=>'Special requirements','notes'=>'Notes','driver_experience'=>'Driver Experience Level','human_player_experience'=>'Human Player Experience Level'];
  foreach($pitLabels as $key=>$label)if(($d[$key]??'')!=='')echo '<p><b>'.h($label).':</b> '.h($d[$key]).(in_array($key,['width','length','height'],true)?' in':($key==='weight_lbs'?' lbs':'')).'</p>';
  if(!$d)echo '<p>No pit record yet.</p>';echo '</section><h2>Match reports</h2>';
  foreach($reports as $r){$v=json_decode($r['data'],true)?:[];echo '<section><b>'.h(matchLabel($r)).' '.h($r['position']).'</b><p>'.h($v['notes']??'').'</p></section>';}
  echo '<script src="'.h(asset('auto-path.js')).'" defer></script>';
 }
 endpage();
}
elseif($p==='strategy'){
 page('Strategy',true,false);
 if(!$e){echo '<p>Select an event in Admin to create strategy plans.</p>';endpage();}
 else{
  $plans=query('SELECT id,title,updated_at FROM strategy_plans WHERE event_id=? ORDER BY updated_at DESC',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
  $id=(string)($_GET['id']??'');$plan=$id?query('SELECT * FROM strategy_plans WHERE id=? AND event_id=?',[$id,$e['id']])->fetch(PDO::FETCH_ASSOC):false;
  if($id&&!$plan){http_response_code(404);exit('Strategy plan not found');}
  $editor=role('admin','mentor','drive');$choices=query('SELECT number,name FROM teams WHERE event_id=? ORDER BY number',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
  $partners=$plan?json_decode($plan['teams'],true):[['number'=>0,'color'=>'#16a34a'],['number'=>0,'color'=>'#f97316'],['number'=>0,'color'=>'#9333ea']];
  $paths=$plan?json_decode($plan['paths'],true):[[],[],[]];
  echo '<div class="strategy-layout"><div>';
  if($editor){
   echo '<form id="strategyForm" method="post" action="/?p=strategy" class="strategy-form">'.csrf().'<input type="hidden" name="id" value="'.h($plan['id']??'').'"><h2>Planned paths</h2><div class="drawing-stage"><div class="strategy-tools">';
   for($i=0;$i<3;$i++)echo '<button type="button" class="strategy-layer" data-layer="'.h($i).'">Partner '.h($i+1).'</button>';
   echo '<button type="button" id="strategyUndo">Undo stroke</button><span class="strategy-action-pair"><button type="button" id="strategyClear">Clear partner path</button><button type="button" class="canvas-fullscreen" aria-pressed="false">Full screen</button></span></div>';
   echo '<canvas id="strategyCanvas" width="800" height="480" data-plan="'.h(json_encode(['teams'=>$partners,'paths'=>$paths])).'" aria-label="Strategy field drawing"></canvas></div><input type="hidden" name="strategy_paths" id="strategyPaths" value="'.h(json_encode($paths)).'"><label>Plan notes<textarea name="notes" rows="7">'.h($plan['notes']??'').'</textarea></label><button>Save strategy plan</button></form>';
  }elseif($plan){
   echo '<h2>'.h($plan['title']).'</h2><div class="drawing-stage"><div class="drawing-stage-bar"><strong>Strategy field</strong><button type="button" class="canvas-fullscreen" aria-pressed="false">Full screen</button></div><canvas id="strategyCanvas" width="800" height="480" data-plan="'.h(json_encode(['teams'=>$partners,'paths'=>$paths])).'" aria-label="Strategy field drawing"></canvas></div><div class="path-legend">';
   foreach($partners as $partner)if($partner['number'])echo '<span><i style="background:'.h($partner['color']).'"></i>Team '.h($partner['number']).'</span>';
   echo '</div><p>'.nl2br(h($plan['notes'])).'</p>';
  }else echo '<p>Choose a saved plan to view it.</p>';
  echo '</div><div class="strategy-sidebar">';
  if($editor){
   $matchChoices=query("SELECT id,stage,match_number,label FROM matches WHERE event_id=? ORDER BY CASE WHEN stage='qualification' THEN 0 ELSE 1 END,match_number",[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
   $matchTeams=[];foreach(query('SELECT s.match_id,s.position,s.team_number FROM slots s JOIN matches m ON m.id=s.match_id WHERE m.event_id=?',[$e['id']]) as $slot)$matchTeams[$slot['match_id']][$slot['position']]=(int)$slot['team_number'];
   echo '<section class="strategy-setup"><h2>Plan setup</h2><label>Plan title<input form="strategyForm" id="strategyTitle" name="title" maxlength="120" value="'.h($plan['title']??'').'" placeholder="Example: Q12 red alliance" required></label><div class="strategy-match-controls"><label>Match<select id="strategyMatch"><option value="">Choose match</option>';
   foreach($matchChoices as $match)echo '<option value="'.h($match['id']).'" data-teams="'.h(json_encode($matchTeams[$match['id']]??new stdClass())).'">'.h(matchLabel($match)).'</option>';
   echo '</select></label><label>Alliance<select id="strategyAlliance"><option value="R">Red</option><option value="B">Blue</option></select></label></div><div class="strategy-setup-actions"><button type="button" id="strategyLoadMatch">Load match teams</button><a class="button strategy-new" href="/?p=strategy">New plan</a></div><p id="strategyMatchStatus" role="status"></p><div class="strategy-partners">';
   foreach($partners as $i=>$partner){
    echo '<div><label>Alliance partner '.h($i+1).'<select form="strategyForm" name="team_'.h($i).'" data-partner="'.h($i).'"><option value="0">Choose team</option>';
    foreach($choices as $choice)echo '<option value="'.h($choice['number']).'"'.((int)$partner['number']===(int)$choice['number']?' selected':'').'>'.h($choice['number'].' · '.$choice['name']).'</option>';
    echo '</select></label><label>Path color<input form="strategyForm" type="color" name="color_'.h($i).'" data-color="'.h($i).'" value="'.h($partner['color']).'"></label></div>';
   }
   echo '</div></section>';
  }
  echo '<aside class="strategy-list"><div class="strategy-list-header"><h2>Saved plans</h2>';
  echo '</div>';
  foreach($plans as $item){
   echo '<div class="strategy-plan-row'.($plan&&$plan['id']===$item['id']?' current':'').'"><div class="strategy-plan-name"><strong>'.h($item['title']).'</strong><small>'.h($item['updated_at']).'</small></div><a class="button strategy-load" href="/?p=strategy&id='.h($item['id']).'" aria-label="Load plan '.h($item['title']).'">Load</a>';
   if($editor)echo '<form class="strategy-delete-form" method="post" action="/?p=strategy_delete" onsubmit="return confirm(&quot;Delete this strategy plan?&quot;)">'.csrf().'<input type="hidden" name="id" value="'.h($item['id']).'"><button type="submit" class="strategy-delete" aria-label="Delete plan '.h($item['title']).'" title="Delete plan '.h($item['title']).'"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6m4-6v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form>';
   echo '</div>';
  }
  if(!$plans)echo '<p>No plans saved yet.</p>';
  echo '</aside></div></div><script src="'.h(asset('strategy.js')).'" defer></script>';endpage();
 }
}
elseif($p==='picks'){
 page('Pick List',true,false);
 if(!$e){echo '<p>Select an event in Admin to view its pick list.</p>';endpage();}
 else{
  $cards=pickRows($e['id']);$editor=role('admin','mentor','drive');
  $reports=query('SELECT s.team_number,sc.data FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status=\'submitted\' ORDER BY sc.updated_at DESC',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
  $byTeam=[];foreach($reports as $report)$byTeam[$report['team_number']][]=json_decode($report['data'],true)?:[];
  $groups=array_fill_keys(pickBuckets(),[]);$already=[];
  foreach($cards as $card){$bucket=in_array($card['bucket']??'B',pickBuckets(),true)?($card['bucket']??'B'):'B';if(picked($card['picked']))$already[]=$card;else $groups[$bucket][]=$card;}
  $availableTags=[];foreach($cards as $card)foreach(pitTags(json_decode($card['pit_data']??'{}',true)?:[]) as $tag)$availableTags[$tag]=$tag;
  natcasesort($availableTags);
  echo '<div class="pick-tag-filter"><label><span class="sr-only">Filter teams by tag</span><select id="pickTagFilter"><option value="">All teams</option>';
  foreach($availableTags as $tag)echo '<option value="'.h($tag).'">'.h($tag).'</option>';
  echo '</select></label><span id="pickTagCount" role="status"></span></div>';
  echo '<div class="pick-board-scroll"><div class="pick-board" id="pickBoard">';
  foreach(array_merge($groups,['Already Picked'=>$already]) as $bucket=>$bucketCards){
   if($bucket==='Already Picked')echo '</div></div>';
   echo '<section class="pick-bucket'.($bucket==='Already Picked'?' already-picked':'').'" data-bucket="'.h($bucket).'"><div class="pick-bucket-head"><h2>'.h($bucket).'</h2><span>'.h(count($bucketCards)).' teams</span></div><div class="pick-cards" data-bucket="'.h($bucket).'">';
   foreach($bucketCards as $card){
   $num=(int)$card['number'];$isPicked=picked($card['picked']);$isDnp=($card['bucket']??'B')==='DNP';
   $entries=$byTeam[$num]??[];$metrics=[];
   foreach(['match_score'=>'Avg Match Score','auto_score'=>'Avg Autonomous','teleop_score'=>'Avg Teleop','defense_rating'=>'Avg Defensive Ability','defensive_vulnerability'=>'Avg Defensive Vulnerability'] as $key=>$label)$metrics[$label]=metricAverage($entries,$key,in_array($key,['defense_rating','defensive_vulnerability'],true)?5.0:null);
   $pitData=json_decode($card['pit_data']??'{}',true)?:[];$tags=pitTags($pitData);
   echo '<article class="pick-card'.($isPicked?' picked':'').($isDnp?' do-not-pick':'').'" data-team="'.h($num).'" data-tags="'.h(json_encode($tags,JSON_THROW_ON_ERROR)).'"'.($editor&&!$isPicked?' draggable="true"':'').'><div class="pick-card-check">';
   if($editor&&!$isPicked)echo '<button type="button" class="pick-drag" aria-label="Drag team '.h($num).' to reorder or change bucket" title="Drag to reorder; arrow keys also work"><span aria-hidden="true">⋮</span></button>';
   echo '</div>';
   echo '<div class="pick-card-title"><h2><a href="/?p=team&n='.h($num).'">'.teamNumber($num).' · '.h($card['name']?:'Unnamed team').'</a></h2>';
   echo '</div>';
   echo '<div class="pick-card-picked">';
   if($editor)echo '<form method="post" action="/?p=pick_status">'.csrf().'<input type="hidden" name="team" value="'.h($num).'"><label title="Mark team picked"><input type="checkbox" name="picked" value="1" aria-label="Team '.h($num).' picked"'.($isPicked?' checked':'').' onchange="this.form.requestSubmit()"><span>Picked</span></label></form>';
   else echo '<span>'.($isPicked?'✓ Picked':'').'</span>';
   echo '</div>';
   echo '<button type="button" class="pick-expand" aria-expanded="false" aria-controls="pick-details-'.h($num).'" aria-label="Show details for team '.h($num).'" title="Show details"><span aria-hidden="true">⌄</span></button>';
   echo '<div class="pick-card-content" id="pick-details-'.h($num).'" hidden>';
   if($card['uploaded_at'])echo '<img class="pick-card-photo" src="/?p=robot_photo&n='.h($num).'&v='.rawurlencode($card['uploaded_at']).'" alt="Robot photo for team '.h($num).'" loading="lazy">';
   else echo '<div class="pick-card-photo placeholder pick-photo-empty" role="img" aria-label="No robot photo for team '.h($num).'"><svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path d="M24 5v6m-4-6h8M10 18h28v23H10zM6 24h4m28 0h4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18" cy="27" r="2" fill="currentColor"/><circle cx="30" cy="27" r="2" fill="currentColor"/><path d="M18 35h12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg><span>No photo</span></div>';
   echo '<div class="pick-stats">';
   $meta=robotMeta($pitData);
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

<?php
declare(strict_types=1);

function teamChartMetrics(): array {
 return [
  'match_score'=>['Match score','#2563eb',null],
  'auto_score'=>['Autonomous score','#c76a00',null],
  'teleop_score'=>['Teleop score','#008978',null],
  'defense_rating'=>['Defensive Ability','#9254d1',5],
  'defensive_vulnerability'=>['Defensive Vulnerability','#ca427c',5],
 ];
}

function teamChartRows(array $reports): array {
 $rows=[];
 foreach($reports as $report){
  $data=json_decode($report['data'],true)?:[];
  $row=['label'=>matchLabel($report),'url'=>'/?p=match&n='.(int)$report['match_number'].'&stage='.($report['stage']==='elimination'?'elimination':'qualification')];
  foreach(teamChartMetrics() as $key=>[,,$limit]){
   $value=$data[$key]??null;
   $row[$key]=$value!==null&&$value!==''&&is_numeric($value)&&is_finite((float)$value)&&(float)$value>=0&&($limit===null||(float)$value<=$limit)?(float)$value:null;
  }
  $rows[]=$row;
 }
 return $rows;
}

function teamChartNumber(float $value): string {return number_format($value,1,'.','');}

function teamMatchChart(array $rows,array $keys,string $title,float $ceiling,string $unit): void {
 $metrics=teamChartMetrics();$width=max(600,90+count($rows)*65);$height=285;
 $hasValues=false;foreach($rows as $row)foreach($keys as $key)if($row[$key]!==null)$hasValues=true;
 if(!$hasValues){echo '<section class="team-chart"><h3>'.h($title).'</h3><p>No values submitted for these statistics yet.</p></section>';return;}
 $left=50;$right=$width-25;$top=22;$bottom=238;
 $x=fn(int $i): float=>count($rows)>1?$left+($right-$left)*$i/(count($rows)-1):($left+$right)/2;
 $y=fn(float $v): float=>$bottom-($bottom-$top)*$v/$ceiling;
 echo '<section class="team-chart"><h3>'.h($title).'</h3><div class="team-chart-legend">';
 foreach($keys as $key)echo '<span><i style="background:'.h($metrics[$key][1]).'"></i>'.h($metrics[$key][0]).'</span>';
 echo '</div><div class="team-chart-scroll"><svg class="match-stat-chart" width="'.h($width).'" height="'.h($height).'" viewBox="0 0 '.h($width).' '.h($height).'" role="img" aria-label="'.h($title.' by match, '.$unit).'"><title>'.h($title.' by match').'</title><desc>Points link to match details. Hover a point to see its value. Missing values leave a gap.</desc>';
 for($tick=0;$tick<=5;$tick++){
  $value=$ceiling*$tick/5;$cy=$y($value);
  echo '<line class="chart-grid-line" x1="'.h($left).'" x2="'.h($right).'" y1="'.h($cy).'" y2="'.h($cy).'"/><text class="chart-label" x="'.h($left-8).'" y="'.h($cy+4).'" text-anchor="end">'.h($ceiling===5.0?(string)$tick:teamChartNumber($value)).'</text>';
 }
 foreach($rows as $i=>$row)echo '<a href="'.h($row['url']).'"><text class="chart-label" x="'.h($x($i)).'" y="'.h($bottom+24).'" text-anchor="middle">'.h($row['label']).'</text></a>';
 foreach($keys as $key){
  $color=$metrics[$key][1];$previous=null;
  foreach($rows as $i=>$row){
   $value=$row[$key];
   if($value===null){$previous=null;continue;}
   $cx=$x($i);$cy=$y($value);
   if($previous)echo '<line x1="'.h($previous[0]).'" y1="'.h($previous[1]).'" x2="'.h($cx).'" y2="'.h($cy).'" stroke="'.h($color).'" stroke-width="2.5"/>';
   $previous=[$cx,$cy];
  }
  foreach($rows as $i=>$row)if($row[$key]!==null){
   $description=$row['label'].' · '.$metrics[$key][0].': '.teamChartNumber($row[$key]);
   echo '<a href="'.h($row['url']).'" aria-label="'.h($description.'; open match').'"><circle class="chart-point" cx="'.h($x($i)).'" cy="'.h($y($row[$key])).'" r="4.5" fill="'.h($color).'"><title>'.h($description).'</title></circle></a>';
  }
 }
 echo '</svg></div></section>';
}

function teamRangeChart(array $rows,string $key,float $ceiling): void {
 [$label,$color]=teamChartMetrics()[$key];
 $values=array_values(array_filter(array_column($rows,$key),fn($v)=>$v!==null));
 echo '<section class="team-range-chart"><h3>'.h($label).'</h3>';
 if(!$values){echo '<p>No values submitted.</p></section>';return;}
 $min=min($values);$max=max($values);$avg=array_sum($values)/count($values);
 $x=fn(float $v): float=>25+350*$v/$ceiling;
 $description=$label.': minimum '.teamChartNumber($min).', average '.teamChartNumber($avg).', maximum '.teamChartNumber($max).'. Based on '.count($values).' submitted values.';
 echo '<svg viewBox="0 0 400 80" role="img" aria-label="'.h($description).'"><title>'.h($description).'</title><line class="chart-range-track" x1="25" x2="375" y1="30" y2="30"/>';
 echo '<line x1="'.h($x($min)).'" x2="'.h($x($max)).'" y1="30" y2="30" stroke="'.h($color).'" stroke-width="14" stroke-linecap="round"/>';
 foreach([$min,$max] as $value)echo '<line x1="'.h($x($value)).'" x2="'.h($x($value)).'" y1="18" y2="42" stroke="'.h($color).'" stroke-width="2"/>';
 echo '<circle class="chart-average" cx="'.h($x($avg)).'" cy="30" r="6"/><text class="chart-label" x="25" y="66">0</text><text class="chart-label" x="375" y="66" text-anchor="end">'.h(teamChartNumber($ceiling)).'</text></svg>';
 echo '<div class="range-values"><span>Min <b>'.h(teamChartNumber($min)).'</b></span><span>Avg <b>'.h(teamChartNumber($avg)).'</b></span><span>Max <b>'.h(teamChartNumber($max)).'</b></span></div><small>'.h(count($values)).' submitted values</small></section>';
}

function teamPerformanceCharts(array $reports): void {
 $rows=teamChartRows($reports);
 echo '<section class="team-performance"><h2>Match statistics</h2>';
 if(!$rows){echo '<p>No submitted match reports yet. Charts will appear as scouting reports are submitted.</p></section>';return;}
 echo '<p>Submitted matches in schedule order. Hover a point for its value or select it to open the match. Missing values are left blank.</p>';
 $scoreValues=[];foreach($rows as $row)foreach(['match_score','auto_score','teleop_score'] as $key)if($row[$key]!==null)$scoreValues[]=$row[$key];
 $ceiling=$scoreValues?max(50.0,ceil(max($scoreValues)/50)*50):50.0;
 teamMatchChart($rows,['match_score','auto_score','teleop_score'],'Scores per match',$ceiling,'points');
 teamMatchChart($rows,['defense_rating','defensive_vulnerability'],'Defense per match',5.0,'rating from 0 to 5');
 echo '<h2>Statistic ranges</h2><p>The colored bar spans minimum to maximum; the dot marks the average. Score charts share a points scale. Defense charts use 0–5; vulnerability 5 means not vulnerable.</p><div class="team-range-grid">';
 foreach(teamChartMetrics() as $key=>[,,$limit])teamRangeChart($rows,$key,$limit===null?$ceiling:5.0);
 echo '</div><details class="team-chart-data"><summary>View statistics by match</summary><div class="scroll"><table><thead><tr><th>Match</th>';
 foreach(teamChartMetrics() as [$label])echo '<th>'.h($label).'</th>';
 echo '</tr></thead><tbody>';
 foreach($rows as $row){echo '<tr><th><a href="'.h($row['url']).'">'.h($row['label']).'</a></th>';foreach(teamChartMetrics() as $key=>$_)echo '<td>'.h($row[$key]===null?'—':teamChartNumber($row[$key])).'</td>';echo '</tr>';}
 echo '</tbody></table></div></details></section>';
}

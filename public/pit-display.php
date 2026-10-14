<?php
declare(strict_types=1);
page('Pit Display',true);
if(!$e){echo '<p>Select an event in Admin to display teams.</p>';endpage();return;}
$teams=query('SELECT number,name FROM teams WHERE event_id=? ORDER BY number',[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
$reports=query("SELECT s.team_number,sc.data FROM scouting sc JOIN slots s ON s.id=sc.slot_id JOIN matches m ON m.id=s.match_id WHERE m.event_id=? AND sc.status='submitted'",[$e['id']])->fetchAll(PDO::FETCH_ASSOC);
$byTeam=[];foreach($reports as $report)$byTeam[$report['team_number']][]=json_decode($report['data'],true)?:[];
$fields=['number'=>'Team number','match_score'=>'Avg total','auto_score'=>'Avg auto','teleop_score'=>'Avg teleop','endgame_score'=>'Avg endgame'];
echo '<div class="pit-display-controls" id="pitDisplayControls"><div class="pit-display-sort"><label>Sort by<select id="pitDisplaySort">';
foreach($fields as $key=>$label)echo '<option value="'.h($key).'">'.h($label).'</option>';
echo '<option value="name">Team name</option></select></label><button type="button" id="pitDisplayDirection" aria-label="Sort descending">Ascending ↑</button><div class="pit-team-combo" id="pitTeamCombo"><label for="pitTeamSearch">Team numbers</label><input type="text" id="pitTeamSearch" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pitTeamSuggestions" inputmode="numeric" autocomplete="off" placeholder="Select or type team #"><div id="pitTeamSuggestions" class="pit-team-suggestions" role="listbox" aria-label="Event team numbers" hidden></div><div id="pitTeamSelections" class="pit-team-selections" aria-label="Selected teams"></div></div><button type="button" id="pitDisplayReset">Reset</button></div><div class="pit-display-filters">';
foreach(array_slice($fields,1,null,true) as $key=>$label)echo '<fieldset><legend>'.h($label).'</legend><label><span class="sr-only">Minimum '.h($label).'</span><input type="number" min="0" step="any" data-filter="'.h($key).'" data-bound="min" placeholder="Min"></label><label><span class="sr-only">Maximum '.h($label).'</span><input type="number" min="0" step="any" data-filter="'.h($key).'" data-bound="max" placeholder="Max"></label></fieldset>';
echo '</div></div><p class="pit-display-help">Around each team number: total upper left, auto upper right, endgame lower left, teleop lower right. Averages use submitted reports; — means no recorded value.</p><p id="pitDisplayStatus" role="status">'.count($teams).' teams</p><div class="scroll"><table class="pit-display-table" id="pitDisplayTable"><thead><tr><th scope="col">Team / Score Averages</th><th scope="col">Team name</th></tr></thead><tbody>';
foreach($teams as $team){
 $entries=$byTeam[$team['number']]??[];$metrics=[];foreach(array_slice(array_keys($fields),1) as $key)$metrics[$key]=metricAverage($entries,$key);
 echo '<tr data-number="'.h($team['number']).'" data-name="'.h($team['name']).'"';foreach($metrics as $key=>$value)echo ' data-'.str_replace('_','-',$key).'="'.h($value??'').'"';echo '><td><div class="pit-score-team">';
 foreach(['match_score'=>['total','Total'],'auto_score'=>['auto','Auto'],'endgame_score'=>['endgame','Endgame'],'teleop_score'=>['teleop','Teleop']] as $key=>[$position,$label])echo '<span class="pit-corner pit-corner-'.h($position).'"><small>'.h($label).'</small><span>'.h($metrics[$key]===null?'—':number_format($metrics[$key],1)).'</span></span>';
 echo '<a class="pit-display-team-number" href="/?p=team&n='.h($team['number']).'" aria-label="Team '.h($team['number']).' performance">'.teamNumber($team['number']).'</a></div></td><td>'.h($team['name']?:'Unnamed team').'</td></tr>';
}
echo '</tbody></table></div><script src="'.h(asset('pit-display.js')).'" defer></script>';endpage();

<?php
declare(strict_types=1);
$settings=siteSettings();
$year=max(2015,min((int)date('Y')+1,(int)($_GET['year']??date('Y'))));
$refresh=isset($_GET['refresh']);$list=catalog($year,$refresh);$districtList=districts($year,$refresh);
$type=in_array($_GET['type']??'district',['district','regional','worlds'],true)?($_GET['type']??'district'):'district';
$district=(string)($_GET['district']??'NE');if(!in_array($district,array_column($districtList,'code'),true))$district=$districtList[0]['code']??'';
$allowed=$type==='district'&&$district?districtCodes($year,$district,$refresh):[];
$filtered=array_values(array_filter($list,fn($item)=>$type==='district'?in_array($item['code'],$allowed,true):eventType($item['name'])===$type));

echo '<div class="admin-layout"><section class="admin-section" id="event-selection"><h2>Event Selection</h2><div class="admin-columns"><div><h3>Choose an event</h3>';
if(!$list)echo '<aside class="warn">No event list is cached for this year. Check the server Internet connection and refresh the event list.</aside>';
echo '<form method="get"><input type="hidden" name="p" value="admin"><div class="admin-field-row"><label>Year<select name="year" onchange="this.form.submit()">';
for($y=(int)date('Y');$y>=2015;$y--)echo '<option value="'.h($y).'"'.($y===$year?' selected':'').'>'.h($y).'</option>';
echo '</select></label><label>Competition type<select name="type" onchange="this.form.submit()">';
foreach(['district'=>'Districts','regional'=>'Regionals','worlds'=>'Worlds'] as $value=>$label)echo '<option value="'.h($value).'"'.($value===$type?' selected':'').'>'.h($label).'</option>';
echo '</select></label></div>';
if($type==='district'){
 echo '<label>FIRST district<select name="district" onchange="this.form.submit()">';
 foreach($districtList as $item)echo '<option value="'.h($item['code']).'"'.($item['code']===$district?' selected':'').'>'.h($item['name']).'</option>';
 echo '</select></label>';
}else echo '<input type="hidden" name="district" value="'.h($district).'">';
echo '</form><p>'.count($filtered).' events shown. <a href="/?p=admin&year='.h($year).'&type='.h($type).'&district='.h($district).'&refresh=1#event-selection">Refresh FIRST event list</a></p><form method="post" action="/?p=event">'.csrf().'<input type="hidden" name="year" value="'.h($year).'"><label>Event<select name="event_code" required><option value="">Choose an event</option>';
foreach($filtered as $item)echo '<option value="'.h($item['code']).'">'.h($item['name']).' — '.h($item['location']).'</option>';
echo '</select></label><button'.(!$filtered?' disabled':'').'>Select event</button></form></div><div>';
if($e){
 $tc=query('SELECT count(*) FROM teams WHERE event_id=?',[$e['id']])->fetchColumn();
 $mc=query("SELECT count(*) FROM matches WHERE event_id=? AND stage='qualification'",[$e['id']])->fetchColumn();
 $pc=query("SELECT count(*) FROM matches WHERE event_id=? AND stage='elimination'",[$e['id']])->fetchColumn();
 echo '<p class="event-confirmation">Selected event: <strong>'.h($e['name']).'</strong></p><p>'.h($tc).' teams · '.h($mc).' qualification matches · '.h($pc).' elimination matches</p>';
 if(preg_match('/^(\d{4})([a-z0-9]+)$/',$e['event_key']??''))echo '<form method="post" action="/?p=refresh_event">'.csrf().'<button>Refresh Official Data</button></form>';
}else echo '<p>No event selected yet.</p>';
echo '</div></div></section>';

echo '<section class="admin-section" id="user-management"><h2>User Management</h2><div class="admin-columns"><div><h3>Add user</h3><form method="post" action="/?p=user">'.csrf().'<label>Username<input name="name" required autocomplete="off"></label><label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><div class="admin-field-row"><label>Role<select name="role">';
foreach(['scout','pit','drive','mentor','admin'] as $role)echo '<option>'.h($role).'</option>';
echo '</select></label><label>Position<select name="position"><option value="">None</option>';
foreach(['R1','R2','R3','B1','B2','B3'] as $position)echo '<option>'.h($position).'</option>';
echo '</select></label></div><button>Create account</button></form></div><div><h3>Existing users</h3><div class="scroll"><table class="admin-users"><thead><tr><th>Username</th><th>Role</th><th>Position</th><th>Password</th></tr></thead><tbody>';
foreach(query('SELECT id,name,role,position FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $user){
 echo '<tr><td>'.h($user['name']).'</td><td>'.h($user['role']).'</td><td>'.h($user['position']?:'—').'</td><td><details class="password-reset"><summary aria-label="Reset password for '.h($user['name']).'">Reset password</summary><form method="post" action="/?p=reset_password">'.csrf().'<input type="hidden" name="user_id" value="'.h($user['id']).'"><label>New password<input type="password" name="new_password" minlength="10" maxlength="72" required autocomplete="new-password"></label><small>At least 10 characters.</small><button type="submit">Reset password</button></form></details></td></tr>';
}
echo '</tbody></table></div></div></div></section>';

echo '<section class="admin-section" id="appearance"><h2>Site Appearance</h2><div class="admin-columns"><div><h3>Logo Selection</h3>';
if($settings['logo_uploaded_at'])echo '<img class="logo-preview" src="/?p=site_logo&v='.rawurlencode($settings['logo_uploaded_at']).'" alt="Current header logo">';
echo '<form method="post" action="/?p=upload_logo" enctype="multipart/form-data">'.csrf().'<label>Header logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required></label><small>JPEG, PNG, or WebP, up to 4 MB.</small><button>Upload logo</button></form><h3>Dark Mode</h3><form method="post" action="/?p=dark_mode" class="theme-form">'.csrf().'<label class="theme-switch"><input type="checkbox" name="dark_mode" value="1"'.(picked($settings['dark_mode'])?' checked':'').' onchange="this.form.requestSubmit()"><span class="theme-slider" aria-hidden="true"></span><span>Dark Mode</span></label><noscript><button>Save theme</button></noscript></form></div><div><h3>Site Title</h3><form method="post" action="/?p=site_title">'.csrf().'<label><span class="sr-only">Site title</span><input name="site_title" type="text" maxlength="80" required value="'.h($settings['site_title']).'"></label><button>Save site title</button></form><div id="highlighted-team-setting"><h3>Highlighted Team</h3><form method="post" action="/?p=highlighted_team">'.csrf().'<label><span class="sr-only">Highlighted team number</span><input name="highlighted_team" type="number" min="1" max="99999" required value="'.h($settings['highlighted_team']).'"></label><small>This team appears in green on Matches, Teams, and Pick List.</small><button>Save highlighted team</button></form></div></div></div></section>';

echo '<section class="admin-section" id="year-configuration"><h2>Year Specific Configuration</h2><h3 id="field-background">Field Background</h3>';
if($e){
 if(query('SELECT count(*) FROM event_field_images WHERE event_id=?',[$e['id']])->fetchColumn())echo '<img class="field-preview" src="/?p=field_image" alt="Current field background">';
 echo '<form method="post" action="/?p=upload_field" enctype="multipart/form-data">'.csrf().'<label>Field image<input type="file" name="field_image" accept="image/jpeg,image/png,image/webp" required></label><small>JPEG, PNG, or WebP, up to 4 MB.</small><button>Upload field image</button></form>';
}else echo '<p>Select an event to configure its field background.</p>';
echo '</section>';

function renderAdminChoices(string $key,array $options): void {
 $label=pitChoiceFields()[$key][0];
 echo '<fieldset class="config-field" data-key="'.h($key).'"><legend>'.h($label).'</legend><div class="config-options">';
 foreach($options as $value){
  [$pitCount,$matchCount]=pitChoiceUsage($key,$value);
  echo '<div class="config-option" data-original="'.h($value).'" data-pit-usage="'.h($pitCount).'" data-match-usage="'.h($matchCount).'"><label><span class="sr-only">'.h($label).' choice</span><input name="choices['.h($key).'][]" value="'.h($value).'" maxlength="'.($key==='tags'?40:80).'" required></label>';
  if($key==='tags')echo '<label class="config-color"><span>Color</span><input type="color" name="tag_colors[]" value="'.h(tagColor($value)).'" aria-label="Color for '.h($value).'"></label>';
  echo '<span class="config-usage">'.($pitCount+$matchCount?h($pitCount).' pit · '.h($matchCount).' match':'Unused').'</span><button type="button" class="config-remove" aria-label="Remove '.h($value).'">Remove</button></div>';
 }
 echo '</div><button type="button" class="config-add">+ Add '.($key==='tags'?'tag':'choice').'</button></fieldset>';
}
$configured=pitChoices();
echo '<section class="admin-section" id="pit-choices"><h2>Pit Scouting Configuration</h2><p>Open a field to manage its dropdown choices. Removing a choice in use requires confirmation and retains saved scouting data.</p><form class="pit-choices-form" method="post" action="/?p=pit_choices">'.csrf().'<input type="hidden" name="scope" value="pit"><input type="hidden" name="acknowledge_removal" value="0">';
foreach(['robot_meta','intake','shooter_type','driver_experience','human_player_experience'] as $key){
 echo '<details class="admin-rollup"><summary>'.h(pitChoiceFields()[$key][0]).'<small>'.count($configured[$key]).' choices</small></summary>';
 renderAdminChoices($key,$configured[$key]);echo '</details>';
}
echo '<button>Save Pit Scouting Configuration</button></form></section><section class="admin-section" id="tag-management"><h2>Tag Management</h2><p>Manage tag text and colors. Removing a tag in use requires confirmation and retains saved records.</p><form class="pit-choices-form" method="post" action="/?p=pit_choices">'.csrf().'<input type="hidden" name="scope" value="tags"><input type="hidden" name="acknowledge_removal" value="0">';
renderAdminChoices('tags',$configured['tags']);
echo '<button>Save Tags</button></form></section></div><script src="'.h(asset('pit-config.js')).'" defer></script>';

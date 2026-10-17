<?php
declare(strict_types=1);
require __DIR__.'/../public/pit-fields.php';
function h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function tagStyle(string $v): string {return '';}
function check(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);}
function rejected(callable $fn,string $msg): void {try{$fn();}catch(InvalidArgumentException $ex){return;}throw new RuntimeException($msg);}
$choices=['robot_meta'=>['Turret','Other'],'intake'=>['Ground'],'shooter_type'=>['Fixed'],'tags'=>['Defense'],'driver_experience'=>['Veteran'],'human_player_experience'=>['Veteran']];
$defaults=defaultPitFields($choices);check(count($defaults)===18,'Legacy fields become defaults');
check(validatePitFields($defaults,$defaults)===$defaults,'Defaults validate');
$fields=[];$i=0;
foreach(array_keys(pitFieldTypes()) as $type)$fields[]=['id'=>'custom_'.str_pad(dechex(++$i),24,'0',STR_PAD_LEFT),'label'=>'Field '.$type,'type'=>$type,'options'=>in_array($type,['dropdown','multiselect','tags'],true)?['A','B']:[]];
check(validatePitFields($fields,$defaults)===$fields,'All supported new field types');
$reordered=array_reverse($fields);check(validatePitFields($reordered,$fields)[0]['id']===$fields[6]['id'],'Order and stable IDs survive save');
$renamed=$fields;$renamed[0]['label']='Renamed';check(validatePitFields($renamed,$fields)[0]['id']===$fields[0]['id'],'Rename preserves data key');
check(validatePitFields([],$defaults)===[],'Allow all fields removed');
$bad=$fields;$bad[]=$fields[0];rejected(fn()=>validatePitFields($bad,$defaults),'Reject duplicate ID');
$bad=$fields;$bad[0]['id']='notes';rejected(fn()=>validatePitFields($bad,[]),'Reject reserved IDs for new fields');
$bad=$fields;$bad[0]['options']=['A','a'];rejected(fn()=>validatePitFields($bad,$defaults),'Reject duplicate choices');
$bad=$fields;$bad[0]['type']='script';rejected(fn()=>validatePitFields($bad,$defaults),'Reject unknown type');
$bad=$fields;$bad[0]['options']=[];rejected(fn()=>validatePitFields($bad,$defaults),'Reject empty choice list');
$colored=$fields;$colored[4]['colors']=['A'=>'#FF8000','B'=>'#001122'];
check(validatePitFields($colored,$fields)[4]['colors']['A']==='#ff8000','Normalize and preserve per-option colors');
$bad=$colored;$bad[4]['colors']['A']='red;position:absolute';rejected(fn()=>validatePitFields($bad,$fields),'Reject CSS injection');
$bad=$colored;$bad[4]['colors']['Unknown']='#aabbcc';rejected(fn()=>validatePitFields($bad,$fields),'Reject colors for unknown options');
ob_start();renderPitField($colored[4],[],2370,null);$coloredHtml=ob_get_clean();check(str_contains($coloredHtml,'background:#FF8000')&&str_contains($coloredHtml,'color:#ffffff'),'Render multi-select colors with contrasting text');
ob_start();renderPitProfile([$colored[4]],[$colored[4]['id']=>['B']]);$coloredProfile=ob_get_clean();check(str_contains($coloredProfile,'background:#001122'),'Profiles use field-specific colors');
$previous=['removed_field'=>'keep me','robot_meta'=>'Other','robot_meta_other'=>'Old custom','tags'=>['Retired tag'],'old_image'=>['mime'=>'image/png','base64'=>'YWJj']];
$post=[];foreach($fields as $f)$post[$f['id']]=match($f['type']){'dropdown'=>'A','multiselect','tags'=>['A','B'],'number'=>'0','text','textarea'=>'<script>alert(1)</script>',default=>''};
$data=readPitValues($fields,$post,$previous);
check($data['removed_field']==='keep me'&&$data['old_image']===$previous['old_image'],'Saving retains removed values and images');
check($data[$fields[1]['id']]==='0','Numeric zero survives');
check($data[$fields[4]['id']]===['A','B'],'Multiple values survive');
check(readPitValues([$fields[4]],[],$data)[$fields[4]['id']]===[],'Uncheck every value clears selection');
$bad=$post;$bad[$fields[1]['id']]='NaN';rejected(fn()=>readPitValues($fields,$bad,$previous),'Reject invalid number');
$bad=$post;$bad[$fields[4]['id']]=['Unknown'];rejected(fn()=>readPitValues($fields,$bad,$previous),'Reject unconfigured options');
$bad=$post;$bad[$fields[2]['id']]=['array'];rejected(fn()=>readPitValues($fields,$bad,$previous),'Reject malformed text');
$oldChoice=$fields[0];check(readPitValues([$oldChoice],[$oldChoice['id']=>'Retired'],[$oldChoice['id']=>'Retired'])[$oldChoice['id']]==='Retired','Existing retired choices remain editable');
check(pitFieldVersion($fields)!==pitFieldVersion($reordered),'Version detects ordering changes');
$meta=array_values(array_filter($defaults,fn($f)=>$f['id']==='robot_meta'));
check(readPitValues($meta,['robot_meta'=>'Other','robot_meta_other'=>'Custom'],[])['robot_meta_other']==='Custom','Legacy Other detail survives');
rejected(fn()=>readPitValues($meta,['robot_meta'=>'Other'],[]),'Require Other detail');
check(pitImageSource(['mime'=>'text/html','base64'=>'YWJj'])==='','Reject unsafe image type');
check(pitImageSource(['mime'=>'image/png','base64'=>'" onerror="x'])==='','Reject malformed image data');
check(pitImageSource(['mime'=>'image/png','base64'=>'YWJj'])==='data:image/png;base64,YWJj','Safe image preview');
rejected(fn()=>readPitImage(['error'=>UPLOAD_ERR_INI_SIZE]),'Reject failed upload');
ob_start();foreach($reordered as $f)renderPitField($f,$data,2370,null);$html=ob_get_clean();
check(!str_contains($html,'<script>alert')&&str_contains($html,'&lt;script&gt;'),'Escape submitted text');
check(strpos($html,'data-field-id="'.$reordered[0]['id'].'"')<strpos($html,'data-field-id="'.$reordered[1]['id'].'"'),'Form renders configured order');
check(str_contains($html,'type="file"')&&str_contains($html,'type="checkbox"')&&str_contains($html,'type="number"'),'Render image, multi, number controls');
ob_start();renderPitProfile($fields,$data);$profile=ob_get_clean();check(str_contains($profile,'Field multiselect')&&!str_contains($profile,'<script>alert'),'Profiles render custom fields safely');
echo "Passed: defaults, schema edits and order, validation, values, removed data, retired choices, images, escaping and profile rendering.\n";

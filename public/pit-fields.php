<?php
declare(strict_types=1);
function pitFieldTypes(): array {return ['dropdown'=>'Dropdown','number'=>'Number','text'=>'Text input','textarea'=>'Long text','multiselect'=>'Multiple select','tags'=>'Tags','image'=>'Image'];}
function defaultPitFields(array $choices): array {
 $fields=[];
 foreach(['robot_photo'=>['Robot photo','image'],'robot_meta'=>['Robot Meta','dropdown'],'drivetrain'=>['Drivetrain','text'],'width'=>['Width (inches)','number'],'length'=>['Length (inches)','number'],'height'=>['Height (inches)','number'],'weight_lbs'=>['Weight (lbs)','number'],'intake'=>['Intake','dropdown'],'shooter_type'=>['Shooter Type','dropdown'],'tags'=>['Tags','tags'],'autonomous'=>['Autonomous Notes','textarea'],'driver_experience'=>['Driver Experience Level','dropdown'],'human_player_experience'=>['Human Player Experience Level','dropdown'],'endgame'=>['Endgame','textarea'],'strategy'=>['Preferred strategy','textarea'],'reliability'=>['Reliability','textarea'],'requirements'=>['Special requirements','textarea'],'notes'=>['Notes','textarea']] as $id=>[$label,$type])$fields[]=['id'=>$id,'label'=>$label,'type'=>$type,'options'=>$choices[$id]??[]];
 return $fields;
}
function pitFieldConfig(): array {return json_decode(query('SELECT pit_choices FROM site_settings WHERE id=1')->fetchColumn()?:'{}',true)?:[];}
function pitFields(): array {static $fields; if($fields!==null)return $fields;$config=pitFieldConfig();return $fields=$config['fields']??defaultPitFields(pitChoices());}
function pitFieldVersion(array $fields): string {return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));}
function validatePitFields($fields,array $existing): array {
 if(!is_array($fields)||!array_is_list($fields)||count($fields)>50)throw new InvalidArgumentException('Use up to 50 fields.');
 $known=array_column($existing,null,'id');$seen=[];$clean=[];$images=0;
 foreach($fields as $f){
  if(!is_array($f))throw new InvalidArgumentException('Invalid field.');
  $id=$f['id']??'';$label=$f['label']??'';$type=$f['type']??'';
  if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_]{0,59}$/',$id)||isset($seen[$id])||(!isset($known[$id])&&!preg_match('/^custom_[a-f0-9]{16,40}$/',$id)))throw new InvalidArgumentException('Invalid or duplicate field ID.');
  if(!is_string($label)||trim($label)===''||strlen($label)>100||!preg_match('//u',$label)||preg_match('/[\x00-\x1f\x7f]/',$label))throw new InvalidArgumentException('Field names must be 1–100 characters.');
  if(!is_string($type)||!isset(pitFieldTypes()[$type]))throw new InvalidArgumentException('Choose a supported field type.');
  if($id==='robot_photo'&&$type!=='image')throw new InvalidArgumentException('The robot thumbnail field must remain an image. Add another field for a different type.');
  if($type==='image'&&++$images>12)throw new InvalidArgumentException('Use up to 12 image fields.');
  $options=$f['options']??[];
  if(!is_array($options)||!array_is_list($options)||count($options)>50)throw new InvalidArgumentException('Use up to 50 choices per field.');
  $values=[];$lower=[];
  foreach($options as $option){if(!is_string($option)||trim($option)===''||strlen($option)>80||!preg_match('//u',$option)||preg_match('/[\x00-\x1f\x7f]/',$option))throw new InvalidArgumentException('Choices must be 1–80 characters.');$option=trim($option);if(isset($lower[strtolower($option)]))throw new InvalidArgumentException('Remove duplicate choices.');$lower[strtolower($option)]=true;$values[]=$option;}
  if(in_array($type,['dropdown','multiselect','tags'],true)&&!$values)throw new InvalidArgumentException('Add at least one choice for '.trim($label).'.');
  $seen[$id]=true;$clean[]=['id'=>$id,'label'=>trim($label),'type'=>$type,'options'=>$values];
 }
 return $clean;
}
function pitSavedOptions(array $field,$value): array {
 $options=$field['options'];$saved=is_array($value)?$value:[$value];
 foreach($saved as $v)if(is_string($v)&&$v!==''&&!in_array($v,$options,true))$options[]=$v;
 return $options;
}
function readPitValues(array $fields,array $post,array $previous): array {
 $data=$previous;
 foreach($fields as $f){
  $id=$f['id'];$type=$f['type'];if($type==='image')continue;
  $value=$post[$id]??(in_array($type,['multiselect','tags'],true)?[]:'');
  if(in_array($type,['multiselect','tags'],true)){
   if(!is_array($value)||!array_is_list($value)||count($value)>50)throw new InvalidArgumentException('Invalid selections for '.$f['label'].'.');
   foreach($value as $v)if(!is_string($v)||!in_array($v,pitSavedOptions($f,$previous[$id]??[]),true))throw new InvalidArgumentException('Choose configured options for '.$f['label'].'.');
   $data[$id]=array_values(array_unique($value));
  }else{
   if(!is_string($value)||strlen($value)>($type==='textarea'?10000:1000)||!preg_match('//u',$value))throw new InvalidArgumentException('Invalid value for '.$f['label'].'.');
   $value=trim($value);
   if($type==='number'&&$value!==''&&(!is_numeric($value)||!is_finite((float)$value)))throw new InvalidArgumentException('Enter a number for '.$f['label'].'.');
   if($type==='number'&&in_array($id,['width','length','height','weight_lbs'],true)&&$value!==''&&(float)$value<0)throw new InvalidArgumentException('Dimensions and weight must be nonnegative.');
   if($type==='dropdown'&&$value!==''&&!in_array($value,pitSavedOptions($f,$previous[$id]??''),true))throw new InvalidArgumentException('Choose a configured option for '.$f['label'].'.');
   $data[$id]=$value;
  }
 }
 // Keep the existing Robot Meta "Other" detail compatible with pick lists.
 foreach($fields as $f)if($f['id']==='robot_meta'&&$f['type']==='dropdown'){
  $custom=$post['robot_meta_other']??'';
  if(!is_string($custom)||strlen($custom)>80||(($data['robot_meta']??'')==='Other'&&trim($custom)===''))throw new InvalidArgumentException('Enter the custom Robot Meta.');
  $data['robot_meta_other']=($data['robot_meta']??'')==='Other'?trim($custom):'';
 }
 return $data;
}
function readPitImage(array $upload): array {
 if(($upload['error']??-1)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])||($upload['size']??0)<1||$upload['size']>4*1024*1024)throw new InvalidArgumentException('Choose a JPEG, PNG, or WebP image up to 4 MB.');
 $info=@getimagesize($upload['tmp_name']);$mime=$info['mime']??'';
 if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new InvalidArgumentException('Choose a JPEG, PNG, or WebP image.');
 return ['mime'=>$mime,'base64'=>base64_encode(file_get_contents($upload['tmp_name']))];
}
function pitImageSource($value): string {
 if(!is_array($value)||!in_array($value['mime']??null,['image/jpeg','image/png','image/webp'],true)||!is_string($value['base64']??null)||!preg_match('/^[A-Za-z0-9+\/=]+$/D',$value['base64']))return '';
 return 'data:'.$value['mime'].';base64,'.$value['base64'];
}
function renderPitField(array $f,array $data,int $team,?array $photo): void {
 $id=$f['id'];$type=$f['type'];$v=$data[$id]??'';$name='fields['.$id.']';
 echo '<div class="pit-dynamic-field" data-field-id="'.h($id).'">';
 if($type==='image'){
  $src=$id==='robot_photo'&&$photo?'/?p=robot_photo&n='.$team.'&v='.rawurlencode($photo['uploaded_at']):pitImageSource($v);
  echo '<label>'.h($f['label']).'<input type="file" name="image_'.h($id).'" accept="image/jpeg,image/png,image/webp"></label>';
  if($src)echo '<img class="pit-photo" src="'.h($src).'" alt="'.h($f['label']).'"><label class="pit-image-remove"><input type="checkbox" name="remove_images['.h($id).']" value="1"> Remove saved image</label>';
  echo '<small>JPEG, PNG, or WebP · up to 4 MB. Images save with the pit record.</small>';
 }elseif(in_array($type,['multiselect','tags'],true)){
  $selected=is_array($v)?array_filter($v,'is_string'):(is_string($v)&&$v!==''?[$v]:[]);
  echo '<fieldset class="pit-multi '.($type==='tags'?'pit-tag-options':'').'"><legend>'.h($f['label']).'</legend><div class="pit-option-grid">';
  foreach(pitSavedOptions($f,$v) as $option)echo '<label'.($type==='tags'?tagStyle($option):'').'><input type="checkbox" name="'.h($name).'[]" value="'.h($option).'"'.(in_array($option,$selected,true)?' checked':'').'> '.h($option).'</label>';
  echo '</div><small>Select all that apply.</small></fieldset>';
 }else{
  $v=is_scalar($v)?(string)$v:'';echo '<label>'.h($f['label']);
  if($type==='dropdown'){
   echo '<select name="'.h($name).'"'.($id==='robot_meta'?' data-robot-meta':'').'><option value="">Choose an option</option>';
   foreach(pitSavedOptions($f,$v) as $option)echo '<option value="'.h($option).'"'.($v===$option?' selected':'').'>'.h($option).'</option>';
   echo '</select>';
  }elseif($type==='textarea')echo '<textarea name="'.h($name).'" maxlength="10000">'.h($v).'</textarea>';
  else echo '<input name="'.h($name).'" type="'.($type==='number'?'number':'text').'"'.($type==='number'?' step="any"':' maxlength="1000"').' value="'.h($v).'">';
  echo '</label>';
  if($id==='robot_meta'&&$type==='dropdown')echo '<label data-robot-meta-other'.($v==='Other'?'':' hidden').'>Custom Robot Meta<input name="fields[robot_meta_other]" maxlength="80" value="'.h(is_string($data['robot_meta_other']??null)?$data['robot_meta_other']:'').'"'.($v==='Other'?' required':'').'></label>';
 }
 echo '</div>';
}
function renderPitProfile(array $fields,array $data): void {
 foreach($fields as $f){$v=$data[$f['id']]??'';if($f['id']==='robot_photo'||$v===''||$v===[]||$v===null)continue;
  echo '<div class="pit-profile-field"><b>'.h($f['label']).':</b> ';
  if($f['type']==='image'){if($src=pitImageSource($v))echo '<img class="pit-photo" src="'.h($src).'" alt="'.h($f['label']).'">';}
  elseif(is_array($v)){foreach($v as $item)if(is_scalar($item))echo '<span class="team-tag"'.($f['type']==='tags'?tagStyle((string)$item):'').'>'.h($item).'</span>';}
  else echo nl2br(h($f['id']==='robot_meta'&&$v==='Other'?($data['robot_meta_other']??$v):$v));
  echo '</div>';
 }
}

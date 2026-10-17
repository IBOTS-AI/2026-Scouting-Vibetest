<?php
declare(strict_types=1);
require __DIR__.'/../public/battery-management.php';
function h($v): string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejected(callable $fn,string $message): void {try{$fn();}catch(InvalidArgumentException $ex){return;}throw new RuntimeException($message);}
$base=['label'=>' B01 ','rated_ah'=>'18','purchased_on'=>'2026-01-31'];$valid=validateBattery($base);check($valid['label']==='B01'&&$valid['rated_ah']===18.0,'Clean labels and numeric capacity');
rejected(fn()=>validateBattery(['label'=>'  ']),'Require label');rejected(fn()=>validateBattery(array_replace($base,['purchased_on'=>'2026-02-30'])),'Reject invalid date');rejected(fn()=>batteryDate(['date'=>'0000-01-01'],'date'),'Reject year zero');rejected(fn()=>batteryNumber('NaN','voltage',0,100),'Reject NaN');rejected(fn()=>batteryNumber('-1','voltage',0,100),'Reject negative voltage');
$simplified=validateBattery($base+['serial_number'=>'unused','in_service_on'=>'invalid']);check(!isset($simplified['serial_number'])&&!isset($simplified['in_service_on']),'Removed fields are not collected');
$entry=['recorded_on'=>'2026-10-17','check_type'=>'Load test','condition'=>'Monitor','readings'=>['resting_voltage'=>'12.8','loaded_voltage'=>'0','resistance'=>'9.25','charge_percent'=>'0','cycle_count'=>'32'],'notes'=>'Voltage sag observed'];
$data=validateBatteryCheck($entry);check($data['readings']['loaded_voltage']===0.0&&$data['readings']['charge_percent']===0.0,'Keep recorded zeros');check(!array_key_exists('capacity',$data['readings']),'Unmeasured values remain missing');
$note=$entry;$note['readings']=[];check(validateBatteryCheck($note)['readings']===[],'Allow notes without readings');
$empty=$note;$empty['notes']='';rejected(fn()=>validateBatteryCheck($empty),'Reject empty check');
$bad=$entry;$bad['readings']['charge_percent']='101';rejected(fn()=>validateBatteryCheck($bad),'Reject out-of-range percentage');
$bad=$entry;$bad['readings']['cycle_count']='1.5';rejected(fn()=>validateBatteryCheck($bad),'Reject fractional cycle count');
$bad=$entry;$bad['readings']['unsupported']='2';rejected(fn()=>validateBatteryCheck($bad),'Reject unknown metrics');
$bad=$entry;$bad['readings']['resting_voltage']=[];rejected(fn()=>validateBatteryCheck($bad),'Reject nested reading');
$bad=$entry;$bad['condition']='Automatically safe';rejected(fn()=>validateBatteryCheck($bad),'Require explicit known condition');
check(batteryDisplay(0,'V')==='0 V'&&batteryDisplay(null)==='—','Display zero versus unknown');
check(!str_contains(batteryBadge('<script>bad</script>'),'<script>'),'Escape display values');
echo "Passed: battery metadata, dates, readings, zero/missing values, notes-only checks, ranges, malformed input and escaping.\n";

<?php
declare(strict_types=1);
require __DIR__.'/../public/battery-management.php';
function h($v): string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
foreach(['Arizona','USS Arizona','B01 - USS Arizona (BB-39)','Battery 3: Arizona'] as $name)check((batteryShip($name)['key']??'')==='arizona','Match Arizona aliases: '.$name);
check((batteryShip('USS Missouri')['key']??'')==='missouri','Match Missouri');
check((batteryShip('USS New Jersey (BB-62)')['key']??'')==='new-jersey','Keep multiword ship names');
check(batteryShip('B01')===null&&batteryShip('Iowa and Missouri')===null&&batteryShip('George Washington')===null,'Do not guess an unrelated ship');
$catalog=batteryShipCatalog();check(count($catalog)>=25,'Offline ship catalog includes the US WWII fleet');
foreach($catalog as $key=>$ship){$image=base64_decode($ship['image_base64'],true);check($image!==false&&substr($image,0,3)==="\xff\xd8\xff",'Valid local JPEG: '.$key);check($ship['source']!==''&&$ship['license']!==''&&$ship['license_url']!=='','Photo source and license retained: '.$key);}
check(str_contains(batteryShipThumbnail('Arizona'),'p=battery_ship&amp;ship=arizona'),'Use a local image endpoint');
check(str_contains(batteryShipCredit('Arizona'),'Photo source'),'Display source attribution');
check(!str_contains(batteryShipThumbnail('<script>alert(1)</script>'),'<script>'),'Escape unmatched labels');
echo 'Passed: '.count($catalog)." attributed local photos, exact ship matching, prefixes/numbers/hull codes, ambiguous names, and escaped placeholders.\n";

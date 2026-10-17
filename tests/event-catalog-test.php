<?php
// Run with php tests/event-catalog-test.php [regular-page.html offseason-page.html].
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../public/index.php');
$start=strpos($source,'function parseEventCatalog(');
eval(substr($source,$start,strpos($source,'function districts(',$start)-$start));
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$regular='<a href="/2026/MAWOR" title="Event Information">WPI District Event</a><span id="detail2"><br />Worcester, MA USA</span>';
$offseason='<a class="list-group-item" href="/2026/CTSO1"><div><strong>CyberKnight &amp; Friends</strong></div><div class="col-4 d-none d-md-block"><div>High School</div><div class="text-muted">720 Pleasant Street</div><div class="text-muted">Southington, CT USA</div></div><span>Oct 17</span></a><a href="/2026/WEEK0"><strong>Week 0</strong></a><a href="/2026/events/OffSeason">Navigation</a>';
$rows=parseEventCatalog($offseason,2026,true);
check(count($rows)===2,'Parse offseason cards, excluding navigation');
check($rows[0]===['code'=>'CTSO1','name'=>'CyberKnight & Friends','location'=>'Southington, CT USA'],'Decode name and extract city rather than street/date');
check($rows[1]['location']==='','Allow missing location');
check(parseEventCatalog($regular,2026)[0]['code']==='MAWOR','Regular-season format still works');
check(parseEventCatalog($offseason,2025,true)===[],'Reject other years');
check(parseEventCatalog('<h1>Unavailable</h1>',2026,true)===[],'Invalid response produces no rows');
class CatalogResult {function __construct(private array $rows){}function fetchAll(int $mode): array {return $this->rows;}}
$cache=[['year'=>2026,'code'=>'MAWOR','name'=>'WPI District Event','offseason'=>'false']];
function query(string $sql,array $args=[]): CatalogResult {
 global $cache;
 if(str_starts_with($sql,'INSERT')){$cache=array_values(array_filter($cache,fn($r)=>!($r['year']===$args[0]&&$r['code']===$args[1])));$cache[]=['year'=>$args[0],'code'=>$args[1],'name'=>$args[2],'location'=>$args[3],'offseason'=>$args[5]];return new CatalogResult([]);}
 return new CatalogResult(array_values(array_filter($cache,fn($r)=>$r['year']===$args[0]&&$r['offseason']===$args[1])));
}
class CatalogStream {
 public $context;private string $body='';private int $offset=0;public static array $urls=[];public static string $html='';
 function stream_open($path,$mode,$options,&$opened): bool {self::$urls[]=$path;$this->body=self::$html;return true;}
 function stream_read($count): string {$s=substr($this->body,$this->offset,$count);$this->offset+=strlen($s);return $s;}
 function stream_eof(): bool {return $this->offset>=strlen($this->body);}
 function stream_stat(): array {return [];}
}
stream_wrapper_unregister('https');stream_wrapper_register('https',CatalogStream::class);
CatalogStream::$html=$offseason;
check(count(catalog(2026,false,true))===2,'Existing regular cache does not suppress first offseason fetch');
check(CatalogStream::$urls===['https://frc-events.firstinspires.org/2026/events/OffSeason'],'Fetch separate FIRST offseason endpoint');
check(count(catalog(2026))===1,'Offseason rows stay out of regular season');
check(count(catalog(2026,false,true))===2&&count(CatalogStream::$urls)===1,'Reuse offseason cache');
CatalogStream::$html='<h1>Unavailable</h1>';
check(count(catalog(2026,true,true))===2&&!empty($_SESSION['flash']),'Failed refresh preserves cached offseason choices');
if(isset($argv[1],$argv[2])){
 $r=parseEventCatalog(file_get_contents($argv[1]),2026);$o=parseEventCatalog(file_get_contents($argv[2]),2026,true);
 check(count($r)>100&&count($o)>50,'Parse current FIRST pages');
 $byCode=array_column($o,null,'code');
 check(isset($byCode['CTSOU']),'Current list includes CyberKnight Invitational');
 check($byCode['CTSOU']['location']==='Southington, CT USA','Current location markup parses correctly');
 echo 'Live pages: '.count($r).' regular events, '.count($o)." offseason events.\n";
}
echo "Passed: parsing, season separation, first load, cached load, failed refresh.\n";

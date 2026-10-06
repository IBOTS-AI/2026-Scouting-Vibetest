<?php
// Run with php -n tests/database-backup-test.php (no database connection needed).
class PDO {
 const FETCH_ASSOC=2,FETCH_COLUMN=7;
 public array $tables=[];public array $columns=[];public array $saved=[];public bool $transaction=false;public bool $fail=false;
 function query($sql){
  if(str_contains($sql,'information_schema')){ $rows=[];foreach($this->columns as $t=>$cs)foreach($cs as $c=>$type)$rows[]=['table_name'=>$t,'column_name'=>$c,'udt_name'=>$type];return new Result($rows);}
  if(str_contains($sql,'pg_tables'))return new Result(array_keys($this->tables));
  preg_match('/FROM "([^"]+)"/',$sql,$m);return new Result(array_map(fn($row)=>[json_encode($row)],$this->tables[$m[1]]));
 }
 function beginTransaction(){$this->saved=$this->tables;$this->transaction=true;}
 function inTransaction(){return $this->transaction;}
 function commit(){$this->transaction=false;}
 function rollBack(){$this->tables=$this->saved;$this->transaction=false;}
 function exec($sql){if(preg_match('/DELETE FROM "([^"]+)"/',$sql,$m))$this->tables[$m[1]]=[];}
 function prepare($sql){preg_match('/INSERT INTO "([^"]+)"/',$sql,$m);return new Insert($this,$m[1]);}
}
class Result implements IteratorAggregate {function __construct(public array $rows){}function fetchAll($mode){return $this->rows;}function getIterator():Traversable{return new ArrayIterator($this->rows);}}
class Insert {function __construct(public PDO $db,public string $table){}function execute($values){if($this->db->fail&&$this->table==='matches')throw new RuntimeException('Simulated constraint failure');$row=[];foreach($this->db->columns[$this->table] as $column=>$type){$v=array_shift($values);if(in_array($type,['jsonb','json']))$v=$v===null?null:json_decode($v);if($type==='bool')$v=$v==='true';$row[$column]=$v;}$this->db->tables[$this->table][]=(object)$row;}}
require __DIR__.'/../public/database-backup.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new PDO;
foreach(backupTables() as $t){$db->columns[$t]=['id'=>'text'];$db->tables[$t]=[];}
$db->columns['users']=['id'=>'text','role'=>'text','password_hash'=>'text'];$db->tables['users']=[(object)['id'=>'admin','role'=>'admin','password_hash'=>'hash']];
$db->columns['site_settings']=['id'=>'int4','dark_mode'=>'bool','pit_choices'=>'jsonb'];$db->tables['site_settings']=[(object)['id'=>1,'dark_mode'=>true,'pit_choices'=>(object)['tags'=>['Defense']]]];
$db->columns['scouting']=['id'=>'text','data'=>'jsonb'];$db->tables['scouting']=[(object)['id'=>'report','data'=>(object)['defense_rating'=>null,'auto_score'=>0,'path'=>[[1,2],[3,4]]]]];
$db->columns['team_photos']=['id'=>'text','photo_base64'=>'text'];$db->tables['team_photos']=[(object)['id'=>'photo','photo_base64'=>base64_encode('image-bytes')]];
$db->tables['matches']=[(object)['id'=>'match']];
$original=json_encode($db->tables);$json=createDatabaseBackup($db);
check(str_contains($json,"\n    \"format\""),'Readable formatted JSON');
$db->tables['scouting']=[];restoreDatabaseBackup($db,$json);check(json_encode($db->tables)===$original,'Round trip preserves null, zero, bool, nested JSON, photos and users');
foreach(['invalid-json',str_replace('ibots-scouting-database','other-app',$json)] as $bad){try{restoreDatabaseBackup($db,$bad);throw new LogicException('Accepted invalid backup');}catch(JsonException|RuntimeException $e){}check(json_encode($db->tables)===$original,'Invalid backup leaves data unchanged');}
$bad=json_decode($json);unset($bad->tables->users);try{restoreDatabaseBackup($db,json_encode($bad));throw new LogicException('Accepted missing table');}catch(RuntimeException $e){}
$bad=json_decode($json);$bad->tables->users->rows=[];try{restoreDatabaseBackup($db,json_encode($bad));throw new LogicException('Accepted no admin');}catch(RuntimeException $e){}
$db->fail=true;try{restoreDatabaseBackup($db,$json);throw new LogicException('Expected insert failure');}catch(RuntimeException $e){}check(json_encode($db->tables)===$original&&!$db->inTransaction(),'Insert failure rolls back all deletes/inserts');
echo "Passed: readable backup, full round trip, invalid/missing tables, no admin, transactional rollback.\n";

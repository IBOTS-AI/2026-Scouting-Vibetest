<?php
declare(strict_types=1);

// Parent tables precede their dependents; restore never executes SQL from a file.
function backupTables(): array {
 return ['users','events','teams','matches','slots','scouting','pit','audit','picklist','event_catalog','districts','district_events','team_photos','event_field_images','strategy_plans','site_settings'];
}
function backupColumns(PDO $db): array {
 $columns=[];
 foreach($db->query("SELECT table_name,column_name,udt_name FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position")->fetchAll(PDO::FETCH_ASSOC) as $column)$columns[$column['table_name']][$column['column_name']]=$column['udt_name'];
 $tables=$db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
 $expected=backupTables();sort($expected);sort($tables);
 if($tables!==$expected)throw new RuntimeException('Database tables differ from this app version. Backup/restore stopped to avoid omitting data.');
 return $columns;
}
function createDatabaseBackup(PDO $db): string {
 $columns=backupColumns($db);$tables=[];
 $db->beginTransaction();
 try {
  $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
  foreach(backupTables() as $table){
   $rows=[];
   foreach($db->query('SELECT row_to_json(t) FROM "'.$table.'" t') as $row)$rows[]=json_decode($row[0],false,512,JSON_THROW_ON_ERROR);
   $tables[$table]=['columns'=>$columns[$table],'rows'=>$rows];
  }
  $json=json_encode(['format'=>'ibots-scouting-database','version'=>1,'created_at'=>gmdate('c'),'tables'=>$tables],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $db->commit();return $json."\n";
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function validateDatabaseBackup(string $json,array $columns): stdClass {
 $backup=json_decode($json,false,512,JSON_THROW_ON_ERROR);
 if(!$backup instanceof stdClass||($backup->format??null)!=='ibots-scouting-database'||($backup->version??null)!==1||!($backup->tables??null) instanceof stdClass)throw new RuntimeException('Choose a JSON database backup downloaded from this app.');
 $names=array_keys(get_object_vars($backup->tables));$expected=backupTables();sort($names);sort($expected);
 if($names!==$expected)throw new RuntimeException('The backup is missing tables or belongs to a different app version.');
 foreach(backupTables() as $table){
  $data=$backup->tables->$table;
  if(!$data instanceof stdClass||!($data->columns??null) instanceof stdClass||!is_array($data->rows??null))throw new RuntimeException('Invalid backup table: '.$table);
  if(get_object_vars($data->columns)!==$columns[$table])throw new RuntimeException('Database columns differ for '.$table.'. Restore with the same app version that created the backup.');
  foreach($data->rows as $row){
   if(!$row instanceof stdClass||array_keys(get_object_vars($row))!==array_keys($columns[$table]))throw new RuntimeException('Invalid row columns in '.$table);
   foreach($columns[$table] as $column=>$type)if(!in_array($type,['json','jsonb'],true)&&!is_scalar($row->$column)&&$row->$column!==null)throw new RuntimeException('Invalid field in '.$table);
  }
 }
 $admins=array_filter($backup->tables->users->rows,fn($row)=>($row->role??null)==='admin'&&is_string($row->password_hash??null)&&$row->password_hash!=='');
 if(!$admins)throw new RuntimeException('The backup must contain an administrator account.');
 if(count($backup->tables->site_settings->rows)!==1||$backup->tables->site_settings->rows[0]->id!==1)throw new RuntimeException('The backup must contain site settings.');
 return $backup;
}
function restoreDatabaseBackup(PDO $db,string $json): void {
 $columns=backupColumns($db);$backup=validateDatabaseBackup($json,$columns);
 $db->beginTransaction();
 try {
  $db->exec("SET LOCAL lock_timeout='10s'");
  $db->exec('LOCK TABLE '.implode(',',array_map(fn($t)=>'"'.$t.'"',backupTables())).' IN ACCESS EXCLUSIVE MODE');
  foreach(array_reverse(backupTables()) as $table)$db->exec('DELETE FROM "'.$table.'"');
  foreach(backupTables() as $table){
   $names=array_keys($columns[$table]);
   $insert=$db->prepare('INSERT INTO "'.$table.'" ('.implode(',',array_map(fn($c)=>'"'.$c.'"',$names)).') VALUES ('.implode(',',array_fill(0,count($names),'?')).')');
   foreach($backup->tables->$table->rows as $row){
    $values=[];
    foreach($columns[$table] as $column=>$type){$value=$row->$column;if($value!==null&&in_array($type,['json','jsonb'],true))$value=json_encode($value,JSON_THROW_ON_ERROR);elseif(is_bool($value))$value=$value?'true':'false';$values[]=$value;}
    $insert->execute($values);
   }
  }
  $db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}

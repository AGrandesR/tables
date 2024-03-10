<?php
namespace Agrandesr\Database;

use Error;
use Exception;
use PDO;
use PDOException;

class TablesManager {
    public static function __callStatic($method, $arguments) {
        if(!isset($GLOBALS['x-open-source-table-manager'])) $GLOBALS['x-open-source-table-manager'] = new TrueTablesManager();
        if (!method_exists($GLOBALS['x-open-source-table-manager'], $method)) throw new Exception("$method doesn't exist");
        return $GLOBALS['x-open-source-table-manager']->$method(...$arguments);
    }
}

class TrueTablesManager {
    use TablesManagerActions;

    private array $tables=[];
    private array $tasks=[];
    private $error;

    public function setFolder($path) : bool {
        if(is_dir($path)) {
            $dir = opendir($path);
            while(($tableFile = readdir($dir)) !== false) {
                if ($tableFile != "." && $tableFile != "..") {
                    $fileWithPath = $path . DIRECTORY_SEPARATOR . $tableFile;
                    echo $fileWithPath ."\n";

                    if (is_file($fileWithPath)) {
                        // Leer el contenido del file
                        $content = file_get_contents($fileWithPath);
                        $tableName=explode('.',$tableFile)[0]; //All type of extensions will be ignored
                        $this->tables[$tableName] = $this->parsecontent($content);
                    }
                }
            }
            return Count($this->tables)>0 ? true : false;
        }
        return false;
    }

    public function new(string $tableName, string $flag='') {
        return new Table($tableName, $this->tables[$tableName], [], $flag);
    }

    public function save(string $flag='') : bool {
        try {
            foreach ($this->tasks as $task) {
                list($action, $table) = $task;

                if($flag!==$flag) continue;
                
                $id=$table->getId();
                if($action=='upsert' && !is_null($id)) { //UPDATE
                    $this->update($table,$flag);
                } elseif ($action=='upsert') { //INSERT
                    $this->insert($table,$flag);
                } elseif ($action=='delete') { //DELETE
                    $this->delete($table,$flag);
                } else {
                    throw new Exception("Exception: $action");die;
                }
            }
            return PDOhandler::commit($flag) ? true : false;
        } catch(Error | Exception | PDOException $e) {
            PDOhandler::rollback($flag);
            throw $e;            
        }
        return false;
    }
    
    private function newTableField(Table &$table, $fieldName) {
        list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames($table->flag());

        try {
            $uniques=[];
            $foreigns=[];

            $fieldInfo=$table->info();
            $sql="ALTER TABLE ".$table->name()." ADD COLUMN $fieldName " .$fieldInfo[$fieldName]['type'];
            $GLOBALS[$globalHistory][]="*: $sql";
            PDOhandler::execute($sql,[],$table->flag());
        } catch (PDOException $e) {
            $this->errorHandler($e,$table,'newTableField');
        }
    }

    public function get(string $tableName, array $search =[], int $limit=0, string $flag='') {
        try {
            $pdo=PDOhandler::startTransaction($flag, true);
            $tableName=trim($pdo->quote($tableName)," '\"");
            $sql = "SELECT * FROM $tableName";
            $first=true;
            foreach ($search as $key => $value) {
                if($first) $sql.=" WHERE ";
    
                $key=trim($pdo->quote($key)," '\"");
                $value=$pdo->quote($value);
    
                $sql.=" $key = $value ";
            }
    
            $stmt=$pdo->prepare($sql);
            $result = $stmt->execute();
            $pdo->commit();
    
            $dataToReturn=[];
            if($result) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $dataToReturn[]=new Table($tableName,$this->tables[$tableName],$row,$flag);
                }
            }
            return $dataToReturn;
        } catch(PDOException $e) {
            list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames($flag);
            $GLOBALS[$globalHistory][]="*: $sql";
            return [];
        }
    }


    public function addTask(string $action, Table &$table) : int {
        $taskId=Count($this->tasks);
        $this->tasks[$taskId]=[$action, $table];
        return $taskId;
    }

    public function updateTask(int $taskId, string $action, Table &$table) : void {
        $this->tasks[$taskId]=[$action, $table];
    }

    private function parseContent($content) {
        $haveId=false;
        $lines = explode("\n", $content);
        
        
        $fields=[
            'id'=>[
                'type'=>'int',
                'primary'=>true,
                'unique'=>true,
                'increment'=>true
            ]
        ];
        $types = [];

        foreach ($lines as $line) {
            $line=trim($line," \t\n\r");
            //$line = str_replace([' ','\t','\n','\r'],'',$line);
            if($line=='') continue;
            list($rawField, $rawData) = explode('[', $line);
            list($type, $rawData) = explode(']',$rawData);
            if(strpos($rawData,'=')!==false) list($rawData, $relation) = explode('=',$rawData);
            if(strpos($rawData, ':')!==false) $options = explode(':',$rawData);

            $fieldName=trim($rawField);
            if($fieldName=='id') continue;

            $fields[$fieldName]=['type'=>$type];
            if(isset($options)) {
                //$fields[$fieldName]['primary']=in_array('primary',$options);
                $fields[$fieldName]['null']=in_array('null',$options);
                $fields[$fieldName]['unique']=in_array('unique',$options);
                $fields[$fieldName]['binary']=in_array('binary',$options);
                $fields[$fieldName]['unsigned']=in_array('unsigned',$options);
                $fields[$fieldName]['zero']=in_array('zero',$options);
                $fields[$fieldName]['increment']=in_array('increment',$options);
                $fields[$fieldName]['generated(default)']=in_array('generated(default)',$options);
                $fields[$fieldName]['related']=$relation??false;
            }
        }

        return $fields;
    }
}

//php array
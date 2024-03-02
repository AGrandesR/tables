<?php
namespace Agrandesr\Database;

use Error;
use Exception;
use PDO;
use PDOException;

trait TablesManagerActions {
    private function update(Table $table, string $flag='') {
        $sql='UPDATE ' . $table->name() . ' SET ';
        $first=true;
        foreach ($table->data() as $fieldName => $value) {
            if($fieldName=='id') continue;
            if(!$first) $sql.=', ';
            $sql.=" $fieldName = :$fieldName ";
            $first=false;
        }
        $sql .= " WHERE id=:id LIMIT 1;";

        try{
            PDOhandler::exec($sql, $table->data(), $flag);
        } catch(PDOException $e) {
            PDOhandler::lastRollback($flag);
            $this->errorHandler($e,$table,'update');
        }
    }

    private function insert(Table &$table, string $flag='') {
        $valuesList=[];
        $sql='INSERT INTO ' . $table->name();

        foreach ($table->data() as $fieldName => $value) {
            if($fieldName=='id') continue;
            $valuesList[$fieldName]=$value;
        }

        $fieldsString = implode(', ', array_keys($valuesList));
        $valuesString = ':' . implode(',:', array_keys($valuesList)); //Example: ':name, :something, :more'

        $sql .= "($fieldsString) VALUES ($valuesString);";

        try{
            $result=PDOhandler::exec($sql, $valuesList, $flag);
            if(PDOhandler::lastInsertId($flag)) $table->setId(PDOhandler::lastInsertId($flag));
            else {
                list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames($flag);
                $GLOBALS[$globalHistory][]="Something fail assign an id";
            }
        } catch(PDOException $e) {
            PDOhandler::lastRollback($flag);
            $this->errorHandler($e,$table,'insert');
        }
    }

    private function create(Table &$table) {
        list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames($table->flag());

        try {
           // $pdo=PDOhandler::startTransaction($table->flag(), true);
            $uniques=[];
            $foreigns=[];

            $sql="CREATE TABLE ".$table->name()." (";

            foreach ($table->info() as $fieldName => $fieldInfo) {
                $sql.=$fieldName .' '. $fieldInfo['type'];
                $sql.=($fieldInfo['null'] ?? false) ? ' NULL ' : ' NOT NULL ';
                $sql.=($fieldInfo['increment']??false) ? ' AUTO_INCREMENT ' : '';

                if ($fieldInfo['unique']??false) $uniques[]=$fieldName;
                if ($fieldInfo['related']??false) $foreigns[$fieldName] = $fieldInfo['related'];

                // $fields[$fieldName]['binary']=in_array('binary',$options);
                // $fields[$fieldName]['unsigned']=in_array('unsigned',$options);
                // $fields[$fieldName]['zero']=in_array('zero',$options);
                // $fields[$fieldName]['increment']=in_array('increment',$options);
                // $fields[$fieldName]['generated(default)']=in_array('generated(default)',$options);
                $sql.=', ';//TODO
            }
            $sql = substr($sql, 0, -2); //We remove last ','

            $sql.=', PRIMARY KEY (id) ';

            if(!empty($uniques))
                $sql.=', UNIQUE ('.implode(',',$uniques).') ';

            foreach ($foreigns as $fieldName=>$relatedTableInfo) {
                $sql.=" FOREIGN KEY ($fieldName) REFERENCES Usuarios(id) ";
            }
            
            $sql.=");";
            $pdo=PDOhandler::startTransaction($table->flag(), true);
            $GLOBALS[$globalHistory][]="*: $sql";
            $stmt=$pdo->prepare($sql);
            $result = $stmt->execute();
        } catch (PDOException $e) {
            $this->errorHandler($e,$table,'create');
        }
    }

    private function delete(Table &$table, $flag) {
        $sql='DELETE FROM ' . $table->name() . " WHERE id=:id LIMIT 1;";

        try{
            PDOhandler::exec($sql, [
                'id'=>$table->getId(),
            ], $flag);
        } catch(PDOException $e) {
            $this->errorHandler($e,$table,'delete');
        }
    }

    private function errorHandler($error, Table &$table, string $retry) {
        list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames($table->flag());

        $errorCode=$error->getCode();
        $errorMsg=$error->getMessage();

        $GLOBALS[$globalHistory][]="Error[$errorCode]: $errorMsg";

        switch ($errorCode) {
            case '42S02':
                if($retry=='create') return false;
                //Base table or view not found for this reason will create the table
                $this->create($table);
                $GLOBALS[$globalHistory][]="Retry action: $retry";
                
                $this->$retry($table);
                break;
            case '42S22':
                if($retry=='newTableField') return false;
                //Column not found
                preg_match("/Unknown column '(.*?)' in /", $errorMsg, $matches);

                if (!isset($matches[1])) throw $error;
            
                $columnName = $matches[1];

                if(!in_array($columnName,array_keys($table->info()))) return false;
                $this->newTableField($table,$columnName);
                $GLOBALS[$globalHistory][]="Retry action: $retry";
                return $this->$retry($table);
                break;
        }
    }
}
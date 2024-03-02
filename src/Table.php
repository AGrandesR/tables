<?php

namespace Agrandesr\Database;

use Agrandesr\Database\PDOhandler;
use Error;
use Exception;
use PDOException;

class Table {
    private $id; //int null
    private array $fieldsInfo;
    private array $fieldsData;
    private string $tableName;
    private string $flag='';
    private bool $remove=false;
    private int|bool $taskId=false;
    
    public function __construct(string $tableName, array $fieldsInfo, array $fieldsData=[], string $flag='') {
        $this->id=$fieldsData['id']??null;
        $this->fieldsInfo=$fieldsInfo;
        $this->fieldsData=$fieldsData;
        $this->tableName=$tableName;
        $this->flag=$flag;
    }

    public function name() : string {
        return $this->tableName;
    }

    public function data() : array {
        return $this->fieldsData;
    }

    public function flag() : string {
        return $this->flag;
    }

    public function info() : array {
        return $this->fieldsInfo;
    }

    public function __call($method, $arguments) {
        if (strpos($method, 'get') === 0) {
            $fieldName=lcfirst(substr($method, strlen('get')));
            if(in_array($fieldName, array_keys($this->fieldsInfo))) return $this->fieldsData[$fieldName]??null;
        } elseif (strpos($method, 'set') === 0) {
            $fieldName=lcfirst(substr($method, strlen('set')));
            if(in_array($fieldName, array_keys($this->fieldsInfo))) $this->fieldsData[$fieldName]=$arguments[0];
            elseif(($arguments[1]??true)==true) throw new Exception("$method doesn't exist", 1);
        }
    }

    public function persist() {
        if($this->remove) return false;
        $this->saveTask('upsert');
        return true;
    }

    public function delete() {
        $this->saveTask('delete');
        return true;
    }

    private function saveTask(string $taskName) {
        if($this->taskId!=false) TablesManager::updateTask($this->taskId,  $taskName, $this);
        else $this->taskId=TablesManager::addTask($taskName,$this);
    }
}
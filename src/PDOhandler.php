<?php
namespace Agrandesr\Database;

use Error;
use Exception;
use PDO;
use PDOException;

class PDOhandler {    
    static function exec(string $sql,array $data=[], string $flag='', string $action='') {
        list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);

        self::startTransaction($flag);

        $stmt = $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]->prepare($sql);
        
        // Asignar valores a las sentencias preparadas
        $sqlLog=$sql;
        foreach ($data as $key => $value) {
            $trueKey=':'.trim($key,':');
            $sqlLog=str_replace($trueKey, $value, $sqlLog);
        }

        $GLOBALS[$globalHistory][]=$GLOBALS[$globalActive] .": ". $sqlLog;

        $stmt->execute($data);

        if($action!=='') $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]=$action;

        return $stmt;
    }

    static function commit($flag='') {
        list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);
        foreach ($GLOBALS[$globalConnections] as &$globalConnection) {
            # code...
            if(isset($globalConnection)) {
                if($globalConnection->inTransaction())
                    $globalConnection->commit();
                else  $GLOBALS[$globalHistory][]='ERROR NOT CATCHED';
            }
        }
        if(isset($GLOBALS[$globalHistory])) $GLOBALS[$globalHistory][]='GLOBAL PDO COMMIT';
    }

    static function lastRollback($flag) {
        list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);
        $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]->rollback();
        unset($GLOBALS[$globalConnections][$GLOBALS[$globalActive]]);
        unset($GLOBALS[$globalActive]);
    }
    static function rollback($flag=false) {
        list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);
        $strings=[];
        
        if(isset($GLOBALS[$globalConnections]) &&  is_array($GLOBALS[$globalConnections])) {
            $reverse=array_reverse($GLOBALS[$globalConnections],true);
            foreach ($reverse as $key => &$pdo) {
                if (is_string($pdo)){
                    $strings[]=$pdo;
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollback();
                    $GLOBALS[$globalHistory][]="ROLLBACK $key";
                } else {
                    $GLOBALS[$globalHistory][]="ROLLBACK $key fail";
                }
            }
        }

        foreach ($strings as $pdo) {
            list($action, $target) = explode(':',$pdo);
            switch ($action) {
                case 'create':
                    $pdo=self::startTransaction($flag, true);
                    $sql="SELECT * FROM $target LIMIT 1";
                    $stmt=$pdo->prepare($sql);
                    $result = $stmt->execute();
                    if($result && $result->num_rows <= 0) {
                        //We can revert
                        $stmt=$pdo->prepare($sql);

                        $sql = "DROP TABLE $target";

                        $pdo=self::startTransaction($flag, true);
                        $stmt=$pdo->prepare($sql);

                        $stmt->execute();
                        $GLOBALS[$globalHistory][]="ROLLBACK: $sql";
                    } else {
                        $GLOBALS[$globalHistory][]="ROLLBACK FAIL - TABLE WITH CONTENT";
                    }
            }
        }
        
        return false;
    }

    static function lastInsertId(string $flag='') {
        list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);

        if(isset($GLOBALS[$globalConnections][$GLOBALS[$globalActive]]))
            return $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]->lastInsertId();
        return false;
    }

    static function findSubstring($string, $substring) {
        //This string is to return User o user from SQL sentence
        $pos = stripos($string, $substring);
        if ($pos !== false) {
            return substr($string, $pos, strlen($substring));
        } else {
            return $substring;
        }
    }

    static function startTransaction($flag, $return=false) {
        list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);

        $flag = (empty($flag))? 'DB_': 'DB_'.$flag.'_';
        $type=$_ENV[$flag . 'TYPE'];
        $host=$_ENV[$flag . 'HOST'];
        $user=$_ENV[$flag . 'USER'];
        $pass=$_ENV[$flag . 'PASS'];
        $dtbs=$_ENV[$flag . 'DTBS'];
        $port=$_ENV[$flag . 'PORT'];
        $char=isset($_ENV[$flag . 'CHAR']) ? $_ENV[$flag . 'CHAR'] : 'UTF8';
        $dsn = "$type:host=$host;port=$port;dbname=$dtbs;charset=$char";
        
        if(!isset($GLOBALS[$globalConnections])) $GLOBALS[$globalConnections]=[];
        if(!$return) $GLOBALS[$globalActive] = Count($GLOBALS[$globalConnections]);
        //if(!$return) unset($GLOBALS[$globalConnections][$GLOBALS[$globalActive]]);

        if($return) $pdo=new PDO($dsn, $user, $pass);
        else $GLOBALS[$globalConnections][$GLOBALS[$globalActive]] = new PDO($dsn, $user, $pass);

        if($return) $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        else $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if($return) $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
        else $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]->setAttribute(PDO::ATTR_AUTOCOMMIT, false);

        if($return) $pdo->beginTransaction();
        else $GLOBALS[$globalConnections][$GLOBALS[$globalActive]]->beginTransaction();

        if(!isset($GLOBALS[$globalHistory])) $GLOBALS[$globalHistory]=[];

        if(!$return) $GLOBALS[$globalHistory][]='STARTED TRANSACTION ' . $GLOBALS[$globalActive];

        return $return ? $pdo : $GLOBALS[$globalConnections][$GLOBALS[$globalActive]];
    }
    static function execute($sql,$data,$flag='') {
        //list($globalConnections, $globalHistory, $globalActive) = self::getGlobalNames($flag);

        $flag = (empty($flag))? 'DB_': 'DB_'.$flag.'_';
        $type=$_ENV[$flag . 'TYPE'];
        $host=$_ENV[$flag . 'HOST'];
        $user=$_ENV[$flag . 'USER'];
        $pass=$_ENV[$flag . 'PASS'];
        $dtbs=$_ENV[$flag . 'DTBS'];
        $port=$_ENV[$flag . 'PORT'];
        $char=isset($_ENV[$flag . 'CHAR']) ? $_ENV[$flag . 'CHAR'] : 'UTF8';
        $dsn = "$type:host=$host;port=$port;dbname=$dtbs;charset=$char";

        $pdo=new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($data);
    }

    static function getGlobalNames(string $flag='') {
        $globalConnections='x-open-source-mysql-connections' . ($flag==''?'':"-$flag");
        $globalHistory=$globalConnections . '-history';
        $globalActive=$globalConnections . '-active';
        return [
            $globalConnections,
            $globalHistory,
            $globalActive
        ];
    }
}
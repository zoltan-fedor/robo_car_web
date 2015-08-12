<?php
include('../../settings.ini'); # get Oracle credentials
include('db_oracle.class.php'); # load the Oracle class


// create the Oracle connection object
$db['conn1'] = new Db(
    $oracle_login,
    $oracle_psw,
    $oracle_host,
    'UTF8',
    'Robo-car Client',
    "Robo-car app", // sub-application or module name
    "Zoltan, Gurshish, Charlie" // user name
);


# change date format
$sql = "ALTER SESSION SET NLS_DATE_FORMAT = 'RRRR-MM-DD HH24:MI:SS'";
$res = $db['conn1']->execute($sql, "Set date format for the session");

# test selct
$sql = "SELECT * FROM U8006365.TEST2";
$res = $db['conn1']->execFetchAll($sql, "Get data");
var_dump($res);
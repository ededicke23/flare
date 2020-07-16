<?php

/*
This is the main Flare configuration file. Please do not change anything in this unless you know what you are
doing! If updating, please backup this file prior to doing so.
*/

session_start();

spl_autoload_register(function($class) {

    require_once 'classes/'.$class.'.php';

});

$GLOBALS['config'] = array(
    'mysql' => array(
        'host' => 'DB_HOST',
        'username' => 'DB_USER',
        'password' => 'DB_PASS',
        'db' => 'DB_NAME'
        ),
    'remember' => array(
        'cookie_name' => 'remember',
        'cookie_expiry' => 604800
    ),
    'session' => array(
        'session_name' => 'user',
        'token_name' => 'token'
    ),
    'va' => array(
        'name' => 'VA_NAME',
        'identifier' => 'VA_IDENTIFIER'
    ),
    'vanet' => array(
        'api_key' => 'VANET_API_KEY'
    ),
    'defaults' => array(
        'permissions' => '{"admin": 0, "usermanage": 0, "staffmanage": 0, "recruitment": 0, "pirepmanage": 0, "newsmanage":0, "emailpilots": 0, "opsmanage": 0, "statsviewing": 0}',
        'fulladminpermissions' => '{"admin": 1, "usermanage": 1, "staffmanage": 1, "recruitment": 1, "pirepmanage": 1, "newsmanage": 1, "emailpilots": 1, "opsmanage": 1, "statsviewing": 1}'
    )
);

if (Cookie::exists(Config::get('remember/cookie_name')) && !Session::exists(Config::get('session/session_name'))) {
    $hash = Cookie::get(Config::get('remember/cookie_name'));
    $hashCheck = DB::getInstance()->get('sessions', array('hash', '=', $hash));

    if ($hashCheck->count()) {
        $user = new User($hashCheck->first()->user_id);
        $user->login();
    }
}
<?php

$module = $Params['Module'];

if(!array_key_exists( 'REGIONCHECKED', $_COOKIE )) {
    //Check for session variable
    //print_r( eZSession::get('REGIONWARNING') );
    if(eZSession::issetkey('REGIONWARNING') === true) {
        $redirectURL = eZSession::get('SYSTEMIDENTIFIEDURL');
        $usURL = eZSession::get('USURL');
        $result = array(
            'redirectto' => $redirectURL            
        );
        $resultJson = json_encode($result);
	header('Content-Type: application/json');
        print_r($resultJson);
    }
    setcookie('REGIONCHECKED', 'TRUE', time()+3600*24*365 , '/' );
}
eZExecution::cleanExit();

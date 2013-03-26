<?php

//connect to gmail
require_once('Zend/Mail/Storage/Pop3.php');
$mail = new Zend_Mail_Storage_Pop3(array('host'     => 'pop.gmail.com',
                                   'port'     => 995,
                                   'user'     => 'timetrex@DOMAINNAME.com',
                                   'password' => 'PASSWORD',
                                   'ssl'      => 'SSL'));





echo $mail->countMessages() . " messages found\n";
if ($mail->countMessages() > 0) {
    $message = $mail->getMessage(1);
	//check username
    if (preg_match("<([A-Za-z0-9]+?)@DOMAINNAME.com>",$message->from,$parsedusername) == 1) {

        //connect to timetrex
        require_once('../../classes/modules/api/client/TimeTrexClientAPI.class.php');
        $TIMETREX_URL = 'http://10.183.0.99:8085/api/soap/api.php';
        $TIMETREX_USERNAME = 'admin';
        $TIMETREX_PASSWORD = 'PASSWORD';


        $emailbody = "";

        $api_session = new TimeTrexClientAPI();
        if ($api_session == false) {
            $emailbody .= "TimeTrexClientAPI error\n";
        }
        $api_session->Login( $TIMETREX_USERNAME, $TIMETREX_PASSWORD );
        if ($api_session  == false) {
            $emailbody .= "Login error\n";
        }
        $auth_obj = new TimeTrexClientAPI( 'Authentication' );
        if ($auth_obj  == false) {
            $emailbody .= "auth_obj error\n";
        }
        $user_obj = new TimeTrexClientAPI( 'User' );
        if ($user_obj  == false) {
            $emailbody .= "user_obj error\n";
        }
        $getUser = $user_obj->getUser(array('filter_data' => array('user_name' => $parsedusername[1])));
        if ($getUser  == false) {
            $emailbody .= "getUser error\n";
        }
        $getUser_result = $getUser->getResult();
        if ($getUser_result  == false) {
            $emailbody .= "getUser_result error\n";
        }
        $auth_obj->switchUser( $parsedusername[1]);
        if ($auth_obj  == false) {
            $emailbody .= "switchUser error\n";
        }
        $punch_obj = new TimeTrexClientAPI( 'Punch' );
        if ($punch_obj  == false) {
            $emailbody .= "punch_obj error\n";
        }
        $result = $punch_obj->getUserPunch();
        if ($result  == false) {
            $emailbody .= "getUserPunch error\n";
        }
        $punch_data = $result->getResult();
        if ($punch_data  == false) {
            $emailbody .= "punch_data error\n";
        }

        //set time_stamp to phone time
        $punch_data['punch_time']=date("j-M-y g:i A",strtotime($message->date));





        if (stripos($message->subject,"In")) {
            $punch_data['status_id']=10;
            $emailcommand =  "In";
        } else if (stripos($message->subject,"Out")) {
            $punch_data['status_id']=20;
            $emailcommand =  "Out";
        } else {
            $emailcommand = "Auto";
        }

        if (stripos($message->subject,"Break")) {
            $punch_data['type_id']=30;
            $emailcommand .=  " Break";
        } else if (stripos($message->subject,"Lunch")) {
            $punch_data['type_id']=20;
            $emailcommand .=  " Lunch";
        } else {
            $emailcommand .=  " Normal";
        }

        $emailcommand .= " Punch\n";

        if (stripos($message->subject,"Report") === false) {
            $punch_data_result = $punch_obj->setUserPunch($punch_data);
            if ($punch_data_result  == false) {
                $emailbody .= "punch_data_result error\n";
            }
        } else {
            $emailcommand =  "Report Only\n";
        }

        $api_session->Logout();

        $mail->removeMessage(1);

		//Generate the punch report
		//log back in as admin


		//get report
        $emailbody .=  "____________________\n\n";
        $emailbody .=  "Punch Summary Report\n";
        $emailbody .=  "____________________\n\n";

        $api_session->Login( $TIMETREX_USERNAME, $TIMETREX_PASSWORD );
        $report_obj = new TimeTrexClientAPI( 'PunchSummaryReport' );
        $config = $report_obj->getTemplate('by_employee+punch_summary+total_time')->getResult();
		//adjust template
        $config['-1010-time_period']['time_period'] = 'this_pay_period';
        $config['employee_number'] = $getUser_result[0]['employee_number'];

		//let the server generate the report
        $result = $report_obj->getPunchSummaryReport($config , 'csv' );
        $result = $result->getResult();
        $input = base64_decode($result['data']);
        $input = str_replace("\"", "", $input);
        $csvData = explode( "\n", $input);
        foreach ($csvData as &$value) {
            $value = explode(',', $value);
        }


        $total = 0;  // tally of total hours worked

		//print table headers
        $emailbody .=  "#{$getUser_result[0]['employee_number']}: {$getUser_result[0]['last_name']}, {$getUser_result[0]['first_name']}\n\n";

		//echo each line of data
        foreach ($csvData as &$value) {
            if (isset($value[2]) && isset($value[3]) &&isset($value[4]) && isset($value[5]) && isset($value[6])) {
                $emailbody .=  str_pad ($value[2], 10);
                $emailbody .=  str_pad ($value[3], 20);
                $emailbody .=  str_pad ($value[4], 10);
                $emailbody .=  str_pad ($value[5], 20);
                if (is_numeric($value[6]) ) {
                    $total = $total + (float)$value[6];
                    if ((float)$value[6] != 0 ) {
                        $emailbody .= sprintf( "% 6.3f", $value[6]);
                    }
                } else {
                    $emailbody .=  str_pad($value[6], 10);
                }
                $emailbody .=  "\n";
            }
        }
		//print total hours worked
        $emailbody .=  str_pad (" ", 60);
        $emailbody .=  sprintf( "% 6.3f",  $total);



        //send reply message
        require_once('Zend/Mail.php');
        require_once('Zend/Mail/Transport/Smtp.php');

        $send_to_name = $getUser_result[0]['first_name'] . " " . $getUser_result[0]['last_name'];
        $send_to_email = $parsedusername[1] . '@DOMAINNAME.com';

        //SMTP server configuration

        $transport = new Zend_Mail_Transport_Smtp('smtp.gmail.com', array('auth' => 'login',
                'ssl' => 'ssl',
                'port' => '465',
                'username' => 'timetrex@DOMAINNAME.com',
                'password' => 'PASSWORD'
                                                                         ));
        $mail = new Zend_Mail();
        $mail->setFrom('timetrex@DOMAINNAME.com', 'Timetrex');
        $mail->addTo($send_to_email, $send_to_name);
        $mail->setSubject($emailcommand);
        $mail->setBodyHtml("<pre>" . $emailbody . "</pre>");
        $mail->send($transport);
    }
}

?>
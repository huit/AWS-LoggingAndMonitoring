<?php

// aws_sns_receiver.php
// 
// by Stefan Wuensch 2014 - 2017
// 
// This script is a webhook to receive AWS CloudWatch Alarm messages via SNS and
// process the Message details into a Nagios Passive Check results command.
// This script should be on a public-facing (AWS SNS accessible) web server
// which can also access the Nagios Command Pipe. See links to details / docs 
// inline in this script.
// 
// Note that the CloudWatch Alarm Name, Trigger, MetricName, MetricName, etc.
// are used to construct the Nagios Host name and Service Name.
// To see how the CloudWatch Alarms are used to generate the Nagios config file(s) see
// https://bitbucket.org/huitcloudservices/cloud_monitoring_services/src/master/Code-to-connect-AWS-and-Nagios/Nagios-config-from-alarms.php
// 
// This is an example of a real-world incoming SNS message JSON from a CloudWatch Alarm:
// {
//     "Message": "{\"AlarmName\":\"calexport.fas.harvard.edu prod elb-request-count-high-cw-alarm\",\"AlarmDescription\":\"Alarm if admints-c-LoadBala-18FMEMSAACK1M ELB request count is GreaterThanThreshold 500 for 10 period(s) of 60 seconds\",\"AWSAccountId\":\"949726781110\",\"NewStateValue\":\"OK\",\"NewStateReason\":\"Threshold Crossed: 1 datapoint (1.0) was not greater than the threshold (500.0).\",\"StateChangeTime\":\"2017-04-19T18:04:56.778+0000\",\"Region\":\"US East - N. Virginia\",\"OldStateValue\":\"INSUFFICIENT_DATA\",\"Trigger\":{\"MetricName\":\"RequestCount\",\"Namespace\":\"AWS/ELB\",\"StatisticType\":\"Statistic\",\"Statistic\":\"SUM\",\"Unit\":null,\"Dimensions\":[{\"name\":\"LoadBalancerName\",\"value\":\"admints-c-LoadBala-18FMEMSAACK1M\"}],\"Period\":60,\"EvaluationPeriods\":10,\"ComparisonOperator\":\"GreaterThanThreshold\",\"Threshold\":500.0,\"TreatMissingData\":\"\",\"EvaluateLowSampleCountPercentile\":\"\"}}",
//     "MessageId": "8fdf384c-4e26-53f7-a06f-693de72e8862",
//     "Signature": "QU380gd5p2P6JZZq6rDm0jen39aBNTjlwpNhSsTDSVWEyEbSFzZCcRvIo1T7vo6+EX5rIb7yRzf6J6/jl2k9LTwpSB48UE7Rn+2KWm4Cbxld7PWG41lN6zINaGbTYUN+SLfUkgxpGYE1JURBVpJnueL/Ubyl3atzna3RGhssUBv6RQ5lEKmfsEFgBc6qw/jjAzUtuerh/fuoa7ItGqs7hbki5ke4BQnGwhKaPsnb3Z45QV2qYRT/ee2qFP7A/ffQItU+4awf/FqhFcuxsp/i7VUC7CHp5s2PgyXOz/+7B+NKd97ZS/h/+I+Wi1mTYHHjswZ5dNrXN8+6BwZfwmdeBw==",
//     "SignatureVersion": "1",
//     "SigningCertURL": "https://sns.us-east-1.amazonaws.com/SimpleNotificationService-b95095beb82e8f6a046b3aafc7f4149a.pem",
//     "Subject": "OK: \"calexport.fas.harvard.edu prod elb-request-count-high-cw-alarm\" in US East - N. Virginia",
//     "Timestamp": "2017-04-19T18:04:56.805Z",
//     "TopicArn": "arn:aws:sns:us-east-1:949726781110:HUIT_Nagios",
//     "Type": "Notification",
//     "UnsubscribeURL": "https://sns.us-east-1.amazonaws.com/?Action=Unsubscribe&SubscriptionArn=arn:aws:sns:us-east-1:949726781110:HUIT_Nagios:556a091d-c9d4-4e15-961d-cc2e84555ffe"
// }
// 
// 
// THIS WORKS!!! (as of 2014-06-27 11:59 AM) -- Stefan W.
//
// Note: This uses a cert that is downloaded on-the-fly to validate messages.
//
// Details: http://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.html
// 
// 
// 
// Changes:
// 
// 2014-07-26: palantir.fas can't "verifyCertificate" because of an old SSL version.
// However, palantir2.unix.fas (RHEL 6) can "verifyCertificate" just fine!
//
// 2014-10-31: Added special handling of 'INSUFFICIENT_DATA' for ELB 4XX & 5XX HTTP counts.
// The 'INSUFFICIENT_DATA' state is really a Nagios OK because it means there's been NO errors at all,
// and the Cloudwatch ELB 'OK' is really a Nagios Warning because it means there's been SOME errors
// but just not enough to trigger the 'ALARM' condition.
// 
// 2017-01-11: Added handling of incoming SNS messages that are NOT from CloudWatch Alarms.
// This new type of message has "detail-type": "Scheduled Event" which indicates it's coming
// from CloudWatch Events. There is now an AWS Event Rule which fires periodically to generate a
// test SNS message to this webhook script. This will be logged to a specific output file, different
// from the general log file. The output file for the CloudWatch Events SNS messages is monitored 
// by Nagios to make sure it's being updated. If SNS messages fail to be delivered, that Nagios 
// check will notify of the issue. See "AWS incoming SNS messages log: nagios-master-server" on
// https://nagios.huit.harvard.edu/nagios/cgi-bin/status.cgi?host=nagios-master-server
// 
// 2017-04-17: Added retries of fopen() in validateCertificate() in case we don't get the
// signing cert on the first attempt.
// 
// 2017-04-19: Added HTTP error codes in header for all non-OK exist states. This forces SNS to retry.
// Implement regex for allowed Topic. Add lots of comments! Construct log file name from script name.
// Clean up code formatting; clean up log output. Fix undfined variable bugs which spewed PHP Notice
// messages into Apache log. Remove JSON echo back to sender... now just "Message processed successfully."
// 
// 2017-04-21: Make the main log file optional, throwing an error to Apache if it can't be opened.
// Include the non-OK HTTP status in the STDOUT as well as the header. Log & handle an error if the
// Alarm Name doesn't have at least one space like it supposed to have. Include output to the
// file being monitored by Nagios for an Alarm notification, in addition to the Scheduled Event type.
// 




// -----------------------------------------------------------------------------------------------------------
//////
//// CONFIGURATION
//////

// PHP standard error handling
error_reporting( E_ALL );
ini_set( 'display_errors', false );	// For Production use, this should be false so that bugs are not exposed.
ini_set( 'html_errors', false );	// This should always be false because we're not dealing with HTML at all.

// Set date basics
date_default_timezone_set('America/New_York');
$dateString = date("Y-m-d");

// Custom debugging options
$writeToNagios = true;		// Unless this is 'true' (boolean not string!) the Nagios command will not actually be processed.
$logToFile = true;		// Write out what's happening. Highly recommended to be enabled even in Prod.

// Optional - validate the SNS ARN that's sending the message.
$restrictByTopic = true;
$allowedTopicRegex = "arn:aws:sns:.*nagios";

// We can optionally restrict the sending domain to a known source.
$verifysourceDomain = true;
$allowedSourceDomain = "sns.us-east-1.amazonaws.com";

//For security you can (should) validate the certificate, this does add an additional time demand on the system.
//NOTE: This also checks the origin of the certificate to ensure messages are signed by the AWS SNS SERVICE.
//Since the allowed topicArn is part of the validation data, this ensures that your request originated from
//the service, not somewhere else, and is from the topic you think it is, not something spoofed.
$verifyCertificate = true;


// This is the Nagios command pipe which gets the Passive Check command.
// https://assets.nagios.com/downloads/nagioscore/docs/nagioscore/4/en/passivechecks.html
$commandPipePath = "/usr/local/nagios/var/rw/nagios.cmd" ;


// This is the main log / output file for the actions of this script.
// Example: /var/tmp/sns/2017-04-19_aws_sns_receiver.php.log
$logFile = "/var/tmp/sns/" . $dateString . "_" . basename( __FILE__ ) . ".log";


// As of 2017-04-18 this $monitoringFile is being watched for size and age via this Service check:
// https://nagios.huit.harvard.edu/nagios/cgi-bin/extinfo.cgi?type=2&host=nagios-master-server&service=AWS+incoming+SNS+messages+log%3A+nagios-master-server
// with the check_command defined as: 		check_file!/var/tmp/sns/$DATE$_SNS-incoming-check.txt!-w 1800 -c 3600 -W 1
// ...and command_line: 			check_file_age -f $ARG1$ $ARG2$
// This allows us to monitor whether the receipt and processing of incoming SNS messages is actually working!
$monitoringFile = "/var/tmp/sns/" . $dateString . "_SNS-incoming-check.txt";


// These are the non-OK HTTP status codes sent in cases of errors
// https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
$HEADER405 = "HTTP/1.1 405 Method Not Allowed" ;	// 405 if there's no POST data
$HEADER503 = "HTTP/1.1 503 Service Unavailable" ;	// 503 is our best bet for an application / logic error



// -----------------------------------------------------------------------------------------------------------
//////
//// OPERATION
//////

$signatureValid = false;
$safeToProcess = true; //Are Security Criteria Set Above Met? Changed programmatically to false on any security failure.

if ( $logToFile ) {
	$logFH = fopen( $logFile, 'a' ) ;

	if ( $logFH ) {		// Obviously we can't write to the log file unless we successfully opened the file!
		fwrite( $logFH, "==============================================================================================================\n" ) ;
		fwrite( $logFH, __FILE__ . " " . date( "Y-m-d H:i:s" ) . "\n\n" ) ;
		fwrite( $logFH, "Environment REMOTE_ADDR: "     . getenv( 'REMOTE_ADDR' )     . "\n" ) ;
		fwrite( $logFH, "Environment HTTP_USER_AGENT: " . getenv( 'HTTP_USER_AGENT' ) . "\n\n" ) ;

	} else {		// If we couldn't open the log file, throw an error out to the Apache error log and continue.
		error_log( __FILE__ . " Error: \"$logFile\" could not be opened!! Continuing without it." ) ;
		$logToFile = false ;
	}
}


//Get the raw post data from the request. This is the best-practice method as it does not rely on special php.ini directives
//like $HTTP_RAW_POST_DATA. Amazon SNS sends a JSON object as part of the raw post body.
$json = json_decode( file_get_contents( "php://input" ) );


// Make sure we got something, and make sure the thing is JSON.
// If the POST data isn't JSON, the json_decode() above won't give us anything.
if ( is_null( $json ) || $json == "" ) {

	$safeToProcess = false;		// We are going to bail out anyway, but we'll set this just in case
	header( $HEADER405 );
	echo $HEADER405 . "\n" ;	// This goes out to the HTTP client / agent.
	if ( $logToFile ) {
		error_log( __FILE__ . " Error: No JSON POST data - exiting" ) ;
		fwrite( $logFH, "Error: No JSON POST data - exiting\n" ) ;
		fclose( $logFH ) ;
	}
	exit( 1 ) ;

}

// Check for a "Type" object in the JSON. Without it, the SNS message is not
// valid! The "Type" has to be either "SubscriptionConfirmation" or "Notification".
// http://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.html
if ( ! isset( $json->Type ) ) {
	$safeToProcess = false;
	header( $HEADER503 );
	echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
	if ( $logToFile ) {
		fwrite( $logFH, "Error: No \"Type\" object in JSON\n\n" );
	}
}

//Check for Restrict By Topic
if ( $restrictByTopic && $safeToProcess ) {
	if ( ! isset( $json->TopicArn ) || ! preg_match( "/$allowedTopicRegex/i", $json->TopicArn ) ) {
		$safeToProcess = false;
		header( $HEADER503 );
		echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
		if ( $logToFile ) {
			$TopicArn = ( isset( $json->TopicArn ) ? $json->TopicArn : "(null)" ) ;
			fwrite( $logFH, "ERROR: Allowed Topic ARN RegEx: \"" . $allowedTopicRegex . "\" DOES NOT MATCH Calling Topic ARN: \"" . $TopicArn . "\"\n\n" );
		}
	}
}


// Check for and Verify Certificate
if ( $verifyCertificate && $safeToProcess ) {

	// If there's no way to get the signing cert, and we're supposed to validate the message cert, that's all folks.
	if ( ! isset( $json->SigningCertURL ) ) {
		$safeToProcess = false;
		header( $HEADER503 );
		echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
		if ( $logToFile ) {
			fwrite( $logFH, "Error: No \"SigningCertURL\" object in JSON\n\n" );
		}
	}

	if ( $verifysourceDomain && $safeToProcess ) {
		// Check For Certificate Source
		$domainFromUrl = getDomainFromUrl( $json->SigningCertURL );
		if ( $domainFromUrl != $allowedSourceDomain ) {
			$safeToProcess = false;
			header( $HEADER503 );
			echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
			if ( $logToFile ) {
				fwrite( $logFH, "Error: Key domain \"" . $domainFromUrl . "\" is not equal to allowed source domain \"" . $allowedSourceDomain. "\"\n\n" );
			}
		}
	}


	// Build Up The String That Was Originally Encoded With The AWS Key So You Can Validate It Against Its Signature.
	// http://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html
	// Checking $safeToProcess here should eliminate "Undefined property" PHP Notice messages.
	$validationString = "";
	if ( $json->Type == "SubscriptionConfirmation" && $safeToProcess ) {
		$validationString .= "Message\n";
		$validationString .= $json->Message . "\n";
		$validationString .= "MessageId\n";
		$validationString .= $json->MessageId . "\n";
		$validationString .= "SubscribeURL\n";
		$validationString .= $json->SubscribeURL . "\n";
		$validationString .= "Timestamp\n";
		$validationString .= $json->Timestamp . "\n";
		$validationString .= "Token\n";
		$validationString .= $json->Token . "\n";
		$validationString .= "TopicArn\n";
		$validationString .= $json->TopicArn . "\n";
		$validationString .= "Type\n";
		$validationString .= $json->Type . "\n";
	} elseif ( $safeToProcess ) {		// This $safeToProcess test is just to prevent "Undefined property" PHP Notice messages
		$validationString .= "Message\n";
		$validationString .= $json->Message . "\n";
		$validationString .= "MessageId\n";
		$validationString .= $json->MessageId . "\n";
		if ( property_exists( $json, "Subject" ) && $json->Subject != "" ){
			$validationString .= "Subject\n";
			$validationString .= $json->Subject . "\n";
		}
		$validationString .= "Timestamp\n";
		$validationString .= $json->Timestamp . "\n";
		$validationString .= "TopicArn\n";
		$validationString .= $json->TopicArn . "\n";
		$validationString .= "Type\n";
		$validationString .= $json->Type . "\n";
	}

	if ( $logToFile ) {
		fwrite( $logFH, "Data Validation String:\n" );
		fwrite( $logFH, $validationString . "\n\n" );
	}

	$signatureValid = validateCertificate( $json->SigningCertURL, $json->Signature, $validationString );

	if ( ! $signatureValid ) {
		$safeToProcess = false;
		header( $HEADER503 );
		echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
		if ( $logToFile ) {
			fwrite( $logFH, "ERROR: Data and Signature do not match Certificate, or Certificate Error.\n\n" );
		}
	} else {
		if ( $logToFile ) {
			fwrite ( $logFH, "Data Validated Against Certificate.\n\n" );
		}
	}
}

if ( $safeToProcess ) {

	//Handle A Subscription Request Programmatically
	if ( $json->Type == "SubscriptionConfirmation" ){
		//RESPOND TO SUBSCRIPTION NOTIFICATION BY CALLING THE URL

		if ( $logToFile ) {
			fwrite( $logFH, "Type == SubscriptionConfirmation\n" );
			fwrite( $logFH, $json->SubscribeURL . "\n\n" );
		}

		$curl_handle=curl_init();
		curl_setopt( $curl_handle, CURLOPT_URL, $json->SubscribeURL );
		curl_setopt( $curl_handle, CURLOPT_CONNECTTIMEOUT, 2 );
		curl_exec( $curl_handle );
		curl_close( $curl_handle );	
	}


	//Handle a Notification Programmatically
	if ( $json->Type == "Notification" ){

		$monitoringFH = fopen( $monitoringFile, 'a' ) ;
		// Note: If we can't open the monitoring file, that's not fatal. We will still try and 
		// process the incoming message. No HTTP non-OK error header here.
		if ( ! $monitoringFH ) {
			if ( $logToFile ) {
				fwrite( $logFH, "Error: Output file for monitoring " . $monitoringFile . " cannot be opened!!!\n" ) ;
			}
		}

		if ( ! property_exists( $json, "Subject" ) ) {
			$json->Subject = "undefined Subject" ;	// If it's not there, create it with a place-holder value.
		}
		
		if ( $logToFile ) {
			fwrite ( $logFH, "Subject: " . $json->Subject . "\n" );
			fwrite ( $logFH, "Message: " . $json->Message . "\n\n" );
		}

		// This is the good stuff!!! (Everything in the JSON other than "Message" is just SNS meta-data.)
		$messageJSON = json_decode( $json->Message ) ;


		// If it's a delivery test SNS message ("Scheduled Event" type), then handle that in a different way.
		// This section will be run if it's a SNS delivery validation / test message coming from 
		// a CloudWatch Event Rule. See https://console.aws.amazon.com/cloudwatch/home?region=us-east-1#rules:
		// for the account "admints" or "admintsdev".
		if ( property_exists( $messageJSON, "detail-type" ) && $messageJSON->{'detail-type'} == "Scheduled Event" ) {
			if ( property_exists( $messageJSON, "resources" ) && isset( $messageJSON->resources[ 0 ] ) ) {
				$resources0 = $messageJSON->resources[ 0 ] ;
			} else {
				$resources0 = "(no resources[] object)" ;
			}
			if ( $monitoringFH ) {
				fwrite( $monitoringFH, join( ',', array( date( "U" ), $resources0, $messageJSON->{'time'}, $messageJSON->{'detail-type'}, __FILE__, getenv( 'REMOTE_ADDR' ) ) ) . "\n" ) ;
				fclose( $monitoringFH ) ;
			}
			if ( $logToFile ) {
				fwrite( $logFH, "\n\$messageJSON->resources[ 0 ]:" . $resources0 . "\n\n" ) ;
				ob_start();
				print_r( $json );
				$output = ob_get_clean();
				fwrite( $logFH, $output . "\n\n" );
				fwrite( $logFH, "Done. Finished. End. That's all folks. " . date( "Y-m-d H:i:s" ) . "\n\n" ) ;
				fclose( $logFH ) ;
			}
			echo "Message processed successfully.\n" ;	// This goes out to the HTTP client / agent.
			exit( 0 ) ;	// For a Scheduled Event notification, we're done.
		}


		// Go through all the objects we expect to be in the Message.
		// If something is not there, create it with a place-holder value.
		foreach( array( "AlarmName", "AlarmDescription", "NewStateValue", "NewStateReason", "StateChangeTime" ) as $testThis ) {
			if ( ! property_exists( $messageJSON, $testThis ) ) {
				fwrite( $logFH, "Missing object replaced with default value: " . $testThis . "\n" ) ;
				$messageJSON->$testThis = "undefined " . $testThis ;
			}
		}
		if ( ! property_exists( $messageJSON, "Trigger" ) ) {
			$messageJSON->Trigger = "" ;
			if ( ! property_exists( $messageJSON->Trigger, "MetricName" ) ) {
				fwrite( $logFH, "Missing object replaced with default value: Trigger->MetricName\n" ) ;
				$messageJSON->Trigger->MetricName = "undefined Trigger MetricName" ;
			}
		}

		if ( $logToFile ) {
			fwrite( $logFH, "AlarmName: " 		. $messageJSON->AlarmName . "\n" ) ;
			fwrite( $logFH, "AlarmDescription: " 	. $messageJSON->AlarmDescription . "\n" ) ;
			fwrite( $logFH, "Trigger->MetricName: "	. $messageJSON->Trigger->MetricName . "\n\n" ) ;
		}




// Nagios Exit Errorlevels, 0=OK, 1=Warning, 2=Critical, 3=Unknown

		$nagiosStatus = 3 ;					// Set a default.
		if ( $messageJSON->NewStateValue == "ALARM" ) {
			$nagiosStatus = 2 ;
		}
		if ( preg_match( "/HTTPCode_ELB_/i", $messageJSON->Trigger->MetricName ) ) {
			if ( $messageJSON->NewStateValue == "INSUFFICIENT_DATA" ) { 	// This is actually OK because this means
				$nagiosStatus = 0 ;					// there were NONE seen.
			}
			if ( $messageJSON->NewStateValue == "OK" ) {			// This is actually a Warning because
				$nagiosStatus = 1 ;					// this means there were SOME seen,
			}								// it just didn't go over the threshold.
		} else {
			if ( $messageJSON->NewStateValue == "INSUFFICIENT_DATA" ) {
				$nagiosStatus = 1 ;
			}
			if ( $messageJSON->NewStateValue == "OK" ) {
				$nagiosStatus = 0 ;
			}
		}


// Working from shell:
// echo "[`date +%s`] PROCESS_SERVICE_CHECK_RESULT;kwp490-ELB-Alarm;HealthyHostCount;0;Foo bar baz" > ~nagios/var/rw/nagios.cmd
// echo "[`date +%s`] PROCESS_HOST_CHECK_RESULT;kwp490-ELB-Alarm;0;Foink" > ~nagios/var/rw/nagios.cmd

		$alarmNameExploded = explode( " ", $messageJSON->AlarmName, 2 ) ;
		$webSiteName = $alarmNameExploded[ 0 ] ;
		$webSiteName = str_replace( "-", ".", $webSiteName ) ;
		$nagiosHostName = $webSiteName . ":" . $messageJSON->Trigger->Dimensions[ 0 ]->value ;
		$nagiosServiceName = $messageJSON->Trigger->MetricName . ": " . $messageJSON->AlarmName ;	// Updated 2015-08-28 to match the new format from ~nagios/libexec/FAS/Nagios-config-from-alarms.php line 452 -- Stefan

		if ( isset( $alarmNameExploded[ 1 ] ) && $alarmNameExploded[ 1 ] != null ) {
			$alarmNameExploded1 = $alarmNameExploded[ 1 ] ;
		} else {
			$alarmNameExploded1 = "(Error: Improper CloudWatch Alarm Name format!!)" ;
			if ( $logToFile ) {
				fwrite( $logFH, "******** Error: Improper CloudWatch Alarm Name format! It appears to be missing the required space delimiter! ******** \n\n" ) ;
			}
		}
		$nagiosStatusInfo = $alarmNameExploded1
				. ": "
				. $messageJSON->NewStateReason 
				. " "
				. $messageJSON->StateChangeTime ;

		$nagiosMessage = "[" . date( "U" ) . "] PROCESS_SERVICE_CHECK_RESULT;" 
				. $nagiosHostName . ";"
				. $nagiosServiceName . ";"
				. $nagiosStatus . ";"
				. $nagiosStatusInfo ;

		if ( $logToFile ) {
			fwrite( $logFH, "Nagios message:\n" . $nagiosMessage . "\n" ) ;
		}

		if ( ! file_exists( $commandPipePath ) ) {
			header( $HEADER503 );
			echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
			if ( $logToFile ) {
				fwrite( $logFH, "\nError - can't find Nagios command pipe \"" . $commandPipePath . "\" !!!\n\n" ) ;
				fclose( $logFH ) ;
			}
			exit( 1 ) ;
		}

		$nagiosCommandPipe = fopen( $commandPipePath, "a" ) ;
		if ( ! $nagiosCommandPipe ) {
			header( $HEADER503 );
			echo $HEADER503 . "\n" ;	// This goes out to the HTTP client / agent.
			if ( $logToFile ) {
				fwrite( $logFH, "\nError opening Nagios command pipe \"" . $commandPipePath . "\" !!!\n\n" ) ;
				fclose( $logFH ) ;
			}
			exit( 1 ) ;
		}

		// Finally we actually write out the passive check command to Nagios for processing! Whew!!
		if ( $writeToNagios && $writeToNagios == true ) {	// Make sure only the bool does it, not the string "true"
			fwrite( $nagiosCommandPipe, $nagiosMessage . "\n" ) ;
			if ( $logToFile ) {
				fwrite( $logFH, "Written to " . $commandPipePath . "\n\n" ) ;
			}
		} else {
			fwrite( $logFH, "\n*** DEBUG ON - NOT written to " . $commandPipePath . " ***\n\n" ) ;
		}
		fclose( $nagiosCommandPipe ) ;


		// If we got this far, we had a valid incoming message and we wrote out the passive check command to
		// Nagios (or we would have) so now throw an entry in the tracking file that indicates a successful
		// incoming AWS SNS message. (This is in addition to the special "Scheduled Event" type handled above.)
		if ( $monitoringFH ) {
			fwrite( $monitoringFH, join( ',', array( date( "U" ), $json->TopicArn, $messageJSON->StateChangeTime, "CloudWatch Alarm", __FILE__, getenv( 'REMOTE_ADDR' ) ) ) . "\n" ) ;
			fclose( $monitoringFH ) ;
		}


		if ( $logToFile ) {
			fwrite( $logFH, "Finished processing Notification!\n\n" ) ;
		}
	}
}


// Additional log / debugging output.
if ( $logToFile ) {

	ob_start();
	print_r( $json );
	$output = ob_get_clean();
	fwrite ( $logFH, "Contents of \"\$json\":\n" . $output . "\n\n" );
	// echo $output . "\n" ;	// This goes out to the HTTP client / agent. Not for Prod use.


	if ( isset( $messageJSON ) && ! is_null( $messageJSON ) && $messageJSON != "" ) {
		ob_start();
		print_r( $messageJSON );
		$output = ob_get_clean();
		fwrite ( $logFH, "Contents of \"\$messageJSON\":\n" . $output . "\n\n" );
		// echo $output . "\n" ;	// This goes out to the HTTP client / agent. Not for Prod use.
	}

	fwrite( $logFH, "Done. Finished. End. That's all folks. " . date( "Y-m-d H:i:s" ) . "\n\n" ) ;
	fclose( $logFH );
}


echo "Message processed successfully.\n" ;	// This goes out to the HTTP client / agent.
exit( 0 ) ;


// -----------------------------------------------------------------------------------------------------------
// Takes the key file, signature, and signed data and tells us if it all matches.
function validateCertificate( $keyFileURL, $signatureString, $data ) {

	// Make sure there's a URL from which to get the signing cert!
	if ( ! isset( $keyFileURL ) || $keyFileURL == "" ) {
		return false ;
	}

	$signature = base64_decode( $signatureString );


	// fetch certificate from file and ready it
	$fp = fopen( $keyFileURL, "r" );
	// Try twice more in case it fails the first time.
	if ( $fp == false ) {
		$fp = fopen( $keyFileURL, "r" );
	}
	if ( $fp == false ) {
		$fp = fopen( $keyFileURL, "r" );
	}
	if ( $fp == false ) {	// If we still can't retrieve the cert from the given SigningCertURL after 3 tries,
		return false ;	// all we can do is bail out - otherwise we'll throw all sorts of php warnings.
	}
	$cert = fread( $fp, 8192 );
	fclose( $fp );

	$pubkeyid = openssl_get_publickey( $cert );

	$ok = openssl_verify( $data, $signature, $pubkeyid, OPENSSL_ALGO_SHA1 );

	if ( $ok == 1 ) {
	    return true;
	} elseif ( $ok == 0 ) {
	    return false;
	} else {
	    return false;
	}	
}


// -----------------------------------------------------------------------------------------------------------
// Take a URL String and return the domain portion only, or "ERROR" if the 'host' object isn't found.
function getDomainFromUrl( $urlString ){
	$domain = "";
	$urlArray = parse_url( $urlString );

	if ( $urlArray == false || ! isset( $urlArray['host'] ) ) {
		$domain = "ERROR";
	} else {
		$domain = $urlArray['host'];
	}

	return $domain;
}
// -----------------------------------------------------------------------------------------------------------



?>

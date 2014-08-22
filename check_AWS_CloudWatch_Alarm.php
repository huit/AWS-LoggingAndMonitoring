#!/usr/bin/php
<?php

// check_AWS_CloudWatch_Alarm.php
// 
// By Stefan Wuensch stefan_wuensch@harvard.edu 2014-08-21
//
// Usage: 
// check_AWS_CloudWatch_Alarm.php [ -h ] [ -v ] --hostName string --hostAlias string --serviceDescription string [ --help ]
//
// This Nagios plugin makes a call to AWS using the AWS command-line tools. It queries AWS for the specific
// CloudWatch Alarm that represents the Nagios Host and Service. The Alarm StateValue is used to determine
// the status of the AWS Alarm.
//
// Mapping of AWS Alarm state to Nagios state:
// AWS CloudFront	Nagios
// ------------------------------------
// OK			OK
// INSUFFICIENT_DATA	Warning
// ALARM		Critical
// (any other state)	Unknown

error_reporting( E_ALL );
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );
date_default_timezone_set('America/New_York');

//For Debugging.
$debug = true;

// Load our constants and etc.
include_once(dirname(__FILE__).'/utils.php');

$commandOptions = getopt( "hv", array( "hostName:", "hostAlias:", "serviceDescription:", "profile:", "help" ) ) ;
if ( isset( $commandOptions[ "h" ] ) || isset( $commandOptions[ "help" ] ) ) {
	usage() ;
	exit( STATE_UNKNOWN );
}
foreach( array( "hostName", "hostAlias", "serviceDescription", "profile" ) as $testThis ) {
	if ( ! isset( $commandOptions[ $testThis ] ) || $commandOptions[ $testThis ] == "" ) {
		print "Error: Missing value for " . $testThis . "\n" ;
		usage() ;
		exit( STATE_UNKNOWN );
	}
}

if( $debug ){
	////LOG TO FILE:
	$dateString = date("Y-m-d");
	$logFile = "/var/tmp/cloudwatch/" . $dateString . "_r.txt";

	$logFH = fopen($logFile, 'a') or die("Log File Cannot Be Opened.");
	fwrite( $logFH, "==============================================================================================================\n" ) ;
	fwrite( $logFH, __FILE__ . " " . date("Y-m-d H:i:s") . "\n\n" ) ;
}

// Example that works as of 2014-08-21:
// aws cloudwatch describe-alarms-for-metric --profile hwp --metric-name Latency --namespace AWS/ELB --dimensions Name=LoadBalancerName,Value=HPACWWWPr-ElasticL-JZF3JWQ62LQC

list( $sitename, $namespace, $dimensionsName ) 	= preg_split( '/:/', $commandOptions[ "hostAlias" ], 3 ) ;
list( $sitename, $dimensionsValue ) 		= preg_split( '/:/', $commandOptions[ "hostName" ], 2 ) ;

$awsReadAlarmCommand =  "aws cloudwatch describe-alarms-for-metric" ;
$awsReadAlarmCommand .= " --profile " 		. $commandOptions[ "profile" ] ;
$awsReadAlarmCommand .= " --metric-name " 	. $commandOptions[ "serviceDescription" ] ;
$awsReadAlarmCommand .= " --namespace "		. $namespace ;
$awsReadAlarmCommand .= " --dimensions Name=" 	. $dimensionsName . ",Value=" . $dimensionsValue ;

$CloudWatchAlarmsJSON = json_decode( shell_exec( $awsReadAlarmCommand ) ) ;

if ( $debug ) {
	fwrite( $logFH, $awsReadAlarmCommand ) ;
}

// Check for getting something back!
if ( ! isset( $CloudWatchAlarmsJSON ) || $CloudWatchAlarmsJSON == "" ) {
	print "Error - no JSON data returned from \"$awsReadAlarmCommand\"\n" ;
	fwrite( $logFH, "Error - no JSON data returned from \"$awsReadAlarmCommand\"\n" ) ;
	exit( STATE_UNKNOWN ) ;
}

// If we got more than one match, that's a problem!
if ( sizeof( $CloudWatchAlarmsJSON->MetricAlarms ) != 1 ) {
	print "Error: Found " . sizeof( $CloudWatchAlarmsJSON->MetricAlarms ) . " MetricAlarms from " . $awsReadAlarmCommand . "\n" ;
	exit( STATE_UNKNOWN ) ;
}

$alarmInstance = $CloudWatchAlarmsJSON->MetricAlarms[ 0 ] ;

$nagiosStatus = STATE_UNKNOWN ;
if ( $alarmInstance->StateValue == "ALARM" ) {
	$nagiosStatus = STATE_CRITICAL ;
}
if ( $alarmInstance->StateValue == "INSUFFICIENT_DATA" ) {
	$nagiosStatus = STATE_WARNING ;
}
if ( $alarmInstance->StateValue == "OK" ) {
	$nagiosStatus = STATE_OK ;
}

print $alarmInstance->StateValue
	. ": " 
	. $alarmInstance->AlarmName 
	. ": " 
	. $alarmInstance->StateReason
	. " Last state change: " 
	. $alarmInstance->StateUpdatedTimestamp
	. "\n" ;

exit( $nagiosStatus ) ;

//=============================================================================
function usage() {

	print "Usage: \n" ;
	print __FILE__ . " [ -h ] [ -v ] --hostName string --hostAlias string --serviceDescription string [ --help ]\n" ;

}
//=============================================================================


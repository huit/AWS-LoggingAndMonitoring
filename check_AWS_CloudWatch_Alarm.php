#!/usr/bin/php
<?php

// check_AWS_CloudWatch_Alarm.php
// 
// By Stefan Wuensch stefan_wuensch@harvard.edu 2014-08-21
//
// Usage: 
// check_AWS_CloudWatch_Alarm.php [ -h ] [ -v ] --hostName string --hostData string --serviceDescription string [ --help ]
//
// This Nagios plugin makes a call to AWS using the AWS command-line tools. It queries AWS for the specific
// CloudWatch Alarm that represents the Nagios Host and Service. The Alarm StateValue is used to determine
// the status of the AWS Alarm.
//
// Mapping of AWS Alarm state to Nagios state, except for AWS/ELB with HTTPCode_ELB_{4,5}XX:
// AWS CloudFront	Nagios
// ------------------------------------
// OK			OK
// INSUFFICIENT_DATA	Warning
// ALARM		Critical
// (any other state)	Unknown
// 
// Mapping of AWS Alarm state to Nagios state for AWS/ELB with HTTPCode_ELB_{4,5}XX (see notes inline):
// AWS CloudFront	Nagios
// ------------------------------------
// INSUFFICIENT_DATA	OK
// OK			Warning
// ALARM		Critical
// (any other state)	Unknown

error_reporting( E_ALL );
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );
date_default_timezone_set('America/New_York');

//For Debugging.
$debug = false;

// Load our constants and etc.
include_once(dirname(__FILE__).'/utils.php');

$commandOptions = getopt( "hv", array( "hostName:", "hostData:", "serviceDescription:", "profile:", "help" ) ) ;
if ( isset( $commandOptions[ "h" ] ) || isset( $commandOptions[ "help" ] ) ) {
	usage() ;
	exit( STATE_UNKNOWN );
}
foreach( array( "hostName", "hostData", "serviceDescription", "profile" ) as $testThis ) {
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

	ob_start();
	var_dump( $argv );
	$contents = ob_get_contents();
	ob_end_clean();
	fwrite( $logFH, $contents . "\n" );
}

// Example that works as of 2014-08-21:
// aws cloudwatch describe-alarms-for-metric --profile hwp --metric-name Latency --namespace AWS/ELB --dimensions Name=LoadBalancerName,Value=HPACWWWPr-ElasticL-JZF3JWQ62LQC

list( $sitename, $namespace, $dimensionsName ) 	= preg_split( '/:/', $commandOptions[ "hostData" ], 3 ) ;
list( $sitename, $dimensionsValue ) 		= preg_split( '/:/', $commandOptions[ "hostName" ], 2 ) ;

// This was the old way to get the alarm data. This assumed that there was only one instance of an alarm with 
// a particular MetricName for each "--dimensions Name="
// However, now (2015) that we need to be able to have multiple alarms with the same MetricName, we need to be
// able to query for something unique. 
// $awsReadAlarmCommand =  "aws cloudwatch describe-alarms-for-metric" ;
// $awsReadAlarmCommand .= " --profile " 		. $commandOptions[ "profile" ] ;
// $awsReadAlarmCommand .= " --metric-name " 	. $commandOptions[ "serviceDescription" ] ;
// $awsReadAlarmCommand .= " --namespace "		. $namespace ;
// $awsReadAlarmCommand .= " --dimensions Name=" 	. $dimensionsName . ",Value=" . $dimensionsValue ;

// Here's the new way. This assumes that the Nagios Service name is now built from [ MetricName + ": " + AlarmName ]
// Example Service Name: "HealthyHostCount: online-learning-harvard-edu Load Balancer Healthy Instance Count 1 Minute"
// Here we will split on the ": " and take the second element as the query {item} for "describe-alarms --alarm-names {item}"
// This will always return a single Alarm, because the CloudWatch Alarm Name (AlarmName) is forced to be unique for us!

// First test to make sure we got something that contains ": " which is our manditory delimiter
if ( ! preg_match( "/: /", $commandOptions[ "serviceDescription" ] ) ) {
	print "Error - serviceDescription expected to be made up of [ MetricName + \": \" + AlarmName ] \n" ;
	exit( STATE_UNKNOWN ) ;
}

// Now break up the serviceDescription and use the second element for our --alarm-names query
list( $NagiosMetricName, $NagiosAlarmName ) = preg_split( '/: /', $commandOptions[ "serviceDescription" ], 2 ) ;
$awsReadAlarmCommand =  "aws cloudwatch describe-alarms" ;
$awsReadAlarmCommand .= " --profile " 		. $commandOptions[ "profile" ] ;
$awsReadAlarmCommand .= " --alarm-names \"" 	. $NagiosAlarmName . "\"";

$CloudWatchAlarmsJSON = json_decode( shell_exec( $awsReadAlarmCommand ) ) ;

if ( $debug ) {
	fwrite( $logFH, $awsReadAlarmCommand . "\n\n" ) ;
}

// Check for getting something back!
if ( ! isset( $CloudWatchAlarmsJSON ) || $CloudWatchAlarmsJSON == "" ) {
	print "Error - no JSON data returned from \"$awsReadAlarmCommand\"\n" ;
	if( $debug ){
		fwrite( $logFH, "Error - no JSON data returned from \"$awsReadAlarmCommand\"\n" ) ;
	}
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

// Special handling of ELB 4xx and 5xx codes, because the "INSUFFICIENT_DATA" is actually OK and "OK" really means Warning.
// Why? Because if there's no data at all that means there's no 4xx/5xx codes seen - and that's good.
// If we do see *some* 4xx/5xx but it's below the threshold, that's not a big deal = Warning.
if ( $namespace == "AWS/ELB" && preg_match( "/HTTPCode_ELB_/i", $commandOptions[ "serviceDescription" ] ) ) {
	if ( $alarmInstance->StateValue == "INSUFFICIENT_DATA" ) {
		$nagiosStatus = STATE_OK ;
	}
	if ( $alarmInstance->StateValue == "OK" ) {
		$nagiosStatus = STATE_WARNING ;
	}
} else {
	if ( $alarmInstance->StateValue == "INSUFFICIENT_DATA" ) {
		$nagiosStatus = STATE_WARNING ;
	}
	if ( $alarmInstance->StateValue == "OK" ) {
		$nagiosStatus = STATE_OK ;
	}
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
	print __FILE__ . " [ -h ] [ -v ] --hostName string --hostData string --serviceDescription string [ --help ]\n" ;

}
//=============================================================================


#!/usr/bin/php
<?php

// Nagios-config-from-alarms.php
//
// By Stefan Wuensch stefan_wuensch@harvard.edu 2014-07-23
//
// Usage:
// aws cloudwatch describe-alarms [ options ] | Nagios-config-from-alarms.php
//
// Output: Nagios configuration objects
//
// Required input: JSON
//
// Required minimum input objects and structure:
// {
//     "MetricAlarms": [
//         {
//             "AlarmArn": "arn:aws:cloudwatch:region:accountNumber:alarm:name", 
//             "AlarmActions": [
//                 "arn:aws:sns:region:accountNumber:topicName"
//             ], 
//             "Namespace": "AWS/XXX", 
//             "AlarmDescription": "Some text string(s)", 
//             "AlarmName": "websitename-harvard-edu plus other text - space REQUIRED after the -edu", 
//             "Dimensions": [
//                 {
//                     "Name": "Some Text", 
//                     "Value": "Some Text"
//                 }
//             ], 
//             "MetricName": "CPUUtilization"
//         }
//     ]
// }
//
//
// Example minified input string for testing:
//{"MetricAlarms":[{"AlarmArn":"arn:aws:cloudwatch:myFakeRegion:123456:alarm:MyTestAlarm","AlarmActions":["arn:aws:sns:myFakeRegion:123456:SomeNagiosTopic"],"Namespace":"AWS/RDS","AlarmDescription":"Some text string(s)","AlarmName":"websitename-harvard-edu plus other text","Dimensions":[{"Name":"FakeDescriptor","Value":"fakeHostName"}],"MetricName":"CPUUtilization"}]}
//
//
// Primary data (where X is the index into the array)
// Nagios "host" name:    $json->MetricAlarms[ X ]->Dimensions[ 0 ]->Value
// Nagios "service" name: $json->MetricAlarms[ X ]->MetricName
// Nagios serviceextinfo: $json->MetricAlarms[ X ]->AlarmDescription
// 
// 
// Changes:
// 2014-08-21 - Added MetricAlarms->Namespace into colon-delimited new format of Host Alias, to be used in performing Active Service Checks.
// 2014-08-22 - Added check for no JSON on input
// 2014-09-03 - Changed "thing=explode()[ index ]" which works on PHP 5.4 to "thingX=explode(); thing=thingX[ index ]" which works on PHP 5.3. Grr.



error_reporting( E_ALL );
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );
date_default_timezone_set('America/New_York');

// Assume data from STDIN only - probably we'll never read a file for input.
$json = json_decode( file_get_contents( "php://stdin" ) );

// Check for getting something back!
// Exit 3 which is Nagios Unknown state so this can become automated at some point!
if ( ! isset( $json ) || $json == "" ) {
	print "Error - no JSON data on input! \n" ;
	exit( 3 ) ;
}


// Need to ensure these are empty because we bolt on additional strings later.
$hostList = "";
$serviceList = "";


// Don't bother looking at anything other than the first one. 
// We'll assume it's all the same region.
$regionExploded = explode( ":", $json->MetricAlarms[ 0 ]->AlarmArn ) ;
$region = $regionExploded[ 3 ] ;
//print "# \$region = " ;
//var_dump( $region ) ;


////////////////////////////////////////////////////////////////////////////////
// Header / Comments

echo <<<ENDOFTEXT
#
# THIS CONFIG FILE IS AUTOMATICALLY GENERATED
#
# If you edit this file, it will be over-written!
#
# The script that created this output was:

ENDOFTEXT;

echo "# " . __FILE__ . "\n#\n";

print "# This config file generated: " . date("Y-m-d H:i:s") . "\n#\n\n\n" ;





////////////////////////////////////////////////////////////////////////////////
// Templates
//
// NOTE: These two templates are importing the *active* check templates from
// the hosts.cfg and services.cfg

echo <<<ENDOFTEXT
###############################################################################
# Templates

define host {
	name				aws-host-CloudFront-Alarm
	use				aws-host-active-check
	contact_groups			aws-info-group
	register			0
}


# Very lazy check intervals because we are relying on Passive check inputs for true Alarm conditions.
# The Active checking of services is just to fill in when we'd otherwise be waiting around for a Passive check.
define service {
	name				aws-service-CloudFront-Alarm
	use				aws-service-active-check
	contact_groups			aws-info-group
	check_command			check_AWS_CloudWatch_Alarm!hwp
	normal_check_interval		30
	retry_check_interval		15
	notification_interval		30
	max_check_attempts		1
	register			0
# Enable these two (and comment out check_command above) if you want to do purely Passive checks, incoming SNS from AWS Cloudwatch.
#	check_command			passive-only-clear-pending
#	check_period			none
}





ENDOFTEXT;




////////////////////////////////////////////////////////////////////////////////
// Hosts

// Build a hash table - quick way to get a set of unique host names
// Key: "host" name
// Value: the name of the attribute that is giving us the name

foreach( $json->MetricAlarms as $alarmInstance ) {

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {	// Only if it's a Nagios action!
			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;
			$webSiteName = $webSiteNameExploded[ 0 ] ;
			$webSiteName = str_replace( "-", ".", $webSiteName ) ;
			$hostName = $webSiteName . ":" . $alarmInstance->Dimensions[ 0 ]->Value ;
			$allHostNames[ $hostName ] = $alarmInstance->Namespace . ":" . $alarmInstance->Dimensions[ 0 ]->Name ;
		}
	}
}


// Alternative way of doing the 'foreach'
// See http://php.net/manual/en/function.key.php
//while ( current( $allHostNames ) ) {
//	$hostName = key( $allHostNames ) ;
//	$hostNameFrom = $allHostNames[ $hostName ] ;
//
//	next( $allHostNames ) ;


print "###############################################################################\n" ;
print "# Hosts\n\n" ;

print "# Total number of AWS MetricAlarms: " . sizeof( $json->MetricAlarms ) . "\n";
print "# Total number of Nagios AWS Hosts: " . sizeof( $allHostNames ) . "\n\n";

foreach ( $allHostNames as $hostName => $hostNameFrom ) {

	$hostList .= $hostName . ",";
	list( $partOne, $partTwo, $discard ) = preg_split( '/[\.:]/', $hostName, 3 ) ;
	$shortSiteName = $partOne . "." . $partTwo ;

	echo <<<ENDOFTEXT
define host {
	use			aws-host-CloudFront-Alarm
	host_name		$hostName
	alias			$shortSiteName:$hostNameFrom
	hostgroups		HPAC in AWS - All
}





ENDOFTEXT;

}



////////////////////////////////////////////////////////////////////////////////
// Services

$awsConsoleURLBase = "https://console.aws.amazon.com/" ;

print "###############################################################################\n" ;
print "# Services\n\n" ;

foreach( $json->MetricAlarms as $alarmInstance ) {

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {

// the working area - in progress
			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;
			$webSiteName =    $webSiteNameExploded[ 0 ] ;
			$webSiteName =    str_replace( "-", ".", $webSiteName ) ;
			$instanceName =   $alarmInstance->Dimensions[ 0 ]->Value ;
			$hostName = 	  $webSiteName . ":" . $instanceName ;
			$hostNameFrom =   $alarmInstance->Dimensions[ 0 ]->Name ;
			$serviceName = 	  $alarmInstance->MetricName ;
			$serviceExtInfo = $alarmInstance->AlarmDescription ;
			$namespace =      $alarmInstance->Namespace ;

			$serviceList .= $hostName . "," . $serviceName . ",";

			switch ( $namespace ) {
				case "AWS/RDS" :
					$actionURL = $awsConsoleURLBase
							. "rds/home?region="
							. $region
							. "#dbinstances:id="
							. $instanceName
							. "%3Bsf=all" ;
					break ;

				case "AWS/ELB" :
					$actionURL = $awsConsoleURLBase
							. "ec2/v2/home?region="
							. $region
							. "#LoadBalancers:search="
							. $instanceName ;
					break ;

				case "AWS/EC2" :
					$actionURL = $awsConsoleURLBase
							. "ec2/v2/home?region="
							. $region
							. "#Instances:search="
							. $instanceName ;
					break ;

				default:
					$actionURL = $awsConsoleURLBase
							. "console/home?region="
							. $region ;
			}

			$notesURL = $awsConsoleURLBase
					. "cloudwatch/home?region="
					. $region
					. "#alarm:alarmFilter=ANY%3Bname="
					. rawurlencode( rawurlencode( $alarmInstance->AlarmName ) ) ;


			echo <<<ENDOFTEXT
define service {
	use				aws-service-CloudFront-Alarm
	host_name			$hostName
	service_description		$serviceName
}
define serviceextinfo {
	host_name			$hostName
	service_description		$serviceName
	notes				$serviceExtInfo ($hostNameFrom = $hostName, AlarmName = $alarmInstance->AlarmName)
	action_url			$actionURL
	notes_url			$notesURL
}





ENDOFTEXT;
// end of the work in progress

//			print "# \$alarmAction = " ;
//			var_dump( $alarmAction );
		}
	}
}



////////////////////////////////////////////////////////////////////////////////
// Host Group(s)

$hostList = rtrim( $hostList, "," ) ; // Trim off the last comma

	echo <<<ENDOFTEXT
###############################################################################
# Hostgroups

define hostgroup {
	hostgroup_name	HPAC in AWS - Incoming Alarms
	alias		Harvard Publishing and Communications AWS Incoming SNS
	members		$hostList
}





ENDOFTEXT;




////////////////////////////////////////////////////////////////////////////////
// Service Group(s)

$serviceList = rtrim( $serviceList, "," ) ; // Trim off the last comma

	echo <<<ENDOFTEXT
###############################################################################
# Servicegroups

define servicegroup {
	servicegroup_name	HPAC in AWS
	alias			HPAC Service Checks from AWS
	members			$serviceList
}





ENDOFTEXT;



exit( 0 );
?>

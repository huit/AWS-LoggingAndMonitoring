#!/usr/bin/php
<?php

// =============================================================================================
// Nagios-config-from-alarms.php
//
// By Stefan Wuensch stefan_wuensch@harvard.edu September 2014
//
// Usage:
// Nagios-config-from-alarms.php --appStack string --profile string [ -h ] [ --help ]
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
// {"MetricAlarms":[{"AlarmArn":"arn:aws:cloudwatch:myFakeRegion:123456:alarm:MyTestAlarm","AlarmActions":["arn:aws:sns:myFakeRegion:123456:SomeNagiosTopic"],"Namespace":"AWS/RDS","AlarmDescription":"Some text string(s)","AlarmName":"websitename-harvard-edu plus other text","Dimensions":[{"Name":"FakeDescriptor","Value":"fakeHostName"}],"MetricName":"CPUUtilization"}]}
//
//
// Primary data (where X is the index into the array)
// Nagios "host" name:    $json->MetricAlarms[ X ]->Dimensions[ 0 ]->Value
// Nagios "service" name: $json->MetricAlarms[ X ]->MetricName
// Nagios serviceextinfo: $json->MetricAlarms[ X ]->AlarmDescription
// 
// =============================================================================================
// 
// Changes:
// 2014-08-21 - Added MetricAlarms->Namespace into colon-delimited new format of Host Alias, to be used in performing Active Service Checks.
// 2014-08-22 - Added check for no JSON on input
// 2014-09-03 - Changed "thing=explode()[ index ]" which works on PHP 5.4 to "thingX=explode(); thing=thingX[ index ]" which works on PHP 5.3. Grr.
// 2014-09-18 - Excluding Host config generation for AWS/EC2 Instances because we're now building those from Instance data in the other script.
//		Changed from reading JSON on STDIN to calling AWS CLI
// =============================================================================================



error_reporting( E_ALL );
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );
date_default_timezone_set('America/New_York');

// Load our constants and etc.
include_once( dirname( __FILE__ ) . '/utils.php' );



// =============================================================================================
// Load our configuration data
$configFile = dirname( __FILE__ ) . '/AWS_config.json' ;
$configJSON = json_decode( file_get_contents( $configFile ) ) ;

$commandOptions = getopt( "h", array( "appStack:", "profile:", "help" ) ) ;
if ( isset( $commandOptions[ "h" ] ) || isset( $commandOptions[ "help" ] ) ) {
	usage() ;
	exit( STATE_UNKNOWN );
}
foreach( array( "appStack", "profile" ) as $testThis ) {
	if ( ! isset( $commandOptions[ $testThis ] ) || $commandOptions[ $testThis ] == "" ) {
		print "Error: Missing value for " . $testThis . "\n" ;
		usage() ;
		exit( STATE_UNKNOWN );
	}
}
// =============================================================================================





// =============================================================================================
// Misc. global variable setup

$awsConsoleURLBase = "https://console.aws.amazon.com/" ;
$myName = __FILE__ ;
$nagiosMasterName = $configJSON->nagiosMasterName ;

$customerProfile 	= $commandOptions[ "profile" ] ;
$appStack 		= $commandOptions[ "appStack" ] ;
$customerShortName = $configJSON->accountsByName->$customerProfile->$appStack->customerShortName ;
$customerLongName  = $configJSON->accountsByName->$customerProfile->$appStack->customerLongName ;
$stackNameMatch    = $configJSON->accountsByName->$customerProfile->$appStack->stackNameMatch ;

// Need to ensure these are empty because we bolt on additional strings later.
$hostList = "";
$serviceList = "";
// =============================================================================================




// =============================================================================================
// Get Alarms
$awsReadAlarmsCommand = "aws cloudwatch describe-alarms --profile=" . $customerProfile ;
$alarmsJSON = json_decode( shell_exec( $awsReadAlarmsCommand ) ) ;

// Check for getting something back!
if ( ! isset( $alarmsJSON ) || $alarmsJSON == "" ) {
	print "Error - no JSON alarm data from $awsReadAlarmsCommand \n" ;
	exit( STATE_UNKNOWN ) ;
}
if ( sizeof( $alarmsJSON->MetricAlarms ) < 1 ) {
	print "Error: Got no Alarms back from $awsReadAlarmsCommand \n" ;
	exit( STATE_UNKNOWN ) ;
}
// =============================================================================================

// Don't bother looking at anything other than the first one. 
// We'll assume it's all the same region.
$regionExploded = explode( ":", $alarmsJSON->MetricAlarms[ 0 ]->AlarmArn ) ;
$region = $regionExploded[ 3 ] ;



// =============================================================================================
// Get Instances

// Example call:
// aws --profile=hwp ec2 describe-instances --filters "Name=tag-value,Values=HPAC*Prod" "Name=tag:Environment,Values=prod" "Name=instance-state-name,Values=running"

// However, for HPAC it's actually redundant to specify "prod" in both the tag-name globbing search and tag name/value, so we really only need one of the following:
// aws --profile=hwp ec2 describe-instances --filters "Name=tag-value,Values=HPAC*" "Name=tag:Environment,Values=prod" "Name=instance-state-name,Values=running"
// or
// aws --profile=hwp ec2 describe-instances --filters "Name=tag-value,Values=HPAC*Prod" "Name=instance-state-name,Values=running"


$awsReadEC2InstancesCommand  = "aws ec2 describe-instances" ;
$awsReadEC2InstancesCommand .= " --profile=" . $customerProfile ;
$awsReadEC2InstancesCommand .= " --filters " . "\"Name=instance-state-name,Values=running\" \"Name=tag:Environment,Values=prod\"" ;
$awsReadEC2InstancesCommand .= " \"Name=tag-value,Values=" . $stackNameMatch . "\"" ;

$EC2InstancesJSON = json_decode( shell_exec( $awsReadEC2InstancesCommand ) ) ;

// Check for getting something back!
if ( ! isset( $EC2InstancesJSON ) || $EC2InstancesJSON == "" ) {
	print "Error - no JSON data returned from \"$awsReadEC2InstancesCommand\"\n" ;
	exit( STATE_UNKNOWN ) ;
}
if ( sizeof( $EC2InstancesJSON->Reservations ) < 1 ) {
	print "Error: Got no valid data back from \"aws ec2 describe-tags\" for the filter \"" . $stackNameMatch . "\"\n" ;
	exit( STATE_UNKNOWN ) ;
}
// =============================================================================================





////////////////////////////////////////////////////////////////////////////////
// Header / Comments


echo <<<ENDOFTEXT
#################################################
# THIS CONFIG FILE IS AUTOMATICALLY GENERATED
#################################################
#
# If you edit this file, it will be over-written!
#
# The script that generated this config file was:

ENDOFTEXT;

echo "# " . $myName . "\n# by Stefan Wuensch, Summer 2014\n\n";

print "# This config file generated: " . date("Y-m-d H:i:s") . "\n\n" ;

print "# Input to this script was: " . $configFile . "\n\n" ;

$statsLeadingText = "Total number of" ;
print "# Note: Search this file for the string \"$statsLeadingText\" to see statistics on the number of objects.\n\n\n" ;




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
	check_command			check_AWS_CloudWatch_Alarm!$customerProfile
	notification_options		u,c,r,f,s
	normal_check_interval		30
	retry_check_interval		15
	notification_interval		30
	max_check_attempts		1
	event_handler			submit_AWS_config_refresh!$nagiosMasterName!"Nagios configuration for $customerShortName AWS CloudWatch Alarms out-of-sync"!\$SERVICESTATE:$nagiosMasterName:Nagios configuration for $customerShortName AWS CloudWatch Alarms out-of-sync$!\$LASTSERVICECHECK:$nagiosMasterName:Nagios configuration for $customerShortName AWS CloudWatch Alarms out-of-sync$!\$SERVICESTATE$!\$SERVICEATTEMPT$!\$MAXSERVICEATTEMPTS$
	register			0
# Enable these two (and comment out check_command above) if you want to do purely Passive checks, incoming SNS from AWS Cloudwatch.
# (However, don't edit this dynamic config file! Do it in $myName)
#	check_command			passive-only-clear-pending
#	check_period			none
}





ENDOFTEXT;







////////////////////////////////////////////////////////////////////////////////
// Hosts

$totalInstancesHosts = 0 ;

// Build the EC2 Instances structure
foreach( $EC2InstancesJSON->Reservations as $instancesReservation ) {
	foreach( $instancesReservation->Instances as $ec2Instance ) {
		$instanceID = $ec2Instance->InstanceId ;
// 		print "# Found \$ec2Instance->InstanceId: " . $ec2Instance->InstanceId . "\n";

		foreach( $ec2Instance->Tags as $ec2InstanceTag ) {
			if ( $ec2InstanceTag->Key == "aws:cloudformation:stack-name" ) {
				$siteID = $ec2InstanceTag->Value ;
			}
			if ( $ec2InstanceTag->Key == "Name" ) {
				$instanceName = $ec2InstanceTag->Value ;
			}
			if ( $ec2InstanceTag->Key == "Environment" ) {
				$instanceEnvironment = $ec2InstanceTag->Value ;
			}
		}

		if ( ! isset( $instanceID ) || ! isset( $siteID ) || ! isset( $instanceName ) ) {
			print "# Skipping an instance - found no instance ID and/or no stack name and/or no instance name!\n" ;
			continue ;
		}

		$allInstanceIDs[ $instanceID ][ "PublicIpAddress" ] 	= $ec2Instance->PublicIpAddress ;
		$allInstanceIDs[ $instanceID ][ "PublicDnsName" ] 	= $ec2Instance->PublicDnsName ;
		$allInstanceIDs[ $instanceID ][ "LaunchTime" ] 		= $ec2Instance->LaunchTime ;
		$allInstanceIDs[ $instanceID ][ "Environment" ] 	= $instanceEnvironment ;
		$allInstanceIDs[ $instanceID ][ "Name" ] 		= $instanceName ;
		$allInstanceIDs[ $instanceID ][ "siteID" ] 		= $siteID ;

		$totalInstancesHosts++ ;
	}


}


// Build a hash table - quick way to get a set of unique host names
// Key: "host" name
// Value: the name of the attribute that is giving us the name

foreach( $alarmsJSON->MetricAlarms as $alarmInstance ) {

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {	// Only if it's a Nagios action!
			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;
			$webSiteName = $webSiteNameExploded[ 0 ] ;
			$webSiteName = str_replace( "-", ".", $webSiteName ) ;
			$hostName = $webSiteName . ":" . $alarmInstance->Dimensions[ 0 ]->Value ;
			
			if ( $alarmInstance->Namespace == "AWS/EC2" ) {
				if ( ! isset( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ] ) ) {
					print "\n# Skipping host \"$hostName\" (found in CloudWatch Alarms) because there is no EC2 instance with that ID found from the filter \"$stackNameMatch\"!!!\n" ;
					print "# This means the CloudWatch Alarm \"$alarmInstance->AlarmName\" ($alarmInstance->MetricName) for site $webSiteName is stale and needs to be updated!!\n\n" ;
					continue ;
				} elseif ( ! isset( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ][ "Environment" ] ) ) {
					print "\n# Skipping host \"$hostName\" (found in CloudWatch Alarms) because its EC2 Environment tag is not set, therefore it's not prod.\n\n" ;
					continue ;
				} elseif ( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ][ "Environment" ] != "prod" ) {
					print "\n# Skipping host \"$hostName\" because the Environment is \"$allInstanceIDs->$hostName->Environment\" not \"prod\"\n\n" ;
					continue ;
				}
			}
			
			$allHostNames[ $hostName ] = $alarmInstance->Namespace . ":" . $alarmInstance->Dimensions[ 0 ]->Name ;
			$allSiteNames[ $webSiteName ][ $hostName ] = true ;
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

print "# $statsLeadingText websites: " . sizeof( $allSiteNames ) . "\n";
print "# $statsLeadingText AWS MetricAlarms: " . sizeof( $alarmsJSON->MetricAlarms ) . "\n";
print "# $statsLeadingText Nagios AWS \"Hosts\": " . sizeof( $allHostNames ) . "\n\n";
print "# Note: Host Groups added here are defined in hostgroups.cfg for use in other non-dynamic config files.\n\n\n";

foreach ( $allHostNames as $hostName => $hostNameFrom ) {

	$hostList .= $hostName . ",";
	list( $partOne, $partTwo, $discard ) = preg_split( '/[\.:]/', $hostName, 3 ) ;
	$shortSiteName = $partOne . "." . $partTwo ;

	if ( $hostNameFrom == "AWS/EC2:InstanceId" ) {
		print "# NOTE: \"$hostName\" is an EC2 Instance, so its Host definition will be built elsewhere by a different script. \n# Skipping host \"$hostName\" here.\n\n\n" ;
		continue ;
	}

	echo <<<ENDOFTEXT
define host {
	use			aws-host-CloudFront-Alarm
	host_name		$hostName
	_AWS_Data		$shortSiteName:$hostNameFrom
	hostgroups		$customerShortName in AWS - All
}




ENDOFTEXT;

}



////////////////////////////////////////////////////////////////////////////////
// Services

$totalServices = 0 ;

print "###############################################################################\n" ;
print "# Services\n\n" ;

foreach( $alarmsJSON->MetricAlarms as $alarmInstance ) {

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {	// Only if it's a Nagios action!

			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;
			$webSiteName =    $webSiteNameExploded[ 0 ] ;
			$webSiteName =    str_replace( "-", ".", $webSiteName ) ;
			$instanceName =   $alarmInstance->Dimensions[ 0 ]->Value ;
			$hostName = 	  $webSiteName . ":" . $instanceName ;
			$serviceName = 	  $alarmInstance->MetricName ;

			if ( $alarmInstance->Namespace == "AWS/EC2" ) {
				if ( ! isset( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ] ) ) {
					print "\n# Skipping service \"$serviceName\" for AWS/EC2 Instance \"$hostName\" (found in CloudWatch Alarms) because there is no EC2 instance with that ID found from the filter \"$stackNameMatch\"!!!\n" ;
					print "# This means the CloudWatch Alarm \"$alarmInstance->AlarmName\" ($alarmInstance->MetricName) for site $webSiteName is stale and needs to be updated!!\n\n\n" ;
					continue ;
				} elseif ( ! isset( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ][ "Environment" ] ) ) {
					print "\n# Skipping service \"$serviceName\" for AWS/EC2 Instance \"$hostName\" because its EC2 Environment tag is not set, therefore it's not prod!\n\n" ;
					continue ;
				} elseif ( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ][ "Environment" ] != "prod" ) {
					print "\n# Skipping service \"$serviceName\" for AWS/EC2 Instance \"$hostName\" because the Environment is \"$allInstanceIDs->$hostName->Environment\" not \"prod\"\n\n" ;
					continue ;
				}
			}

			$hostNameFrom =   $alarmInstance->Dimensions[ 0 ]->Name ;
			$serviceExtInfo = $alarmInstance->AlarmDescription ;
			$namespace =      $alarmInstance->Namespace ;

			$serviceList .= $hostName . "," . $serviceName . ",";
			$totalServices++ ;

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
	notes				$serviceExtInfo ($hostNameFrom = $hostName, AlarmName = $alarmInstance->AlarmName, Namespace = $namespace)
	action_url			$actionURL
	notes_url			$notesURL
}




ENDOFTEXT;

		}
	}
}

print "# $statsLeadingText Nagios AWS Services: $totalServices\n\n\n\n";



////////////////////////////////////////////////////////////////////////////////
// Host Group(s)
//

// First do a hostgroup for all the Passive checks...

$hostList = rtrim( $hostList, "," ) ; // Trim off the last comma

	echo <<<ENDOFTEXT


###############################################################################
# Hostgroups

define hostgroup {
	hostgroup_name	$customerShortName in AWS - Incoming Alarms
	alias		$customerLongName AWS Incoming SNS
	members		$hostList
}



ENDOFTEXT;

// Next do a hostgroup for each site...

foreach( $allSiteNames as $siteName => $allHostNames ) {

	$hostList = "" ;

	foreach( $allHostNames as $hostName => $garbage ) {	// The value doesn't matter, only the key name
		$hostList .= $hostName . ",";
	}
	$hostList = rtrim( $hostList, "," ) ; // Trim off the last comma

	echo <<<ENDOFTEXT
define hostgroup {
	hostgroup_name	$customerShortName in AWS - site $siteName
	alias		$siteName $customerLongName AWS Incoming SNS
	members		$hostList
}



ENDOFTEXT;

}




////////////////////////////////////////////////////////////////////////////////
// Service Group(s)

$serviceList = rtrim( $serviceList, "," ) ; // Trim off the last comma

	echo <<<ENDOFTEXT
###############################################################################
# Servicegroups

define servicegroup {
	servicegroup_name	$customerShortName in AWS
	alias			$customerShortName Service Checks from AWS
	members			$serviceList
}





ENDOFTEXT;






// =============================================================================================
function usage() {

	print "Usage: " . __FILE__ . " --appStack string --profile string [ --help | -h ]\n" ;

}
// =============================================================================================


print "# Completed OK at " . date("Y-m-d H:i:s") . "\n" ;
exit( 0 );
?>

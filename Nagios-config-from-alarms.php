#!/usr/bin/php
<?php

// =============================================================================================
// Nagios-config-from-alarms.php
//
// By Stefan Wuensch stefan_wuensch@harvard.edu Fall 2014 - Spring 2015
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
// 2015-07-16 - Added AlarmName to the creation of serviceName so that serviceName will be unique (since AlarmName already has to be unique):
// 		$serviceName = 	$alarmInstance->MetricName . ": " . $alarmInstance->AlarmName ;
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
if ( ! isset( $configJSON ) || $configJSON == "" ) {
	print "Error: No JSON returned for config file $configFile\n" ;
	exit( STATE_UNKNOWN );
}

$commandOptions = getopt( "h", array( "appStack:", "profile:", "help" ) ) ;
if ( isset( $commandOptions[ "h" ] ) || isset( $commandOptions[ "help" ] ) ) {
	usage() ;
	exit( STATE_UNKNOWN );
}
foreach( array( "appStack", "profile" ) as $testThis ) {	// Check for required args
	if ( ! isset( $commandOptions[ $testThis ] ) || $commandOptions[ $testThis ] == "" ) {
		print "Error: Missing value for " . $testThis . "\n" ;
		usage() ;
		exit( STATE_UNKNOWN );
	}
}
$appStack 		= $commandOptions[ "appStack" ] ;
$customerProfile 	= $commandOptions[ "profile" ] ;
// =============================================================================================





// =============================================================================================
// Misc. global variable setup

$awsConsoleURLBase = "https://console.aws.amazon.com/" ;
$myName = __FILE__ ;
$nagiosMasterName = $configJSON->nagiosMasterName ;
$defaultContactGroup = $configJSON->defaultContactGroup ;
$nagiosContactGroupAlarms = $defaultContactGroup ;	// This should be replaced per-site later.

if ( ! property_exists( $configJSON->accountsByName->$customerProfile, $appStack ) ) {
	print "Error: Can't find app stack \"$appStack\" in $configFile accountsByName->$customerProfile\n" ;
	exit( STATE_UNKNOWN );
}

foreach( array( "customerShortName", "customerLongName", "tagFilters", "applicationSites" ) as $testThis ) {	// Validate these
	if ( ! property_exists( $configJSON->accountsByName->$customerProfile->$appStack, $testThis ) ) {
		print "Error: Missing \"$testThis\" in $configFile accountsByName->$customerProfile->$appStack\n" ;
		exit( STATE_UNKNOWN );
	}
}
$customerShortName = $configJSON->accountsByName->$customerProfile->$appStack->customerShortName ;
$customerLongName  = $configJSON->accountsByName->$customerProfile->$appStack->customerLongName ;

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
$awsReadEC2InstancesCommand .= " --filters " . "\"Name=instance-state-name,Values=running\" " ;

$filters = "" ;
foreach( $configJSON->accountsByName->$customerProfile->$appStack->tagFilters as $filter ) {
	$filters .= "\"$filter\" " ;
}
$awsReadEC2InstancesCommand .= $filters ;

$EC2InstancesJSON = json_decode( shell_exec( $awsReadEC2InstancesCommand ) ) ;

// Check for getting something back!
if ( ! isset( $EC2InstancesJSON ) || $EC2InstancesJSON == "" ) {
	print "Error - no JSON data returned from \"$awsReadEC2InstancesCommand\"\n" ;
	exit( STATE_UNKNOWN ) ;
}

// Commented this out 2015-08-17 - not sure if we need to have any EC2 instances present.
// if ( sizeof( $EC2InstancesJSON->Reservations ) < 1 ) {
// 	print "Error: Got no valid data back from \"aws ec2 describe-tags\" for the filter ( $filters)\n" ;
// 	exit( STATE_UNKNOWN ) ;
// }
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

echo "# " . $myName . "\n# by Stefan Wuensch, 2014 - 2015\n\n";

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

# Note: Template names are automatically generated, ending with "-$customerShortName" to differentiate from other AWS customers.
# "$defaultContactGroup" is a default, which is expected to be replaced by a value from "nagiosContactGroupAlarms" out of the site config file.

define host {
	name				aws-host-CloudFront-Alarm-$customerShortName
	use				aws-host-active-check-$customerShortName
	contact_groups			$defaultContactGroup
	register			0
}


# Very lazy check intervals because we are relying on Passive check inputs for true Alarm conditions.
# The Active checking of services is just to fill in when we'd otherwise be waiting around for a Passive check.
define service {
	name				aws-service-CloudFront-Alarm-$customerShortName
	use				aws-service-active-check-$customerShortName
	contact_groups			$defaultContactGroup
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
$allInstanceIDs = array() ;	// Init to null

// Build the EC2 Instances structure
foreach( $EC2InstancesJSON->Reservations as $instancesReservation ) {
	foreach( $instancesReservation->Instances as $ec2Instance ) {
		$instanceID = $ec2Instance->InstanceId ;
// 		print "# Found \$ec2Instance->InstanceId: " . $ec2Instance->InstanceId . "\n";

		$siteID = "" ;
		$instanceName = "" ;

		foreach( $ec2Instance->Tags as $ec2InstanceTag ) {
			if ( $ec2InstanceTag->Key == "aws:cloudformation:stack-name" ) {
				$siteID = $ec2InstanceTag->Value ;
			}
			if ( $ec2InstanceTag->Key == "SiteID" ) {
				$siteID = $ec2InstanceTag->Value ;
			}
			if ( $ec2InstanceTag->Key == "Name" ) {
				$instanceName = $ec2InstanceTag->Value ;
			}
		}

		if ( ! isset( $siteID ) || $siteID == "" || ! isset( $instanceName ) || $instanceName == "" ) {
			print "# Skipping instance $instanceID named \"$instanceName\" - found no stack name from Tags!\n" ;
			continue ;
		}

		$allInstanceIDs[ $instanceID ][ "siteID" ] 		= $siteID ;
		$allInstanceIDs[ $instanceID ][ "LaunchTime" ] 		= $ec2Instance->LaunchTime ;
		$allInstanceIDs[ $instanceID ][ "Name" ] 		= $instanceName ;

		$totalInstancesHosts++ ;
	}


}

// var_dump( $allInstanceIDs ) ;	// Debugging output

// Build a hash table - quick way to get a set of unique host names
// Key: "host" name
// Value: the name of the attribute that is giving us the name

$allSiteNames = array() ;		// Init to null
$allHostNames = array() ;		// Init to null
$hostToContactgroupMapping = array() ;	// Init to null

foreach( $alarmsJSON->MetricAlarms as $alarmInstance ) {

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {	// Only if it's a Nagios action!

			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;	// Break the Alarm Name by spaces into an array.
			if ( preg_match( "/[\.:-]/", $webSiteNameExploded[ 0 ] ) ) {		// If there is a period and/or colon and/or hyphen,
				$webSiteName = $webSiteNameExploded[ 0 ] ;			// then assume we have something in the first element like a FQDN or site name we can use.
			} elseif ( isset( $webSiteNameExploded[ 1 ] ) ) {			// If we don't have a colon or space in the first element, and the second element exists,
				$webSiteName = $webSiteNameExploded[ 0 ] . "." . $webSiteNameExploded[ 1 ] . "-constructed-name";	// then construct something from the first two words that we can later break apart.
			} else {
				$webSiteName = $webSiteNameExploded[ 0 ] . ".constructed-name" ;		// As a last resort, make up something that will still work when we split it later.
			}
			$webSiteName = 	str_replace( "-", ".", $webSiteName ) ;
			$hostName = 	$webSiteName . ":" . $alarmInstance->Dimensions[ 0 ]->Value ;

			if ( $alarmInstance->Namespace == "AWS/EC2" ) {
				if ( ! isset( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ] ) ) {
					print "\n# Skipping host \"$hostName\" (found in CloudWatch Alarms) because there is no EC2 instance with that ID found from the filter ( $filters) !!!\n" ;
					print "# This means the CloudWatch Alarm \"$alarmInstance->AlarmName\" ($alarmInstance->MetricName) for site $webSiteName is stale and needs to be updated!!\n\n" ;
					continue ;
				}
			}

			$allHostNames[ $hostName ] = $alarmInstance->Namespace . ":" . $alarmInstance->Dimensions[ 0 ]->Name ;
			$allSiteNames[ $webSiteName ][ $hostName ] = true ;
		}
	}
}

// var_dump( $allHostNames ) ;	// Debugging output
// var_dump( $allSiteNames ) ;	// Debugging output

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

foreach( $allHostNames as $hostName => $hostNameFrom ) {

	$nagiosContactGroupAlarms = $defaultContactGroup ;	// Need to set the default each time in case we don't find one in the applicationSites below
	$hostList .= $hostName . ",";
	list( $partOne, $partTwo, $discard ) = preg_split( '/[\.:]/', $hostName, 3 ) ;
	$shortSiteName = $partOne . "." . $partTwo ;

	// Go get the contact_group by looping through the data structure to find the site ID for that host name, then
	// we know which site to reference to get the contact group.
	foreach( $allSiteNames as $siteName => $allHostNamesFromSites ) {
		foreach( $allHostNamesFromSites as $hostNameFromSites => $garbage ) {	// The value doesn't matter, only the key name
			if ( $hostNameFromSites == $hostName ) {
				foreach( $configJSON->accountsByName->$customerProfile->$appStack->applicationSites as $applicationSiteInstance ) {
					if ( property_exists( $applicationSiteInstance, "websiteHostName" ) && str_replace( "-", ".", $applicationSiteInstance->websiteHostName ) == $siteName ) {
						$nagiosContactGroupAlarms = $applicationSiteInstance->nagiosContactGroupAlarms ;
						print "# Found Nagios \"host\" name \"$hostNameFromSites\" in site $siteName which has {nagiosContactGroupAlarms->$nagiosContactGroupAlarms} in the config file.\n" ;
						$hostToContactgroupMapping[ $hostNameFromSites ][ "contact_groups" ] = $nagiosContactGroupAlarms ;	// Build an association between the host name and the contact group for quick access when we do Services.
						break ;
					}
				}
			}
		}
	}

	if ( $hostNameFrom == "AWS/EC2:InstanceId" ) {
		print "# NOTE: \"$hostName\" is an EC2 Instance, so its Host definition will be built elsewhere by a different script. \n# Skipping host \"$hostName\" here.\n\n\n" ;
		continue ;
	}

	echo <<<ENDOFTEXT
define host {
	use			aws-host-CloudFront-Alarm-$customerShortName
	host_name		$hostName
	_AWS_Data		$shortSiteName:$hostNameFrom
	hostgroups		$customerShortName in AWS - All
	contact_groups		$nagiosContactGroupAlarms
}




ENDOFTEXT;

}

// var_dump( $hostToContactgroupMapping ) ;	// Debugging output

////////////////////////////////////////////////////////////////////////////////
// Services

$totalServices = 0 ;
$nagiosContactGroupAlarms = $defaultContactGroup ;	// Reset to the default since it was probably used in the Hosts section. This should be replaced per-site later.

print "###############################################################################\n" ;
print "# Services\n\n" ;

foreach( $alarmsJSON->MetricAlarms as $alarmInstance ) {

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {	// Only if it's a Nagios action!

			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;	// Break the Alarm Name by spaces into an array.
			if ( preg_match( "/[\.:-]/", $webSiteNameExploded[ 0 ] ) ) {		// If there is a period and/or colon and/or hyphen,
				$webSiteName = $webSiteNameExploded[ 0 ] ;			// then assume we have something in the first element like a FQDN or site name we can use.
			} elseif ( isset( $webSiteNameExploded[ 1 ] ) ) {			// If we don't have a colon or space in the first element, and the second element exists,
				$webSiteName = $webSiteNameExploded[ 0 ] . "." . $webSiteNameExploded[ 1 ] . "-constructed-name";	// then construct something from the first two words that we can later break apart.
			} else {
				$webSiteName = $webSiteNameExploded[ 0 ] . ".constructed-name" ;		// As a last resort, make up something that will still work when we split it later.
			}
			$webSiteName = 	str_replace( "-", ".", $webSiteName ) ;
			$hostName = 	$webSiteName . ":" . $alarmInstance->Dimensions[ 0 ]->Value ;

			$instanceName = $alarmInstance->Dimensions[ 0 ]->Value ;
			$serviceName = 	$alarmInstance->MetricName . ": " . $alarmInstance->AlarmName ;

			if ( $alarmInstance->Namespace == "AWS/EC2" ) {
				if ( ! isset( $allInstanceIDs[ $alarmInstance->Dimensions[ 0 ]->Value ] ) ) {
					print "\n# Skipping service \"$serviceName\" for AWS/EC2 Instance \"$hostName\" (found in CloudWatch Alarms) because there is no EC2 instance with that ID found from the filter ( $filters) !!!\n" ;
					print "# This means the CloudWatch Alarm \"$alarmInstance->AlarmName\" ($alarmInstance->MetricName) for site $webSiteName is stale and needs to be updated!!\n\n\n" ;
					continue ;
				}
			}

			$hostNameFrom =   $alarmInstance->Dimensions[ 0 ]->Name ;
			if ( isset( $alarmInstance->AlarmDescription ) ) {
				$serviceExtInfo = $alarmInstance->AlarmDescription ;
			} else {
				$serviceExtInfo = "(No \"AlarmDescription\" found for this CloudWatch Alarm)" ;
			}
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
// Update 2015-07-28 to current URL format
// old:					. "#alarm:alarmFilter=ANY%3Bname="
					. "#c=CloudWatch&s=Alarms&alarm="
					. rawurlencode( rawurlencode( $alarmInstance->AlarmName ) ) ;

			if ( isset( $hostToContactgroupMapping[ $hostName ][ "contact_groups" ] ) ) {
				$nagiosContactGroupAlarms = $hostToContactgroupMapping[ $hostName ][ "contact_groups" ] ;
			} else {
				print "# Warning: Could not find nagiosContactGroupAlarms in config JSON for $hostName in applicationSites - using default Contact Group $defaultContactGroup\n" ;
			}

			echo <<<ENDOFTEXT
define service {
	use				aws-service-CloudFront-Alarm-$customerShortName
	host_name			$hostName
	service_description		$serviceName
	contact_groups			$nagiosContactGroupAlarms
	notes				$serviceExtInfo ($hostNameFrom = $hostName, AlarmName = $alarmInstance->AlarmName, Namespace = $namespace)
	notes_url			$notesURL
	action_url			$actionURL
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

	if ( $hostList != "" ) {	// Are there any hosts??
		echo <<<ENDOFTEXT


###############################################################################
# Hostgroups

define hostgroup {
	hostgroup_name	$customerShortName in AWS - Incoming Alarms
	alias		$customerLongName AWS Incoming SNS
	members		$hostList
}



ENDOFTEXT;
	}				// End of Are there any hosts??

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

	if ( $serviceList != "" ) {	// Are there any services??
		echo <<<ENDOFTEXT
###############################################################################
# Servicegroups

define servicegroup {
	servicegroup_name	$customerShortName in AWS
	alias			$customerShortName Service Checks from AWS
	members			$serviceList
}





ENDOFTEXT;
	}				// End of Are there any services??






// =============================================================================================
function usage() {

	print "Usage: " . __FILE__ . " --appStack string --profile string [ --help | -h ]\n" ;

}
// =============================================================================================


print "# Completed OK at " . date("Y-m-d H:i:s") . "\n" ;
exit( 0 );
?>

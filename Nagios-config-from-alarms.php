#!/usr/bin/php
<?php

// =============================================================================================
// Nagios-config-from-alarms.php
//
// By Stefan Wuensch stefan_wuensch@harvard.edu 2014 - 2015 - 2016 - 2017
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
// 2016-10-19 - Added "Actual Alarm Evaluation Period" to the Service Notes - see comments above $evaluationPeriodMinutes
// 2016-10-31 - Added "AWS Account" to Host and Service Notes, and added AWS Console URL as notes_url on Host
// 2017-03-22 - Added "service" to the line 546 skipping notification, plus quotes around the service name
// 2017-08-23 - Remove quotes around word "host" line 418 to make the "Skipping" comments uniform everywhere
// 		Add property_exists() validation for $customerProfile
// 2017-11-01 - Add first pass at handling namespace AWS/ApplicationELB (ELBv2)
// 2017-11-22 - Remove slash "/" from Host and Service names for Nagios XI compatibility
// 		Update AppELB action_url to search for TargetGroups
// 		Add customerProfile to last-resort "constructed name" to avoid name collisions across AWS accounts
// 2017-12-12 - Replace "CloudFront" in template names with correct "CloudWatch"
// 		Add Instance Tag search for "Product" to try and make "siteID"
// 		Clarify message when skipping because EC2 Instance is no longer there
// 		Remove Host config skip for EC2 Instances; no longer doing separate automation for Instances
// 2017-12-13 - Change retry_interval from 15 to 25. No need to load the server so much when SNS has proven reliable.
// 2018-01-12 - Move the "running" filter into $filters var so it also prints in the "skipping" comments
// 		Add Namespace "System/Linux" to the exclusions for skipping an EC2 Instance config if it doesn't exist
// 2018-01-16 - Drop the "running" filter. It ought to be in the config file.
// 		Make the "--filters" CLI arg present only if there are filters found
// 2018-01-29 - Handle System/Linux Namespace which has multiple Dimensions[] and we have to find the one that's "InstanceId"
// 		Fix and improve Notes on Host & Service definitions, including more links to AWS Console and improved clarity
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

if ( ! property_exists( $configJSON->accountsByName, $customerProfile ) ) {
	print "Error: Can't find AWS account/profile \"$customerProfile\" in $configFile accountsByName\n" ;
	exit( STATE_UNKNOWN );
}

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

// $filters = "\"Name=instance-state-name,Values=running\" " ;		// Use this if we want to only process running instances
$filters = "" ;		// Starting with nothing because we're adding in the next loop, if any are in the config.
foreach( $configJSON->accountsByName->$customerProfile->$appStack->tagFilters as $filter ) {
	$filters .= "\"$filter\" " ;
}
if ( isset( $filters ) && $filters != "" ) {				// Only if there are some filters there...
	$awsReadEC2InstancesCommand .= " --filters " . $filters ;	// ...then we add the CLI option.
}

$EC2InstancesJSON = json_decode( shell_exec( $awsReadEC2InstancesCommand ) ) ;

// Check for getting something back!
if ( ! isset( $EC2InstancesJSON ) || $EC2InstancesJSON == "" ) {
	print "Error - no JSON data returned from \"$awsReadEC2InstancesCommand\" - could be a problem reaching the AWS API\n" ;
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

print "# Config input to this script was: " . $configFile . "\n\n" ;

print "# Arguments passed to generate this config: --profile=" . $customerProfile . " --appStack=" . $appStack . "\n\n";

$statsLeadingText = "Total number of" ;
print "# Note: Search this file for the string \"$statsLeadingText\" to see statistics on the number of objects.\n\n\n" ;




////////////////////////////////////////////////////////////////////////////////
// Templates
//
// NOTE: These two templates are importing the *active* check templates from
// the hosts.cfg and services.cfg

echo <<<ENDOFTEXT
###############################################################################
###############################################################################
# Templates

# Note: Template names are automatically generated, ending with "-$customerShortName" to differentiate from other AWS customers.
# "$defaultContactGroup" is a default, which is expected to be replaced by a value from "nagiosContactGroupAlarms" out of the site config file.

define host {
	name				aws-host-CloudWatch-Alarm-$customerShortName
	use				aws-host-active-check-$customerShortName
	contact_groups			$defaultContactGroup
	register			0
}


# Very lazy check intervals because we are relying on Passive check inputs for true Alarm conditions.
# The Active checking of services is just to fill in when we'd otherwise be waiting around for a Passive check.
define service {
	name				aws-service-CloudWatch-Alarm-$customerShortName
	use				aws-service-active-check-$customerShortName
	contact_groups			$defaultContactGroup
	check_command			check_AWS_CloudWatch_Alarm!$customerProfile
	notification_options		u,c,r,f,s
	check_interval			30
	retry_interval			25
	notification_interval		30
	max_check_attempts		1
	event_handler			submit_AWS_config_refresh!$nagiosMasterName!Nagios config in sync - $customerShortName AWS CloudWatch Alarms!\$SERVICESTATE:$nagiosMasterName:Nagios config in sync - $customerShortName AWS CloudWatch Alarms$!\$LASTSERVICECHECK:$nagiosMasterName:Nagios config in sync - $customerShortName AWS CloudWatch Alarms$!\$SERVICESTATE$!\$SERVICEATTEMPT$!\$MAXSERVICEATTEMPTS$
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
// 		print "# Found \$ec2Instance->InstanceId: " . $ec2Instance->InstanceId . "\n";	// Debugging output

		$siteID = "" ;
		$instanceName = "" ;

		foreach( $ec2Instance->Tags as $ec2InstanceTag ) {
			if ( $ec2InstanceTag->Key == "Product" || $ec2InstanceTag->Key == "product" ) {
				$siteID = $ec2InstanceTag->Value ;
			}
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
			print "# Skipping instance $instanceID named \"$instanceName\" - found no usable info in Tags!\n\n" ;
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
$skipHostNames = array() ;		// Init to null
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
				$webSiteName = $webSiteNameExploded[ 0 ] . ".constructed-name-" . $customerProfile ;	// As a last resort, make up something that will still work when we split it later.
			}
			$webSiteName = 	str_replace( "-", ".", $webSiteName ) ;
			$hostName = 	$webSiteName . ":" . $alarmInstance->Dimensions[ 0 ]->Value ;
			$hostName =	str_replace( "/", "_", $hostName ) ;	// 2017-11-22 for Nagios XI Config Prep Tool compatibility

			// If the Alarm is for an EC2 Instance (either of the two Namespaces) make sure that Instance ID actually exists!
			// This prevents Nagios config objects from being built for old stale Alarms. At one point in early 2018,
			// there were more than 2000 Alarms for Instances that did not exist!!
			// Note that this could be extended to any / all other Namespaces for which there are Alarms (ELB, ASG, RDS, etc.)
			// but as of 2018-01-29 the number of stale alarms for those types is not too great - and by building Nagios configs
			// for those (even if the Alarm target doesn't exist) allows the app owner to see the "INSUFFICIENT_DATA" state in Nagios.
			if ( $alarmInstance->Namespace == "AWS/EC2" || $alarmInstance->Namespace == "System/Linux" ) {

				$thisAlarmInstanceId = "" ;
				foreach( $alarmInstance->Dimensions as $Dimension ) {		// Reach into Dimensions[] and find the "Name": "InstanceId"
					if ( isset( $Dimension->Name ) && $Dimension->Name == "InstanceId" ) {
						$thisAlarmInstanceId = $Dimension->Value ;
// 						print "\$alarmInstance->AlarmName: " . $alarmInstance->AlarmName . "\n\$thisAlarmInstanceId: " . $thisAlarmInstanceId . "\n\n";		// Debugging output
						break ;
					}
				}

				// Re-generate the "Host" name from the Instance ID, which is NOT necessarily Dimensions[ 0 ]
				// Something similar *might* also have to be done for any Alarm for an AWS resource defined by multiple Dimensions. TBD as of 2018-01-29
				$hostName = 	$webSiteName . ":" . $thisAlarmInstanceId ;
				$hostName =	str_replace( "/", "_", $hostName ) ;	// 2017-11-22 for Nagios XI Config Prep Tool compatibility

				if ( ! isset( $allInstanceIDs[ $thisAlarmInstanceId ] ) ) {	// If we didn't find that InstanceId among all the Instances, it's bogus!
					print "\n# Skipping host \"$hostName\" (built from CloudWatch Alarms, namespace $alarmInstance->Namespace) because there is no EC2 instance with that ID found from the filter ( $filters) !!!\n" ;
					print "# This skipping means the CloudWatch Alarm \"$alarmInstance->AlarmName\" (MetricName $alarmInstance->MetricName) is stale and needs to be updated or removed!!\n\n\n" ;
					continue ;
				}
			}

			// Build the primary Hosts object, tracking the Namespace and what Dimension gave us the constructed Host Name
			$allHostNames[ $hostName ] = $alarmInstance->Namespace . ":" . $alarmInstance->Dimensions[ 0 ]->Name ;
			// ...except in the case of an EC2 Instance which could have multiple Dimensions as noted above.
			if ( $alarmInstance->Namespace == "AWS/EC2" || $alarmInstance->Namespace == "System/Linux" ) {
				$allHostNames[ $hostName ] = $alarmInstance->Namespace . ":InstanceId" ;
			}
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
print "###############################################################################\n" ;
print "# Hosts\n\n" ;

print "# $statsLeadingText websites: " . sizeof( $allSiteNames ) . "\n";
print "# $statsLeadingText AWS MetricAlarms: " . sizeof( $alarmsJSON->MetricAlarms ) . "\n";
print "# $statsLeadingText Nagios AWS \"Hosts\": " . sizeof( $allHostNames ) . "\n\n";
print "# Note: Host Groups added here are defined in hostgroups.cfg for use in other non-dynamic config files.\n\n\n";

foreach( $allHostNames as $hostName => $hostNameFrom ) {

	$skipHostNotInConfig = "" ;				// A flag for whether or not we have a matching config file entry. If not, we're not going to write a Nagios config for it.
	$nagiosContactGroupAlarms = $defaultContactGroup ;	// Need to set the default each time in case we don't find one in the applicationSites below
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
						$skipHostNotInConfig = "" ;	// If we did find it, make sure we don't skip over it on the output!
						$skipHostNames[ $hostName ] = "N" ;	// Flag it as to-be-skipped for later use outside these loops
						break ;
					} else {
						$skipHostNotInConfig = "Y" ;	// If it's not matched by any config entry, we shouldn't be generating a config for it.
						$skipHostNames[ $hostName ] = "Y" ;	// Flag it as to-be-skipped for later use outside these loops
					}
				}
			}
		}
	}

	if ( $skipHostNotInConfig == "Y" ) {
		print "# NOTE: Skipping host name \"$hostName\" for $customerShortName because it matched no \"nagiosContactGroupAlarms\" in the config file { \"" . $customerProfile . "\": { \"" . $appStack . "\" } } section.\n\n\n" ;
		continue ;
	}

	$hostList .= $hostName . ",";

	if ( $hostNameFrom == "AWS/EC2:InstanceId" || $hostNameFrom == "System/Linux:InstanceId" ) {
		print "# NOTE: \"$hostName\" is an EC2 Instance.\n" ;
// 2017-12-12 - No longer doing EC2 Instance config automation, so we will no longer skip.
// 		print "# NOTE: \"$hostName\" is an EC2 Instance, so its Host definition will be built elsewhere by a different script.\n# Skipping host \"$hostName\" here.\n\n\n" ;
// 		continue ;
	}

	echo <<<ENDOFTEXT
define host {
	use			aws-host-CloudWatch-Alarm-$customerShortName
	host_name		$hostName
	_AWS_Data		$shortSiteName:$hostNameFrom
	hostgroups		$customerShortName in AWS - All
	contact_groups		$nagiosContactGroupAlarms
	notes			Notes: AWS Account "<a href="https://$customerProfile.signin.aws.amazon.com/console">$customerProfile</a>". For links directly to the Alarms and to the $hostNameFrom, see the Action and Notes URLs on <a href="/nagios/cgi-bin/status.cgi?host=$hostName">each Nagios Service for this Host</a>.
	notes_url		https://$customerProfile.signin.aws.amazon.com/console
}




ENDOFTEXT;

}

// var_dump( $allHostNames ) ;	// Debugging output
// var_dump( $skipHostNames ) ;	// Debugging output
// var_dump( $hostToContactgroupMapping ) ;	// Debugging output

////////////////////////////////////////////////////////////////////////////////
// Services

$totalServices = 0 ;
$nagiosContactGroupAlarms = $defaultContactGroup ;	// Reset to the default since it was probably used in the Hosts section. This should be replaced per-site later.

print "###############################################################################\n" ;
print "# Services\n\n" ;

foreach( $alarmsJSON->MetricAlarms as $alarmInstance ) {	// Yes this loop is mighty similar to the one above which does "Hosts". To-Do: unify the two.

	foreach( $alarmInstance->AlarmActions as $alarmAction ) {
		if ( preg_match( "/nagios/i", $alarmAction ) ) {	// Only if it's a Nagios action!

			$webSiteNameExploded = explode( " ", $alarmInstance->AlarmName ) ;	// Break the Alarm Name by spaces into an array.
			if ( preg_match( "/[\.:-]/", $webSiteNameExploded[ 0 ] ) ) {		// If there is a period and/or colon and/or hyphen,
				$webSiteName = $webSiteNameExploded[ 0 ] ;			// then assume we have something in the first element like a FQDN or site name we can use.
			} elseif ( isset( $webSiteNameExploded[ 1 ] ) ) {			// If we don't have a colon or space in the first element, and the second element exists,
				$webSiteName = $webSiteNameExploded[ 0 ] . "." . $webSiteNameExploded[ 1 ] . "-constructed-name";	// then construct something from the first two words that we can later break apart.
			} else {
				$webSiteName = $webSiteNameExploded[ 0 ] . ".constructed-name-" . $customerProfile ;	// As a last resort, make up something that will still work when we split it later.
			}
			$webSiteName = 	str_replace( "-", ".", $webSiteName ) ;
			$hostName = 	$webSiteName . ":" . $alarmInstance->Dimensions[ 0 ]->Value ;
			$hostName =	str_replace( "/", "_", $hostName ) ;	// 2017-11-22 for Nagios XI Config Prep Tool compatibility

			$resourceID = 	$alarmInstance->Dimensions[ 0 ]->Value ;
			$serviceName = 	$alarmInstance->MetricName . ": " . $alarmInstance->AlarmName ;
			$serviceName =	str_replace( "/", "_", $serviceName ) ;	// 2017-11-22 for Nagios XI Config Prep Tool compatibility
			$primaryDimension = $alarmInstance->Dimensions[ 0 ]->Name ;

			// If the Alarm is for an EC2 Instance (either of the two Namespaces) make sure that Instance ID actually exists!
			// This prevents Nagios config objects from being built for old stale Alarms. At one point in early 2018,
			// there were more than 2000 Alarms for Instances that did not exist!!
			// Note that this could be extended to any / all other Namespaces for which there are Alarms (ELB, ASG, RDS, etc.)
			// but as of 2018-01-29 the number of stale alarms for those types is not too great - and by building Nagios configs
			// for those (even if the Alarm target doesn't exist) allows the app owner to see the "INSUFFICIENT_DATA" state in Nagios.
			if ( $alarmInstance->Namespace == "AWS/EC2" || $alarmInstance->Namespace == "System/Linux" ) {

				$thisAlarmInstanceId = "" ;
				foreach( $alarmInstance->Dimensions as $Dimension ) {		// Reach into Dimensions[] and find the "Name": "InstanceId"
					if ( isset( $Dimension->Name ) && $Dimension->Name == "InstanceId" ) {
						$thisAlarmInstanceId = $Dimension->Value ;
						$primaryDimension = "InstanceId" ;
// 						print "\$alarmInstance->AlarmName: " . $alarmInstance->AlarmName . "\n\$thisAlarmInstanceId: " . $thisAlarmInstanceId . "\n\n";		// Debugging output
						break ;
					}
				}

				// Re-generate the "Host" name from the Instance ID, which is NOT necessarily Dimensions[ 0 ]
				// Something similar *might* also have to be done for any Alarm for an AWS resource defined by multiple Dimensions. TBD as of 2018-01-29
				$hostName = 	$webSiteName . ":" . $thisAlarmInstanceId ;
				$hostName =	str_replace( "/", "_", $hostName ) ;	// 2017-11-22 for Nagios XI Config Prep Tool compatibility
				$resourceID = 	$thisAlarmInstanceId ;

				if ( ! isset( $allInstanceIDs[ $thisAlarmInstanceId ] ) ) {	// If we didn't find that InstanceId among all the Instances, it's bogus!
					print "\n# Skipping service \"$serviceName\" for AWS/EC2 Instance \"$hostName\" (built from CloudWatch Alarms, namespace $alarmInstance->Namespace) because there is no EC2 instance with that ID found from the filter ( $filters) !!!\n" ;
					print "# This skipping means the CloudWatch Alarm \"$alarmInstance->AlarmName\" (MetricName $alarmInstance->MetricName) is stale and needs to be updated or removed!!\n\n\n" ;
					continue ;
				}
			}

			if ( isset( $alarmInstance->AlarmDescription ) ) {
				$serviceExtInfo = $alarmInstance->AlarmDescription ;
			} else {
				$serviceExtInfo = "(No \"AlarmDescription\" found for this CloudWatch Alarm)" ;
			}
			$namespace =      $alarmInstance->Namespace ;

			switch ( $namespace ) {
				case "AWS/RDS" :
					$actionURL = $awsConsoleURLBase
							. "rds/home?region="
							. $region
							. "#dbinstances:id="
							. $resourceID ;
// 							. "%3Bsf=all" ;		// Disabled this 2016-08-08 - it seems to not be needed, and the urlencoded ';' doesn't seem to be decoded by Chrome
					break ;

				case "AWS/ELB" :
					$actionURL = $awsConsoleURLBase
							. "ec2/v2/home?region="
							. $region
							. "#LoadBalancers:search="
							. $resourceID ;
					break ;

				case "AWS/ApplicationELB" :
					$actionURL = $awsConsoleURLBase
							. "ec2/home?region="
							. $region
							. "#TargetGroups:search="
							. $resourceID ;
					break ;

				case "AWS/EC2" :
					$actionURL = $awsConsoleURLBase
							. "ec2/v2/home?region="
							. $region
							. "#Instances:search="
							. $resourceID ;
					break ;

				case "System/Linux" :
					$actionURL = $awsConsoleURLBase
							. "ec2/v2/home?region="
							. $region
							. "#Instances:search="
							. $resourceID ;
					break ;

				case "AWS/AutoScaling" :
					$actionURL = $awsConsoleURLBase
							. "ec2/autoscaling/home?region="
							. $region
							. "#AutoScalingGroups:id="
							. $resourceID ;
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
				print "# NOTE: Skipping service name \"$serviceName\" - Could not find \"nagiosContactGroupAlarms\" in config JSON for $hostName in $customerShortName \"applicationSites\".\n\n\n" ;
				continue ;
			}

			$serviceList .= $hostName . "," . $serviceName . ",";
			$totalServices++ ;

			// Added this 2016-10-19 to make it clear what the real evaluation period is... because
			// the CloudFormation Template is limited in what it can put in the AlarmDescription...
			// plus there was a bug in the template that assumed the Period was always 60 sec.
			$evaluationPeriodMinutes = "unknown" ;	// Just in case the next assignment fails for some reason.
			$evaluationPeriodMinutes = ( $alarmInstance->EvaluationPeriods * $alarmInstance->Period ) / 60 ;
			$evaluationPeriodMinutesUnit = "minute" ;
			if ( $evaluationPeriodMinutes != "unknown" && $evaluationPeriodMinutes >= 2 ) {
				$evaluationPeriodMinutesUnit .= "s" ;
			}

			echo <<<ENDOFTEXT
define service {
	use				aws-service-CloudWatch-Alarm-$customerShortName
	host_name			$hostName
	service_description		$serviceName
	_AWS_Data			$alarmInstance->AlarmName
	contact_groups			$nagiosContactGroupAlarms
	notes				Notes: $serviceExtInfo ($primaryDimension = <a href="$actionURL">$resourceID</a>, AlarmName = "<a href='$notesURL'>$alarmInstance->AlarmName</a>", Namespace = $namespace, MetricName = $alarmInstance->MetricName, Actual Alarm Evaluation Period = $evaluationPeriodMinutes $evaluationPeriodMinutesUnit, AWS Account = <a href="https://$customerProfile.signin.aws.amazon.com/console">$customerProfile</a>)
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
		if ( isset( $skipHostNames[ $hostName ] ) && $skipHostNames[ $hostName ] == "Y" ) {
			continue ;	// Skip if we didn't find it in the config file earlier.
		} else {
			$hostList .= $hostName . ",";
		}
	}

	if ( $hostList == "" ) {	// If there's nothing for this site, on to the next one.
		continue ;
	}

	$hostList = rtrim( $hostList, "," ) ; // Trim off the last comma

	echo <<<ENDOFTEXT
define hostgroup {
	hostgroup_name	$customerShortName in AWS - site $siteName
	alias		$siteName $customerLongName
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

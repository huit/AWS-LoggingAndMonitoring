#HPAC Nagios Alerts Installation Guide
================================================================================================================================================================

Welcome to the HPAC Nagios AWS Alerts Installation Guide.  This file contains all of the documentation for adding RDS Nagios Alerts for HPAC Amazon Web Services.

###Nagios Alerts for AWS Service Description

The following procedures need to be followed to add AWS CloudWatch alarms that will send sns alerts to Nagios that will notify the HUIT Operation Center personnel of a possible issue with the HPAC Drupal Web sites.

###Amazaon Web Services Account Name
cloudhacks

###Application
HPAC Drupal Websites currently hosted at Amazon Web Services.  The following site RDS installation will be monitored.

http://www.harvard.edu
http://campaign.harvard.edu
http://news.harvard.edu


###Amazon Web Service
RDS - Managed Relational Database Service

###AWS Event
RDS - Read Latency

###AWS SNS Topic Definition

In AWS, Topics are the in-point for a notification. When a metric crosses the defined threshold, the message is sent to the Topic.

A Topic has Subscriptions which are the destinations for the message. Subscriptions can be SMS, email, HTTP/HTTPS. At this time (Summer 2014) we recommend each critical-level Topic that sends to Nagios should have an additional email Subscription as a backup. (If a metric is simply a performance monitor, it can safely use a Topic with the Nagios subscription alone.)

For sending alarm notifications to production Nagios (as of Summer 2014) a Topic needs to have a Subscription which is HTTPS and with the following url:

```
https://nagios.fas.harvard.edu/aws_sns_receiver.php
```
Note: At this time a new Subscription to Nagios needs to be manually confirmed. This will no longer be necessary after the HUIT upgrade to Nagios 4 later in 2014, because that will allow auto-confirmation of subscriptions.

###Creating RDS Read Latency Alarm

In AWS Console, go to the CloudWatch Services.
https://console.aws.amazon.com/cloudwatch/home?region=us-east-1

![CloudWatch Alarms](https://raw.githubusercontent.com/stephenmartino/AWS-LoggingAndMonitoring/master/documentation/images/metricdefinition.png)
```
To set up monitoring of database (RDS) latency, click "RDS" in the left hand pane under the "Metrics" Category.
```

###Metric Searching
Search either for the RDS database name, or simply search for the metric. For example, searching for "latency" will return read and write latency for all RDS instances.

```
To set up monitoring of your database (RDS) "read latency", click once in the Search Metrics box and enter "latency" and click "Browse Metrics".
```

Click the checkbox to the left of your RDS Instance for "read latency" which you want to create the alarm.

At the bottom right of the graph panel that appears, click "Create Alarm". Note that the graph shows previous data for this metric which you can use to determine the threshold for this new alarm.

### Alarm Threshold

Give the new alarm a name that indicates as much information as possible, optimally including:

```
Field: Name(must be unique):  Application Name 
Convention: Application Name + Instance Type + Alarm Type
Example: HPAC Drupal RDS Read Latency Alarm
```
```
Field: Description
Convention: AWS Account Name|Customer Application|Amazon Service|Instance Name|Additional Notes and Comments 
Example: cloudhacks|HPAC Drupal|RDS|smartinodbinstance|This is only an example alarm by Stefan and Steve M.
```
```
Field: Whenever
Convention: Name of Metric Chosen
Example: ReadLatency
```
```
Field: is
Convention: >= OR <= OR > OR <
Example: >=

Convention: Numeric value that corresponds to the Alarm Preview Graph
Example: .003
```
```
Field: for
Convention: Numeric value that corresponds to the number of consecutive periods before triggering an alarm.
Example: 1
```

###Actions
Click on the +Notifications button to create the following Notifications.

###State is OK Notification

```
Field: Whenever this alarm
Convention: State is OK
Example: State is OK

Field: Send notification to
Convention: Name of Nagios List
Example: HUIT_Nagios_Critical

Field: Email list
Convention: email address is submitted by AWS for the subscription
Example: nagios-dev@fas.harvard.edu
```

####State is ALARM Notification

```
Field: Whenever this alarm
Convention: State is ALARM
Example: State is ALARM

Field: Send notification to
Convention: Name of Nagios List
Example: HUIT_Nagios_Critical

Field: Email list
Convention: email address is submitted by AWS for the subscription
Example: nagios-dev@fas.harvard.edu
```

###Alarm Preview

```
Field: Alarm Preview
Convention: A descriptive verse on the definition of the alarm that you are about to create.
Example: This alarm will trigger when the blue line goes up to or above the red line for a duration of 5 minutes

Field: Namespace
Convention: AWS/Service Name
Example: AWS/RDS

Field: DBInstance-Identifier
Convention: Name of RDS instance (DO NOT CHANGE)
Example: hpacdbinstance

Field: Metric Name
Convention: Name of the metric that was chosen
Example: ReadLatency

Field: Period
Convention: Time in minutes that the "Metric" was not met.
Example:  5 Minutes

Field: Statistic
Convention: Minimum OR  Maximum OR Sum OR Average OR Data Samples
Example: Average
```
###Sending Alarm Events via Nagios

In order for Nagios to be able to receive and process an alarm message from AWS SNS, a standard Nagios Host and Service configuration must each be created.

Nagios uses a Host definition as the base of all related Service attributes. For example a conventional server would be defined as a single Host, and the Services like disk usage, Apache, memory use, etc. would all be associated to the Host.

In AWS we no longer focus on the server as the "host" so we have to change the way we use the Nagios Host object.

We use the Description field of an AWS Alarm to associate that Alarm to the proper "Host" in Nagios. Because a particular metric (for example RDS ReadLatency) that makes up an Alarm will have its own name, the Nagios name of the Host is coded in the Alarm Description as bar-delimited data:
AWS account name|Host name for Nagios|Amazon service|instance name|additional notes and comments

For example, this is the Description for an Alarm on the ReadLatency metric:
cloudhacks|HPAC Drupal|RDS|smartinodbinstance|This is only an example alarm by Stefan and Steve M. - nothing serious

When Nagios accepts the Alarm message from SNS, this Description data will be used to change the Nagios state for the "Host" named 'HPAC Drupal'.

As we further develop Nagios alerting for AWS applications, we will be using the additional data fields in the Description to allow routing of the alert notifications.

###hostgroups.cfg additions

```
================== hostgroups.cfg ========================

define hostgroup {
	hostgroup_name	aws tests
	alias		Test targets for developing AWS connector
	members		kwp490-ELB-Alarm,awselb-TestLoggi-ElasticL-151NCQZ5JP9OP-High-Average-Latency,HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564
}


define hostgroup {
	hostgroup_name	HPAC in AWS
	alias		Harvard Publishing and Communications applications in the Amazon cloud
	members		HPAC Drupal RDS Read Latency Alarm,HPAC Drupal RDS Write Latency Alarm,HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564
}

```

###services.cfg additions
```
================ services.cfg ==============================

define service {
	name				aws-service
	use				generic-service
	contact_groups			aws-group
	max_check_attempts		1
	register			0
}


define service {
	use				aws-service
	host_name			HPAC Drupal RDS Read Latency Alarm
	service_description		ReadLatency
	check_command			FAKE-host-alive
	check_period			none
	passive_checks_enabled		1
}

define service {
	use				aws-service
	host_name			HPAC Drupal RDS Write Latency Alarm
	service_description		WriteLatency
	check_command			FAKE-host-alive
	check_period			none
	passive_checks_enabled		1
}


define service {
	use				test-service
	host_name			HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564
	service_description		HTTP
	check_command			check_http
}

define service {
	use				test-service
	host_name			HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564
	service_description		HTTP content scrape
	check_command			check_http_url_scrape!80!/!"Welcome to Drupal"
}
define serviceextinfo {
	host_name			HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564
	service_description		HTTP content scrape
	action_url			http://hpac-drup-elasticl-1wmwzlxku4evx-1226981564.us-east-1.elb.amazonaws.com/
	notes				Health check via scrape for "Welcome to Drupal" in the returned HTML.
}

```

###hosts.cfg additions
```
========================= hosts.cfg ===============================



define host {
	use			test-host
	host_name		HPAC Drupal RDS Read Latency Alarm
	alias			HPAC Drupal RDS Read Latency Alarm
	check_command		FAKE-host-alive
	check_period		none
	passive_checks_enabled	1
}

define host {
	use			test-host
	host_name		HPAC Drupal RDS Write Latency Alarm
	alias			HPAC Drupal RDS Write Latency Alarm
	check_command		FAKE-host-alive
	check_period		none
	passive_checks_enabled	1
}


define host {
	use			test-host
	host_name		HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564
	alias			HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564.us-east-1.elb.amazonaws.com
	address			HPAC-Drup-ElasticL-1WMWZLXKU4EVX-1226981564.us-east-1.elb.amazonaws.com
	check_command		FAKE-host-alive
}

```
###contacts.cfg additions
```
===================== contacts.cfg =================================

define contact {
	contact_name			openview-HPAC
	alias				ITO SOC Operations OpenView - Notify Amazon Web Services
	email				None
	address1			HPAC
	service_notification_period	24x7
	host_notification_period	24x7
	service_notification_options	w,u,c,r
	host_notification_options	d,u,r
	service_notification_commands	notify-openview-service
	host_notification_commands	notify-openview-host
}


define contact {
	contact_name			stephen_martino
	alias				Stephen Martino
	email				stephen_martino@harvard.edu
	pager				None
	service_notification_period	24x7
	host_notification_period	24x7
	service_notification_options	c,r,u
	host_notification_options	d,r
	service_notification_commands	notify-by-email
	host_notification_commands	host-notify-by-email
}

```
###contactgroups.cfg additions
```
================== contactgroups.cfg ====================================


define contactgroup {
	contactgroup_name	aws-group
	alias			Amazon Web Services Contact Group
	members			openview-HPAC,stephen_martino,wuensch
}

```

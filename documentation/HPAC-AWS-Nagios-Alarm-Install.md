#HPAC Nagios Alerts Installation Guide
======================

Welcome to the HPAC Nagios AWS Alerts Installation Guide.  This file contains all of the documentation for adding Nagios Alerts for HPAC Amazon Web Services.

###Nagios Alerts for AWS Service Description

The following procedures need to be followed to add AWS CloudWatch alarms that will send sns alerts to Nagios that will notify the HUIT Operation Center personnel of a possible issue with the HPAC Drupal Web sites.

###Account
cloudhacks

###Application
HPAC Drupal


###AWS Service
RDS - Managed Relational Database Service



###AWS Event
RDS - Read Latency

###AWS SNS Topics ====

In AWS, Topics are the in-point for a notification. When a metric crosses the defined threshold, the message is sent to the Topic.

```
https://console.aws.amazon.com/sns/home?region=us-east-1#
```

A Topic has Subscriptions which are the destinations for the message. Subscriptions can be SMS, email, HTTP/HTTPS. At this time (Summer 2014) we recommend each critical-level Topic that sends to Nagios should have an additional email Subscription as a backup. (If a metric is simply a performance monitor, it can safely use a Topic with the Nagios subscription alone.)

For sending alarm notifications to production Nagios (as of Summer 2014) a Topic needs to have a Subscription which is HTTPS and with the following URL: https://nagios.fas.harvard.edu/aws_sns_receiver.php

Note: At this time a new Subscription to Nagios needs to be manually confirmed. This will no longer be necessary after the HUIT upgrade to Nagios 4 later in 2014, because that will allow auto-confirmation of subscriptions.

###Creating RDS Read Latency Alarm

In AWS Console, go to the CloudWatch Services.
https://console.aws.amazon.com/cloudwatch/home?region=us-east-1

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

#* the name of the metric being watched for this alarm

# Enter the criteria for the threshold

# Add Actions:

## Choose the notification list ("Topic") from the pop-up list for the ALARM state

## Click "+Notification" and add the same notification list for "State is OK"


==== Sending Alarm Events via Nagios ====

In order for Nagios to be able to receive and process an alarm message from AWS SNS, a standard Nagios Host and Service configuration must each be created.

Nagios uses a Host definition as the base of all related Service attributes. For example a conventional server would be defined as a single Host, and the Services like disk usage, Apache, memory use, etc. would all be associated to the Host.

In AWS we no longer focus on the server as the "host" so we have to change the way we use the Nagios Host object.

We use the Description field of an AWS Alarm to associate that Alarm to the proper "Host" in Nagios. Because a particular metric (for example RDS ReadLatency) that makes up an Alarm will have its own name, the Nagios name of the Host is coded in the Alarm Description as bar-delimited data:
AWS account name|Host name for Nagios|Amazon service|instance name|additional notes and comments

For example, this is the Description for an Alarm on the ReadLatency metric:
cloudhacks|HPAC Drupal|RDS|smartinodbinstance|This is only an example alarm by Stefan and Steve M. - nothing serious

When Nagios accepts the Alarm message from SNS, this Description data will be used to change the Nagios state for the "Host" named 'HPAC Drupal'.

As we further develop Nagios alerting for AWS applications, we will be using the additional data fields in the Description to allow routing of the alert notifications.

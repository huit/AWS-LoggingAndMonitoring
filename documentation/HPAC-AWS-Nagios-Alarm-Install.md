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
```
In AWS Console, go to CloudWatch: URL: https://console.aws.amazon.com/cloudwatch/home?region=us-east-1
```
```
Click [URL: https://console.aws.amazon.com/cloudwatch/home?region=us-east-1#metrics: Browse Metrics]
```
```
To set up monitoring of database (RDS) latency, click "RDS Metrics".
```

# Search either for the RDS database name, or simply search for the metric. For example, searching for "latency" will return read and write latency for all RDS instances.

# Click the checkbox for the metric for which you want to create the alarm.

# At the bottom right of the graph panel that appears, click "Create Alarm". Note that the graph shows previous data for this metric which you can use to determine the threshold for this new alarm.

# Give the new alarm a name that indicates as much information as possible, optimally including:

#* stack name

#* database name

#* the name of the metric being watched for this alarm

# Enter the criteria for the threshold

# Add Actions:

## Choose the notification list ("Topic") from the pop-up list for the ALARM state

## Click "+Notification" and add the same notification list for "State is OK"

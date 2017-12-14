#!/usr/bin/env bash

# aws-automation-diff-dev.sh / aws-automation-diff-prod.sh
# 
# by Stefan Wuensch, January 2016
# 
# This script is part of the AWS CloudWatch and Nagios integration suite.
# This is designed to be used for validation of the automation config files.
# 
# The existing on-disk Nagios config files /usr/local/nagios/etc/aws/*alarm*
# are parsed for the "--profile=" and "--appStack=" arguments from the 
# previous run of the automation scripts. Those two args are then used to
# call the "Nagios-config-from-alarms" script (which generates a new Nagios
# config file on-the-fly to STDOUT) and that dynamic output is compared
# to the on-disk config files via 'diff'.
# 
# The output from this script is designed to be human-readable. The idea
# is that this script (with "-dev" in the name) will be run after making 
# changes to the "AWS_config-dev.json" file. If the 'diff' output looks
# good, the same changes will then be applied to "AWS_config.json" which
# makes them live in production for the AWS/Nagios automation to use.
# 
# The version of this script with "-prod" in the name acts the same, 
# except it compares the on-disk configs with what is generated from the
# production config file "AWS_config.json". See the assignment of the
# $CONFIG variable below.
# 
############################################################################
# NOTE: This script is expected to be named "aws-automation-diff-dev.sh"
# and a sym-link "aws-automation-diff-prod.sh" should point at it.
# 
# Example:
# -rwxrwxr-x. 1 root unixadm 2325 Jan 12 16:09 /usr/local/bin/aws-automation-diff-dev.sh
# lrwxrwxrwx. 1 root root      26 Jan 10 13:51 /usr/local/bin/aws-automation-diff-prod.sh -> aws-automation-diff-dev.sh
# 
# This allows one script to be called by two different names, with each name
# invoking different behavior. (This is an alternative to having to use a
# command-line argument to select dev or prod when running this script.)
# The use of "basename $0" below is where this logic is applied.
############################################################################

export PATH=/usr/local/bin:/bin:/usr/bin


RUNASUSER="nagios"
[[ "$( /usr/bin/whoami )" != "${RUNASUSER}" ]] &&
	echo "This script must be run as user ${RUNASUSER}, for example:" &&
	echo "  sudo -u ${RUNASUSER} $0" &&
	exit 1



FILES="$( ls /usr/local/nagios/etc/aws/*alarm* | sort )"

[[ $# -eq 1 ]] && FILES="${1}"	# if we get an arg, use it for the config file name

if [[ -n "$( basename $0 | grep -i dev )" ]] ; then
	DEV="Y"
	SCRIPT="/usr/local/nagios/libexec/FAS/Nagios-config-from-alarms-dev.php"
	CONFIG="AWS_config-dev.json"
else
	DEV="N"
	SCRIPT="/usr/local/nagios/libexec/FAS/Nagios-config-from-alarms.php"
	CONFIG="AWS_config.json"
fi



echo -e "\nStarting $0 at $( date )"
echo -e "\nChecking config \"${CONFIG}\" via \"$( basename ${SCRIPT} )\" for each of the following files:"
echo -e "${FILES}" | xargs -n1 basename | sort

echo -ne "\n\nNote: You can ignore any comment lines of the 'diff' output which differ only by "
[[ "${DEV}" == "Y" ]] && echo -n "the string \"-dev\" or "
echo -e "the date/time stamp."
[[ $# -eq 0 ]] && sleep 2	# if we're operating on all the files, give the person a sec. to review the list


for file in ${FILES} ; do
	echo -e "\n\n\n\n=============== $file ======================= "
	echo "#" ${SCRIPT} $( grep "Arguments passed to generate this config" $file | cut -d: -f2 ) \| diff $file -
 	${SCRIPT} $( grep "Arguments passed to generate this config" $file | cut -d: -f2 ) | diff $file -
done

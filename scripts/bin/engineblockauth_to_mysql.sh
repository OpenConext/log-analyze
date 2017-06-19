#!/bin/bash
# This script parses the authentication logs that are provided by Engineblock syslogs
# The ebauth messages should be place in the UNPROCESSED_DIR
# The filename of the ebauth message should start with ebauth-
#
# Some variables
UNPROCESSED_DIR="/var/log/openconextconext/log_logins/unprocessed/"
WORK_DIR="/var/log/openconext/log_logins/work/"
PROCESSED_DIR="/var/log/openconext/log_logins/work/processed"
DB_HOST="DB_HOST"
DB_USER="DB_USER"
DB_PASS="DB_PASSWORD"
DB_NAME="DB_NAME"
JQ_BINAY="/usr/bin/jq"

if test -n "$(find $UNPROCESSED_DIR -maxdepth 1 -name 'ebauth-*' -print -quit)"
then
    mv "$UNPROCESSED_DIR"ebauth-* $WORK_DIR
else
    exit
fi

for LOGFILE in $WORK_DIR*
do
cat $LOGFILE  | awk -F ]: '{ print $2 }' | sed s'/"key_id":null/"key_id":"null"/g' |$JQ_BINARY -r '.context + .extra |  [.login_stamp, .user_id, .sp_entity_id, .idp_entity_id, .key_id, .session_id, .request_id  ]  | @csv' |  sed 's/^/INSERT INTO log_logins(loginstamp,userid,spentityid,idpentityid,keyid,sessionid,requestid) VALUES (/g' | sed 's/$/) ON DUPLICATE KEY UPDATE id=id;/g' | mysql -u $DB_USER -h $DB_HOST -p$DB_PASS $DB_NAME
mv $LOGFILE $PROCESSED_DIR
done


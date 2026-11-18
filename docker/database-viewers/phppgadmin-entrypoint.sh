#!/bin/sh
set -eu
umask 077
mkdir -p /tmp/phppgadmin-sessions /tmp/apache2
chmod 700 /tmp/phppgadmin-sessions /tmp/apache2
exec apache2 -DFOREGROUND

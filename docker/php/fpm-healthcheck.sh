#!/bin/sh
set -eu

SCRIPT_NAME=/ping \
SCRIPT_FILENAME=/ping \
REQUEST_METHOD=GET \
REQUEST_URI=/ping \
cgi-fcgi -bind -connect 127.0.0.1:9000 \
    | tr -d '\r' \
    | grep -qx pong

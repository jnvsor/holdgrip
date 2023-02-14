#!/bin/sh

env >> /etc/environment

exec "$@"

#!/bin/sh
set -eu

umask 077

[ -n "${REDIS_PASSWORD:-}" ] || {
    printf 'redis: REDIS_PASSWORD is required.\n' >&2
    exit 1
}

configuration_file=/tmp/nexasalud-redis.conf

{
    printf '%s\n' 'bind 0.0.0.0'
    printf '%s\n' 'protected-mode yes'
    printf '%s\n' 'port 6379'
    printf '%s\n' 'appendonly yes'
    printf '%s\n' 'appendfsync everysec'
    printf '%s\n' 'maxmemory-policy noeviction'
    printf '%s\n' 'dir /data'
    printf 'requirepass %s\n' "${REDIS_PASSWORD}"
} >"${configuration_file}"

chmod 600 "${configuration_file}"
exec redis-server "${configuration_file}"

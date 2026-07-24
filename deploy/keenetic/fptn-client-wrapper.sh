#!/bin/sh
if [ -f /opt/etc/fptn-client.conf ]; then
    . /opt/etc/fptn-client.conf
fi
if [ "$ENABLED" != "yes" ] || [ -z "$TOKEN" ]; then
    exit 0
fi

TUN_NAME="${TUN_INTERFACE:-opkgtun11}"
ARGS="--access-token $TOKEN --tun-interface-name $TUN_NAME --disable-routing"

if [ -n "$PREFERRED_SERVER" ]; then
    ARGS="$ARGS --preferred-server $PREFERRED_SERVER"
fi

/opt/bin/fptn-client-cli $ARGS &
CLI_PID=$!

(
    sleep 2
    ip addr add 10.0.0.2/24 dev "$TUN_NAME" 2>/dev/null || true
    ip link set "$TUN_NAME" up 2>/dev/null || true
) &

wait $CLI_PID

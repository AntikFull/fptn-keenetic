#!/bin/sh
if [ -f /opt/etc/fptn-client.conf ]; then
    . /opt/etc/fptn-client.conf
fi

if [ "$ENABLED" = "no" ] || [ "$ENABLED" = "'no'" ] || [ -z "$TOKEN" ]; then
    exit 0
fi

TUN_NAME="${TUN_INTERFACE:-opkgtun11}"
ARGS="--access-token $TOKEN --tun-interface-name $TUN_NAME --disable-routing"

if [ -n "$PREFERRED_SERVER" ]; then
    ARGS="$ARGS --preferred-server $PREFERRED_SERVER"
fi

/opt/bin/fptn-client-cli $ARGS &
CLI_PID=$!

sleep 3

# Динамически вытягиваем выданный сервером IP
ASSIGNED_IP=$(grep -oE 'Received IP assignment from server: IPv4=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)' /var/log/fptn/fptn-client-cli.log 2>/dev/null | tail -n 1 | cut -d'=' -f2)

if [ -n "$ASSIGNED_IP" ]; then
    ip addr flush dev "$TUN_NAME" 2>/dev/null || true
    ip addr add "${ASSIGNED_IP}/16" dev "$TUN_NAME" 2>/dev/null || true
else
    ip addr flush dev "$TUN_NAME" 2>/dev/null || true
    ip addr add 172.20.0.2/16 dev "$TUN_NAME" 2>/dev/null || true
fi

ip link set "$TUN_NAME" up 2>/dev/null || true

iptables -t nat -D POSTROUTING -o "$TUN_NAME" -j MASQUERADE 2>/dev/null || true
iptables -t nat -I POSTROUTING 1 -o "$TUN_NAME" -j MASQUERADE
iptables -D FORWARD -o "$TUN_NAME" -j ACCEPT 2>/dev/null || true
iptables -I FORWARD 1 -o "$TUN_NAME" -j ACCEPT
iptables -D FORWARD -i "$TUN_NAME" -j ACCEPT 2>/dev/null || true
iptables -I FORWARD 1 -i "$TUN_NAME" -j ACCEPT

iptables -t mangle -D FORWARD -o "$TUN_NAME" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true
iptables -t mangle -I FORWARD 1 -o "$TUN_NAME" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
iptables -t mangle -D FORWARD -i "$TUN_NAME" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true
iptables -t mangle -I FORWARD 1 -i "$TUN_NAME" -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu

wait $CLI_PID

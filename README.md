# FPTN for Keenetic Routers (Entware)

A specialized fork of the FPTN VPN technology, focused on deploying and running the client on home Keenetic routers with Entware.

FPTN is a VPN technology engineered from the ground up to provide secure, robust, and censorship-resistant connections capable of bypassing Deep Packet Inspection (DPI) and network filtering. This repository contains an interactive installer and a web control panel for managing the client directly on your router.

---

## Fast Installation

Connect to your Keenetic router via SSH (you must have Entware installed and configured).

### 🚀 Standard Installation:
Download the installer script to a temporary file and run it (this is required so the shell interactive input works correctly):
```bash
curl -fsSL -o /tmp/install.sh https://raw.githubusercontent.com/AntikFull/fptn-keenetic/master/deploy/keenetic/install.sh && sh /tmp/install.sh
```

### ⚡ Installation Mirror (for restricted networks / Russia):
If GitHub is blocked or connection to `raw.githubusercontent.com` hangs on your router, use the fast mirror via jsDelivr CDN:
```bash
curl -fsSL -o /tmp/install.sh https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/deploy/keenetic/install.sh && sh /tmp/install.sh
```
*(Or via `wget` if `curl` is missing on your router:)*
```bash
wget -O /tmp/install.sh https://cdn.jsdelivr.net/gh/AntikFull/fptn-keenetic@master/deploy/keenetic/install.sh && sh /tmp/install.sh
```

### What the installer does:
1. **Installs packages:** Installs required Entware packages (`lighttpd`, `php8-cgi` for the web interface, `curl`, and certificates).
2. **Detects processor architecture:** Automatically detects your hardware platform (`aarch64`, `armv7`, or `mipsel`) and downloads the appropriate static FPTN client binary.
3. **Prompts for parameters:** Prompts you for the interface name, port for the web panel (default `8088`), and your subscription token.
4. **Creates and configures a TUN interface in KeeneticOS:** Automatically registers an interface of type `OpkgTun` with `ip global` flags and TCP MSS adjustment so the router treats the tunnel as a working internet connection.
5. **Configures autostart:** Registers the startup service in `/opt/etc/init.d/S53fptn-client`.

---

## Web Control Panel Usage

After the installation is complete, open the web panel in your browser:
```
http://192.168.1.1:8088/fptn/
```
*(If you selected a different port during installation, use it instead of `8088`)*.

In the web panel, you can:
* Save/update your FPTN subscription token.
* View the list of available premium servers.
* Select a preferred server or keep autoselect enabled.
* Start, stop, and restart the VPN client service.
* Monitor the service and TUN interface status.

---

## Routing Configuration in Keenetic

You can route traffic of your home devices into the FPTN tunnel using standard KeeneticOS features:

1. **Device Routing (Policies):**
   In the router's web interface, navigate to the **"Network Rules" -> "Connection Priorities"** section. A new connection named **Fptn** will appear in the connection list. Drag it into the desired policy (e.g., create a separate policy for bypassing blocks and add your devices there).

2. **Domain DNS Routing:**
   In the **"Network Rules" -> "Routing"** (or "DNS Routes") section, you can bind specific domain groups directly to the **Fptn** interface (e.g., `OpkgTun1`).

Detailed instructions on manual configuration and routing are available in the [deploy/keenetic/README.md](deploy/keenetic/README.md) file.

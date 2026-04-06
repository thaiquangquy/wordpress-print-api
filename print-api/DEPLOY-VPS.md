# Print API — Deploy to VPS (ISPConfig)

## Prerequisites

- SSH access to the VPS
- `rsync` installed locally (WSL2) and on the VPS
- The plugin source at `/home/quy/workspace/upwork/wordpress-plugin/print-api/`

---

## Method 1 — `deploy-vps.sh` (recommended)

### 1. Configure the script

Open `deploy-vps.sh` and set your SSH credentials:

```sh
VPS_USER="web13"               # SSH user — see "Finding your SSH username" below
VPS_HOST="YOUR_VPS_IP_OR_DOMAIN"
VPS_PORT="22"                  # change if your VPS uses a non-standard SSH port
```

The remote path is already set to match your ISPConfig web root:

```
/var/www/clients/client2/web13/web/wp-content/plugins/print-api
```

### 2. Run

```sh
cd /home/quy/workspace/upwork/wordpress-plugin/print-api
./deploy-vps.sh
```

Or pass values inline without editing the file:

```sh
VPS_USER=web13 VPS_HOST=203.0.113.10 ./deploy-vps.sh
```

### 3. Activate the plugin (first deploy only)

Go to **WP Admin → Plugins → Installed Plugins**, find **Print API**, and click **Activate**.

This runs the activation hook which creates `wp-content/uploads/private/books/` and writes the `.htaccess` that blocks direct HTTP access to the PDFs.

Subsequent deploys (`./deploy-vps.sh` again) update the files in place — no re-activation needed.

---

## Method 2 — Upload ZIP via WP Admin

Use this if SSH/rsync is unavailable.

### 1. Build the ZIP

```sh
cd /home/quy/workspace/upwork/wordpress-plugin
zip -r print-api.zip print-api/ \
  --exclude "*.git*" --exclude "*.md" --exclude "*.sh"
```

### 2. Upload

**WP Admin → Plugins → Add New Plugin → Upload Plugin**

Choose `print-api.zip`, click **Install Now**, then **Activate Plugin**.

---

## Finding your SSH username (ISPConfig)

The SSH user for a web is usually one of:

- `web13` — the web system user (most common)
- `client2` — the client user

Check in **ISPConfig → Sites → Select web13 → Options tab** — look for the **Linux user** field.

Verify from your terminal:

```sh
ssh YOUR_USER@YOUR_VPS_IP "ls /var/www/clients/client2/web13/web/wp-content/plugins/"
```

---

## Setting up SSH key auth (skip password prompts)

```sh
# generate a key if you don't have one
ssh-keygen -t ed25519 -C "deploy"

# copy the public key to the VPS
ssh-copy-id -p 22 YOUR_USER@YOUR_VPS_IP
```

After this, `deploy-vps.sh` runs without any password prompt.

---

## Verify the deploy

After deploying, confirm the files landed correctly:

```sh
ssh YOUR_USER@YOUR_VPS_IP \
  "ls /var/www/clients/client2/web13/web/wp-content/plugins/print-api/"
```

Expected output:

```
assets/
includes/
print-api.php
deploy-vps.sh   ← excluded by rsync, so this won't appear
```

To confirm the plugin is active and the REST endpoints are reachable:

```sh
curl -s https://YOUR_SITE/wp-json/print-api/v1/nonce
# → {"nonce":"..."}
```

# Tiranga Car World Hostinger Deploy

This project now deploys to the new Hostinger account and main domain:

- Domain: `tirangacarworld.com`
- SSH host: `147.93.109.162`
- SSH port: `65002`
- SSH user: `u892049228`
- Web root: `/home/u892049228/domains/tirangacarworld.com/public_html`
- Database name: `u892049228_tirangamaindb`
- Database user: `u892049228_tirangamaindb`

Do not commit SSH passwords, database passwords, or `config/database.local.php`.

## 1. GitHub deploy key on Hostinger

The deploy script makes Hostinger pull the code from:

```text
git@github.com:ipoindiaa/carmela.git
```

On Hostinger SSH, create a deploy key:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/github_carmela_deploy -C "hostinger-tiranga-deploy"
cat ~/.ssh/github_carmela_deploy.pub
```

Copy the printed public key.

In GitHub:

1. Open `ipoindiaa/carmela`.
2. Go to `Settings -> Deploy keys -> Add deploy key`.
3. Title: `Hostinger tirangacarworld.com`.
4. Paste the public key.
5. Leave `Allow write access` unchecked.
6. Save.

Test from Hostinger SSH:

```bash
GIT_SSH_COMMAND='ssh -F /dev/null -i ~/.ssh/github_carmela_deploy -o StrictHostKeyChecking=no' \
git ls-remote git@github.com:ipoindiaa/carmela.git main
```

## 2. Deploy from local machine

Run this from the project root:

```bash
DEPLOY_PASSWORD='YOUR_HOSTINGER_SSH_PASSWORD' \
DEPLOY_DB_PASS='YOUR_DATABASE_PASSWORD' \
./.deploy-hostinger.sh "Fresh deploy to tirangacarworld.com"
```

The script will:

1. Commit local changes.
2. Push `main` to GitHub.
3. SSH into Hostinger.
4. Clone or reset the site in `public_html`.
5. Write `config/database.local.php` on Hostinger using the DB env vars.

## 3. Fresh database setup

For a new empty production database:

1. Open Hostinger phpMyAdmin.
2. Select `u892049228_tirangamaindb`.
3. Import `database/schema.sql`.
4. Open `https://tirangacarworld.com/setup.php`.
5. Create the business and first admin account.
6. Confirm login at `https://tirangacarworld.com/login.php`.

After setup is complete, block public access to `setup.php` before sharing the site.

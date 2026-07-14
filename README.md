# Tiranga Car World

## Isolated Testing Environment

The testing environment uses the local MySQL database `autobooks_pro_testing` and never connects to the Hostinger production database.

```bash
# Recreate the test database with demo records
./scripts/testing-env.sh reset

# Start the testing site
./scripts/testing-env.sh start
```

Open `http://127.0.0.1:8788` and sign in with:

- Email: `tester@tirangacarworld.test`
- Password: `Testing@123`

Useful commands:

```bash
./scripts/testing-env.sh status       # Show database and login details
./scripts/testing-env.sh reset empty  # Fresh schema without demo records
./scripts/testing-env.sh fresh        # Reset with demo records and start the site
```

`APP_ENV=testing` includes a database-name safety guard: the application refuses to start unless the selected database name contains `test`. Testing pages also show a visible TEST badge and `[TEST]` in the browser title.

The production Hostinger deployment remains configured through `config/database.local.php` and is not read by the testing environment.

### Hostinger Staging

The public staging site uses `test.tirangacarworld.com`, an independent Hostinger database, and the isolated document root `public_html/test`.

```bash
DEPLOY_DB_NAME='hostinger_test_database' \
DEPLOY_DB_USER='hostinger_test_user' \
DEPLOY_DB_PASS='test_database_password' \
./scripts/deploy-testing-hostinger.sh
```

The staging deploy writes ignored `config/environment.local.php` and `config/database.testing.local.php` files on Hostinger. It never reads or writes the production database configuration.

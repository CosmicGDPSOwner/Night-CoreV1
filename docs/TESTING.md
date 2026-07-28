# Testing Night Core V1

Use a disposable database. Never use the live GDPS database for early core tests.

## Easiest method: Docker Desktop

Requirements: Docker Desktop with Docker Compose.

From the repository folder run:

```bash
docker compose up --build -d
docker compose exec web php bin/nightcore migrate
docker compose exec web php bin/nightcore doctor
docker compose exec web php bin/nightcore self-test
```

Expected results:

- `migrate` applies `0001_accounts.sql` on the first run;
- `doctor` reports database/accounts/users as `OK`;
- `self-test` prints `Night Core self-test: OK`;
- `http://127.0.0.1:8080/health.php` returns `ok`;
- `http://127.0.0.1:8080/info.php` returns non-secret installation information.

## Test account registration

PowerShell:

```powershell
Invoke-WebRequest `
  -Method Post `
  -Uri "http://127.0.0.1:8080/accounts/registerGJAccount.php" `
  -Body @{ userName="CoreTest"; password="Test12345"; email="test@example.invalid" }
```

The response body should be:

```text
1
```

Running the same registration again should return `-2` because the username already exists.

## Test login

```powershell
Invoke-WebRequest `
  -Method Post `
  -Uri "http://127.0.0.1:8080/accounts/loginGJAccount.php" `
  -Body @{ userName="CoreTest"; password="Test12345"; udid="local-test" }
```

A successful login returns two numeric IDs separated by a comma, for example:

```text
1,1
```

A wrong password should return `-1`.

## Stop and reset

Stop containers:

```bash
docker compose down
```

Delete the disposable database too:

```bash
docker compose down -v
```

## Existing Cvolton-compatible database

For an existing server, first restore a database backup into a separate test database. Configure `.env`, then run:

```bash
php bin/nightcore doctor
```

Do not run migrations against production until the copied database passes compatibility tests.

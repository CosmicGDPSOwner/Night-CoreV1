# Dashboard account panel

`/mediaAdmin.php` remains a public song and SFX library. Uploading files requires a GDPS account.

The top-right **Sign in / Register** button opens a modal with two tabs:

- sign in with an existing GDPS account;
- register a normal GDPS account.

Registration uses the same `AccountService`, password hashing, `ACCOUNT_PREACTIVATE` setting and account table as the game-facing `registerGJAccount.php` endpoint. Registration attempts are limited by HMAC identifiers for the exact IP and subnet; raw IP addresses are not stored.

Recommended production settings:

```env
REGISTRATION_MAX_PER_IP=2
REGISTRATION_MAX_PER_SUBNET=10
REGISTRATION_WINDOW_SECONDS=86400
REGISTRATION_IP_HASH_KEY=long_random_secret
```

After successful registration, a preactivated account receives a protected dashboard session immediately. When `ACCOUNT_PREACTIVATE=0`, the account is created but cannot sign in until it is activated.

# Night Core V1

Night Core V1 is the standalone server core for **NightGDPS**.

The project is being built as a clean, compatibility-first replacement for the current hosted GDPS backend. It uses the protocol behavior and implementation experience of [Cvolton/GMDprivateServer](https://github.com/Cvolton/GMDprivateServer) as its upstream reference while moving NightGDPS-specific behavior into a smaller, documented codebase that we fully control.

## Goals

- Keep Geometry Dash 2.2-compatible server endpoints.
- Separate protocol endpoints from business logic.
- Keep database changes explicit through migrations.
- Centralize authentication, permissions and database access.
- Preserve NightGDPS features such as Events, moderator commands and Creator Points.
- Avoid hosting-panel-specific code and hidden dependencies.
- Never store production credentials or secrets in Git.

## Upstream baseline

Initial reference: `Cvolton/GMDprivateServer` at commit `719dfe36c622a54c8162b07967241fce79b2497c`.

Night Core V1 is a modified/derived project and keeps the upstream GPLv3 licensing requirements. See `LICENSE` and `docs/UPSTREAM.md`.

## Status

Early bootstrap. The first milestone is a minimal core that can connect to a test database and expose a health endpoint before Geometry Dash endpoints are migrated one group at a time.

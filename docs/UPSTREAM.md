# Upstream and licensing

Night Core V1 uses **Cvolton/GMDprivateServer** as its compatibility and implementation reference.

Initial pinned upstream revision:

`719dfe36c622a54c8162b07967241fce79b2497c`

Repository: `Cvolton/GMDprivateServer`

The upstream project is licensed under the GNU General Public License version 3. Night Core V1 must preserve the applicable GPL terms for code derived from or copied from that project.

## Policy for imported code

- Record the upstream file/revision when code is copied or substantially adapted.
- Mark Night Core changes clearly when practical.
- Do not remove upstream copyright/license notices.
- Keep NightGDPS-specific modules separated where possible.
- Never copy production secrets, hosting-panel credentials or private configuration into this repository.

The goal is not to claim authorship of the original Cvolton implementation. The goal is to build and maintain a NightGDPS-specific core on top of a clearly documented upstream foundation.

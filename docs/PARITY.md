# svnmanage ⇄ iF.SVNAdmin feature parity

svnmanage is a ground-up rewrite of [iF.SVNAdmin](https://github.com/gomoblaqo-faris/iF.SVNAdmin)
on PHP 8.1+, htmx and Tailwind CSS. It reads and writes **the same files** (`config.ini`,
`userroleassignments.ini`, the SVN `authz` file, `htpasswd` / `htdigest`), so it can be
pointed at an existing iF.SVNAdmin `data/` directory.

Legend: ✅ ported · ➕ new in svnmanage · 🔧 behaviour fixed/hardened

## Authentication & session
| Capability | Status |
|---|---|
| Login with user provider (htpasswd, htdigest, LDAP, AD) | ✅ |
| Login requires the `basics/login` ACL right | ✅ |
| Logout | ✅ (POST + CSRF 🔧) |
| `AuthenticationStatus=off` (no login, everyone is admin) | ✅ with warning banner ➕; localhost only 🔧 |
| HTTP proxy auth (`HttpProxyAuth`: `REMOTE_USER`, `X-Forwarded-User`) | ✅ 🔧 (original read the wrong `$_SERVER` key) |
| Session fixation protection, idle timeout | ➕ |
| Login brute-force throttling | ➕ |
| CSRF protection on every state change | ➕ |
| Two-factor authentication (TOTP, QR enrolment, 10 recovery codes, policy off/optional/admins/all, admin + CLI reset) | ➕ |
| Network allow-list (`AllowedNetworks`), trusted reverse proxies (`TrustedProxies`) | ➕ |
| Require HTTPS (`ForceHttps`), `__Host-` cookie, HSTS, absolute session lifetime, sessions ended on password/2FA change | ➕ |
| `AuthenticationStatus=off` limited to localhost (or `SVNMANAGE_ALLOW_NOAUTH=1`) | ➕ |

## First start / setup
| Capability | Status |
|---|---|
| Copy config template on first run, redirect to settings | ✅ |
| Settings reachable without login while no admin is defined | ✅ 🔧 (only after entering the one-time setup key from `data/setup.key`; an unreadable role file no longer re-opens setup) |
| "Set admin": pick existing user as Administrator | ✅ |
| Create default admin when user file is empty | ✅ 🔧 (you choose the password instead of `admin/admin`) |

## Dashboard
| Capability | Status |
|---|---|
| Welcome, app version, PHP version, logged-in user, roles of user | ✅ |
| Counts (repositories, users, groups, access paths), svn version, provider status, quick actions | ➕ |

## Repositories (`svnclient` provider)
| Capability | Status |
|---|---|
| List repositories, grouped by multiple parent paths (`[Repositories:svnclient:N]`) | ✅ |
| Create repository (name, fs-type) | ✅ (fsfs / fsx / bdb) |
| …optionally create the root access path `repo:/` | ✅ |
| …optional structure: none / single (`trunk,branches,tags`) / multi (`project/trunk…`) | ✅ |
| Delete repositories (gated by `GUI.RepositoryDeleteEnabled`) | ✅ 🔧 (path confinement) |
| …optionally delete the repository's access paths | ✅ |
| Dump repository download (gated by `GUI.RepositoryDumpEnabled`) | ✅ |
| Load a dump file into a repository (stub in original) | ➕ |
| Browse repository tree (name, type, revision, author, date) | ✅ (+ size) |
| Links to Apache directory listing / custom browser (`%1`, `%2` placeholders) | ✅ |
| Create access path from the browser (prefilled) | ✅ |
| Repository details: youngest revision, UUID, last change, recent log, file preview | ➕ |

## Users
| Capability | Status |
|---|---|
| List users | ✅ + live search, pagination ➕ |
| `*` everyone pseudo-user | ✅ granted as **Everyone** on access paths (+ `$authenticated`, `$anonymous` ➕) |
| Create user (htpasswd / htdigest) | ✅ (bcrypt/apr1/sha1 hashing, verify all htpasswd formats) |
| Delete users (bulk) — cascades groups, access paths, roles, project-manager assignments | ✅ |
| Bulk-assign a web role from the list | ✅ |
| User details: groups, access-path permissions (direct, via group, via `*`), roles, managed paths | ✅ (+ nested-group resolution 🔧) |
| Assign/unassign groups, roles, permissions, project-manager paths from the user page | ✅ |
| Change own password / other users' password (`changepassother`) | ✅ |
| Admin role can only be (un)assigned by holders of `assignadmin` / `unassignadmin` | ✅ |

## Groups (`svnauthfile` / LDAP / AD)
| Capability | Status |
|---|---|
| List / create / delete groups (bulk) | ✅ |
| Delete cascades: remove from parent groups and all access paths | ✅ |
| Group details: user members, sub-groups, access paths | ✅ (+ "member of" parents ➕) |
| Add/remove users and sub-groups on the group page | ✅ |
| Memberships page: bulk add users/sub-groups to groups | ✅ |
| Rename group (updates every reference) | ➕ |

## Access paths (`svnauthfile`)
| Capability | Status |
|---|---|
| List access paths with their project managers | ✅ + search ➕ |
| Project managers only see/manage paths under their assignment | ✅ 🔧 (prefix check no longer matches `repo:/ab` for `repo:/a`) |
| Create access path (`repo:/path` or `/`) | ✅ |
| Delete access paths (bulk), cascades project-manager assignments | ✅ |
| Path details: users and groups with permission; grant / revoke | ✅ |
| Bulk assign project managers to paths | ✅ |
| Permissions page: bulk grant users/groups × paths × (none / r / rw) | ✅ |
| Preserves comments, aliases, `$authenticated`, `~` rules in authz | ➕ |

## Roles / ACL
| Capability | Status |
|---|---|
| Built-in roles: User, User-Group-Manager, Access-Path-Manager, Repository-Creator, Repository-Manager, Role-Manager, Update-Manager, Administrator | ✅ (same inheritance) |
| Implicit Project-Manager role | ✅ |
| Role list with descriptions | ✅ (+ members ➕) |
| Role assignments stored in `userroleassignments.ini` (compatible format) | ✅ |

## Update / synchronisation
| Capability | Status |
|---|---|
| Sync LDAP/AD groups & members into the authz file | ✅ |
| `Update:ldap.AutoRemoveUsers` / `AutoRemoveGroups` cleanup with report | ✅ |
| LDAP user cache (`Ldap.CacheEnabled`, `CacheFile`) | ✅ |
| `GUI.AllowUpdateByGui` | ✅ |
| CLI `update` mode | ✅ (`bin/svnmanage update`) |

## Settings
| Capability | Status |
|---|---|
| Provider selection (user view/edit, group view/edit, repository view/edit) | ✅ (+ access-path & auth ➕) |
| Paths: authz, htpasswd, htdigest + realm, SVNParentPath, svn / svnadmin executables | ✅ |
| LDAP connection, users, groups settings | ✅ |
| AD domain, controllers, bind user, group filter, strict mode | ✅ (native ext-ldap, no adLDAP dependency) |
| Test buttons: files writable, folder writable, executables, LDAP connection/users/groups, AD DC detection/connection | ✅ |
| GUI toggles (delete, dump, update by GUI, directory listing URLs), default locale, hashing | ➕ in UI |

## Misc
| Capability | Status |
|---|---|
| Translations: en, de, es, ja, pt_BR, uk_UA, zh_CN + locale switcher | ✅ |
| Error pages: no access, inactive module | ✅ (+ 404/500 ➕) |
| CLI: `update`, `license` | ✅ (+ `check`, `set-admin`, `passwd`, `version` ➕) |
| Automatic timestamped backups before every file write | ✅ (`Common.BackupFolder`, now actually used) |
| Activity log of every change | ➕ |
| Mobile-first responsive UI, sidebar navigation, htmx partial updates | ➕ |

## Verification

`composer test` runs three suites:

- **Unit:** the INI and authz models, password hashing (checked against `htpasswd -B/-m/-s`
  output), the ACL, the directory sync, the CLI, and translation catalogue completeness and
  placeholder integrity for all 6 locales.
- **Integration:** creates real repositories with `svnadmin` in temporary folders, then
  covers layout, browse, log, dump, load and path-traversal rejection.
- **HTTP:** drives the kernel in-process. It covers the setup wizard, login, open-redirect
  and CSRF rejection, rate limiting, role enforcement, project-manager scoping, cascading
  deletes, the last-admin guard, settings, backups, the audit log, and a render of every page.
- **Security regressions:** each finding of the security review has a test that reproduced
  it before the fix. That covers INI/authz newline injection (forged `[user] Administrator=`
  and `[/] * = rw` sections), admin-account takeover by delegated managers, project managers
  reading other repositories, open redirects through control characters, the setup key gate,
  error-detail leaks, proxy-auth names and login rights, file permissions, and throttling of
  the "current password" check.
- **Public exposure** (`PublicExposureTest`, `TwoFactorTest`, `RateLimiterTest`,
  `LoginTimingTest`, `NetworkTest`, `TotpTest`, `QrCodeTest`): spoofed `X-Forwarded-For`,
  per-address throttling, user-name probing by timing, oversized input, session lifetime and
  revocation, allow-lists, forced HTTPS, settings that would run programs or write PHP,
  remote access with authentication off, 2FA enrolment, replay, throttling, expiry, recovery
  codes and resets.


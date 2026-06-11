# Changelog — Mambers

## 0.2.27 — 2026-06-11 (MBR-2.9 post edit + rich bio)

### Added

- **Post edit** — Edit button on your posts · inline rich editor · visibility · PATCH `/activity/{id}` · link preview refreshes from body URL
- **Rich bio** — TipTap-class toolbar + emoji picker on profile Edit tab · `profile_bio_html` stored sanitized · rich display on feed tab

### Added (shared)

- **`mambers-rich-editor.js`** — shared toolbar + emoji for composer, bio, and post edit

## 0.2.25 — 2026-06-11 (MBR-2.8.4 OG link cards + share URL)

### Changed

- **Link preview cards** — full-width OG image on top, title + description + hostname below (FB-style)
- **Share / permalink** — always copies absolute URL with `https://yoursite/...` (fixes path-only Grav `base_url` in dev)
- **OG meta** — post pages emit `og:url`; link previews include `site_name` from hostname

## 0.2.24 — 2026-06-11 (MBR-2.8.3 image compression)

### Added

- **Server-side image compression** — GD resize (max edge 2048px) + JPEG recompress; PNG/WebP without alpha → JPG; keeps alpha PNGs as PNG
- **Ingest vs stored caps** — upload up to 15 MB per image when compression on; stored target 5 MB (configurable)
- **Admin** — `activity_image_compress_enabled`, `activity_upload_max_mb`, `activity_image_max_edge_px`, `activity_image_jpeg_quality`

## 0.2.23 — 2026-06-11 (MBR-2.8.2 upload UX)

### Added

- **Pre-upload media checks** — file list with sizes; red warnings when over limit, wrong type, or too many files; Post disabled until fixed
- **Live upload meter** — progress bar + % during multipart post (XHR upload progress)
- **Limits hint** — composer shows max image/video MB and counts from site config

## 0.2.22 — 2026-06-11 (MBR-2.8.1 media upload + profile tabs)

### Fixed

- **Media posts via API** — Grav API bridge now forwards PSR-7 multipart uploads; activity `storeUploads` accepts bridge temp files (fixes “Posting…” hang / silent drop)
- **Upload errors** — clear messages for oversize, partial, and rejected file types

### Added

- **Profile tabs** — Feed (default) vs Links when member has link-in-bio + activity feed
- **Giphy fallback** — uses Messenger `giphy_api_key` when Mambers key is empty
- **Asset cache bust** — `?v=` on activity JS/CSS

## 0.2.21 — 2026-06-07 (MBR-2.8 social composer + reactions)

### Added

- **Rich composer** — TipTap-class toolbar (bold/italic/underline/link), emoji picker, optional Giphy GIF attach
- **FB-style links** — paste URL in post body → live OG preview card (no separate link field); server auto-unfurls first URL
- **Emoji reactions** — 👍❤️😂😮😢😠 on posts · `POST /activity/{id}/react`
- **Share** — copy permalink to clipboard
- **API** — `GET /giphy/search?q=` (proxied when `activity_giphy_api_key` set)

## 0.2.20 — 2026-06-05 (MBR-2.7 social graph + FB feed UI)

### Added

- **Social graph** — follow / unfollow / block; JSONL edges under `user/data/mambers/graph/`; follower & following counts on profiles
- **API** — `GET graph/stats|followers|following/{user}`, `POST|DELETE graph/follow|block/{user}`, `GET /feed?scope=following`
- **FB-style feed UI** — image grid layouts (1–4+), lightbox modal viewer, inline video (`mp4`/`webm` up to 10MB), OG link cards in composer + posts
- **Village feed tabs** — All vs Following for logged-in members; compose from `/members/feed`

### Fixed

- **Activity API URL** — templates no longer double-prefix `/api/v1/api/…`; `MudMambersConfig::apiUrl()` normalizes legacy `api_route` values

## 0.2.19 — 2026-06-11 (MBR-2.5 activity feed)

### Added

- **Native activity feed** — JSONL posts under `user/data/mambers/activity/`, profile Activity tab, site `/members/feed`
- **API** — `GET /feed`, `GET /activity/{username}`, `POST /activity`, `PATCH|DELETE /activity/{id}`, `POST /link-preview`
- **Composer** — text, images (Lite caps), link OG preview cards, visibility (`public` / `members` / `private`)
- **Fences** — `:::mambers-feed`, `:::mambers-activity{user="damo"}`
- **Per-post share URLs** — `/members/{user}/post/{id}` with media/link OG meta

## 0.2.18 — 2026-06-11 (Grav 2 RC5 user fields)

### Fixed

- **`Call to undefined method User::username()`** on `/members/*` when logged in — Grav 2 RC5 exposes identity via `get('username')`, not Grav 1 `username()` methods; `method_exists()` was a false positive on session users
- **`MudMambersGravUser`** — shared `username` / `fullname` / `displayName` / `avatar` helpers for profiles, directory cache, and Mambers API

## 0.2.17 — 2026-06-10 (MUD fences)

### Added

- **`MudMambersFences`** — embed Mambers in `.mud` pages via `onMudFenceRender` (same pattern as Forumz)
- Fence types: `::: mambers` / `mambers-directory`, `::: mambers-featured`, `::: mambers-profile` (+ optional `forumz="true"` feed), `::: mambers-login`, `::: mambers-register`, `::: mambers-auth`
- Client hydration: `assets/mud-mambers-fences.js` + `mud-mambers-fences.css` (auto-enqueued when a fence renders)

## 0.2.16 — 2026-06-05 (inline register form)

### Fixed

- **`/user_register` 400 — `forms/form.html.twig` not defined** — register auth skin now uses inline form (same pattern as login); Form plugin hidden fields + Mambers label strings

## 0.2.15 — 2026-06-05 (auth route links + claim redirect)

### Fixed

- **Create account on `/members` reloads directory** — empty `href` when Login `getRoute('register')` was null (broken/missing `user_registration.enabled`); Mambers now forces Login register routes and exposes `mambers_register_url` / `mambers_login_url` with `/user_register` fallback
- **Claim your profile → login with no return** — `/members/me` now sets `session.redirect_after_login` before sending guests to login so post-login lands on profile edit

## 0.2.14 — 2026-06-05 (auth label strings)

### Fixed

- **Login form shows raw `PLUGIN_LOGIN.*` keys** — Mambers auth skin now uses `languages/en.yaml` (`PLUGIN_MAMBERS.AUTH_*`) instead of Login plugin translation keys that are not loaded in Mambers Twig context

## 0.2.13 — 2026-06-05 (self-contained login form)

### Fixed

- **`partials/login-form.html.twig` still not defined** after 0.2.12 — 0.2.12 only mutated `twig_paths` after the Twig loader was built (no-op); login skin now ships an inline form in `mambers-auth/login.html.twig` with no Login-plugin partial dependency; Form/Login paths register in `onTwigTemplatePaths` + `onTwigLoader`

## 0.2.12 — 2026-06-05 (login form twig paths)

### Fixed

- **`Template "partials/login-form.html.twig" is not defined`** on Mambers auth skin — prepend Login + Form plugin template paths before rendering `mambers-auth/login.html.twig`

## 0.2.11 — 2026-06-05 (login form bootstrap)

### Fixed

- **`/login` shows tagline only, no form** — physical auth pages missing `template: login` (or slim plugin-only deploy) now get Login plugin page stubs with forms; Mambers auth skin applies by route and re-applies after Login plugin sets its template

## 0.2.10 — 2026-06-05 (auth class import hotfix)

### Fixed

- **`Class "Grav\Plugin\MudMambersAuth" not found`** on `/login` — `use Grav\Plugin\Mambers\MudMambersAuth` in `mambers.php` (plugin class lives in `Grav\Plugin` namespace)

## 0.2.9 — 2026-06-05 (login route bootstrap)

### Fixed

- **`/login` + `/user_register` 404** on slim GetGRAV deploys — Mambers now registers Login virtual pages when Login plugin path bootstrap misses; gravfans package includes physical auth pages + keeps `login`/`form`/`api` in deploy zip

## 0.2.8 — 2026-06-05 (virtual route user hotfix)

### Fixed

- **`/members/me` 500** — `Identifier "user" is not defined` when Login's `$grav['user']` service was not yet available on early virtual-route handling; `MudMambersSession::user()` resolves session/guest safely
- **Virtual route Twig context** — profile pages set `user` + `uri` before `onTwigSiteVariables`; login partials and logout links work on Mambers shell routes

## 0.2.7 — 2026-06-05 (GPM review #4119)

### Security

- **Path traversal** — cover/avatar serve routes only read files from the member media directory; removed free-text `profile_cover` Admin2 field (upload-only)
- **CSRF** — profile save, cover upload, and avatar upload require Grav nonce on router forms and API writes (`nonce` POST field or `X-Members-Nonce` header)
- **MIME hardening** — unknown image extensions on cover/avatar routes return 404 instead of `application/octet-stream`

### Fixed

- **API bridge identity** — `/api/v1/mud-mambers` resolves session/JWT/API-key user via `SessionAuthenticator` and `setApiUser()` (same pattern as Messenger)
- **Login config rewrite** — Mambers no longer overwrites global Login redirect settings every request unless `sync_login_redirects: true`
- **Directory performance** — 60s TTL cache for member listing; busted on profile save/upload

### Changed

- Dropped legacy `grav-mud-mambers` config slug fallback
- Neutral GPM defaults — empty `linkz_cta_url`; site config can set campaign CTA
- Removed legacy non-API route fallback (`api/mud-mambers` direct hit); Grav API plugin required

## 0.2.6 — 2026-06-09 (virtual route output shell)

### Fixed

- **Messenger float bubble on Mambers pages** — virtual routes (`/members`, profiles, etc.) bypass Grav's `onOutputGenerated`; `MudMambersTheme::finalizeHtml()` now appends Messenger (GetGRAV `goggrav-messenger.js` on mud_site, or standard launcher + assets elsewhere)
- Pattern applies to any plugin that hooks output generation — Mambers responses now get the same site shell tail as normal Grav pages

## 0.2.5 — 2026-06-09 (directory guest CTA)

### Added

- **Guest CTA on `/members`** — when logged out, shows Create account · Log in · Claim your profile links
- Register link only when Login plugin registration is enabled

### Changed

- Empty directory copy — guests nudged to sign up; logged-in members told to enable **Show in public directory**

## 0.2.4 — 2026-06-09 (profile hero + theme shell)

### Added

- **GetGRAV theme shell** — directory, profile, not-found, **login**, and **register** use the **active theme layout** (`default.html.twig` → header/footer), not a Mambers-only island
- Theme resolver falls back `default.html.twig` → `partials/base.html.twig` → plugin layout (Quark, grav-mud-site, GetGRAV all supported)
- GetGRAV `gg-foot` only when `grav_mud_goggrav.mudSite` is on; standard themes use their own footer
- **FB/Twitter-style profile hero** — cover + avatar + name embedded in one banner with bottom gradient
- **Cover & avatar edit buttons** on the hero — open upload modals (no more inline file forms)
- **Avatar upload** — `POST /members/{username}/avatar`, served at `/members/avatar/{username}`
- **Log out** link on own profile (`/members/me`)
- Centred campaign footer (`gg-foot`) on Mambers pages

### Changed

- Directory cards use mini cover + overlapping avatar
- Profile CSS uses GetGRAV `--gg-*` tokens when present
- Mambers router hydrates standard Grav Twig context (`pages`, `page`, `onTwigSiteVariables`) on virtual routes
- Campaign footer (`gg-foot`) only on mud_site GetGRAV installs

## 0.2.3 — 2026-06-09 (registration + directory fix)

### Fixed

- **Member directory empty** — account scan + permission check reads stored `access.site.member` (not session-only `authorize()`)
- **Post-login redirect** → `/members/me` (own profile + edit form), not bare directory
- **Post-registration** → `/login` (no auto-login) when `login_after_registration: false`

## 0.2.2 — 2026-06-09 (auth skin)

### Added

- **Mambers auth skin** — dark glass card for Login `/user_register` + `/login` (replaces 2005 default form on dark themes)
- Config toggle `auth_skin` (default on)

## 0.2.1 — 2026-06-09 (hotfix)

### Fixed

- **PSR-4 router boot** — `MudMambersRouter` namespace on profile routes (same class of bug as Messenger 0.3.2)
- **Profile Twig timing** — register templates on `onTwigLoader` (Grav 2 builds loader before `onTwigInitialized`)
- **404 fallback** — `onPageNotFound` delegates to profile router like Eventz

## 0.2.0 — 2026-06-09 (MBR-2 · profiles)

### Added

- **Member profiles** at `/members/{username}` with bio + link-in-bio (Lite max 5 links)
- **Member directory** at `/members` with search + pagination
- **Profile cover image** — one upload = page banner + **`og:image`** social share card
- `/members/me` redirect for logged-in members
- Public API: `GET /members`, `GET /profile/{username}`, `GET|PATCH /profile`, `POST /profile/cover`
- Admin2 account fields: `profile_public`, `profile_bio`, `profile_cover`, `profile_links`
- PSR-4 autoload (production-safe boot like Messenger 0.3.2)

## 0.1.0 — 2026-06-09 (MBR-1 · GPM)

### Added

- Plugin skeleton (`mambers` slug, legacy `grav-mud-mambers` config fallback)
- `site.member` permission registration via `permissions.yaml`
- Login `onUserLoginRegisterData` — default tier + member access on signup
- Pro expiry check on `onUserLoginAuthorize` when `edition: pro`
- Page gating via `access.site.member`, `login.member`, `login.visibility: member`, `member_only_routes`
- Public API `/api/v1/mud-mambers/whoami`
- Admin2 account blueprint fields (`member_tier`, `member_since`; Pro fields when edition pro)
- Demo page pattern documented in README

### Roadmap

- MBR-3: Members sidebar, pending queue, audit log (Pro)
- MBR-4–7: Forumz / Stripe / Shop bridges · profile theming (Pro)

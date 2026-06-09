# Changelog — Mambers

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

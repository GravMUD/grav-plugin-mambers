# Changelog — Mambers

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

- MBR-2: tier editor polish in Admin2 Users
- MBR-3: Members sidebar, pending queue, audit log (Pro)
- MBR-4–6: Forumz / Stripe / Shop bridges (Pro) · Messenger nick lock ships Lite via Messenger plugin

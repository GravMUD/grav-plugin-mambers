# Mambers

**Site:** [mambers.gravmud.site](https://mambers.gravmud.site) · **Repo:** [GravMUD/grav-plugin-mambers](https://github.com/GravMUD/grav-plugin-mambers)

**Membership on Grav** — tiers, gates, one identity across community plugins. Extends Login + Admin2 accounts; **no second user database**.

> *Grav already has users. Mambers gives sites **members**.*

**SKU:** `mambers` · tiers **`lite`** | **`pro`**  
**License:** Lite = MIT forever

---

## Requirements

| Package | Version |
|---------|---------|
| [Grav](https://github.com/getgrav/grav) | `>=2.0.0` |
| [Login](https://github.com/getgrav/grav-plugin-login) | `>=1.7.0` |
| [Admin2](https://github.com/getgrav/grav-plugin-admin2) | `>=1.0.0` |
| [API](https://github.com/getgrav/grav-plugin-api) | `>=1.0.0` |

Optional: **messenger** (Lite+ nick lock — auto when Mambers + Messenger both enabled) · **forumz**, **shop** (Pro)

---

## Installation

```bash
bin/gpm direct-install https://github.com/GravMUD/grav-plugin-mambers/releases/download/0.1.0/grav-plugin-mambers.zip
bin/grav cache
```

Once listed in GPM:

```bash
bin/gpm install mambers
bin/grav cache
```

Enable **Login** registration + **Mambers** in Admin2 → Plugins.

Legacy config key `plugins.grav-mud-mambers` is still read if `plugins.mambers` is empty.

---

## Mambers Lite (v0.2)

- Everything in v0.1, plus **member profiles + directory**
- `/members` · `/members/{username}` · `/members/me`
- Link-in-bio (≤5 links on Lite) · **cover image = OG share card**
- `GET /api/v1/mud-mambers/members` · `GET|PATCH /api/v1/mud-mambers/profile`

## Mambers Lite (v0.1)

- Registers `site.member`, `site.member.pro`, `site.member.moderator` permissions
- On Login registration → grants default tier + `site.member`
- Page gating via frontmatter `access.site.member: true`
- Shorthand `login.member: true` or `login.visibility: member`
- Public API `GET /api/v1/mud-mambers/whoami`
- Admin2 account fields `member_tier`, `member_since` (Pro adds expiry + notes)

### v0.2 roadmap (MBR-2)

- **Member profiles** + opt-in **directory**
- **Link-in-bio** (≤5 links on Lite)
- **Profile cover image** — one upload = page banner **and** social **`og:image`** when the profile link is shared
- Pro adds full profile theming (presets, unlimited links, flair)

---

## Lite vs Pro

| | **Lite** (default) | **Pro** (roadmap) |
|---|-------------------|-------------------|
| Signup → `site.member` | ✅ | ✅ |
| Page / route gating | ✅ | ✅ |
| Admin2 Users fields | ✅ | ✅ |
| Member profiles + directory | ✅ (0.2) | ✅ |
| Link-in-bio | ✅ ≤5 links (0.2) | unlimited |
| Cover image → OG share card | ✅ one image, two jobs (0.2) | ✅ |
| Profile theming | default layout only | presets + custom CSS |
| Members sidebar + pending queue | — | ✅ |
| Stripe tier webhooks | — | ✅ |
| Messenger bridge (nick lock) | ✅ | + mod from `site.member.moderator` |
| Forumz / Shop bridges | — | ✅ |

Set `edition: pro` in config for Pro-only fields and expiry checks. License keys ship with The Mud Bazaar later.

---

## Configuration

`user/config/plugins/mambers.yaml`:

```yaml
enabled: true
edition: lite
public_registration: true
default_tier: basic
api_route: api/mud-mambers
member_only_routes: []
```

**Login** must have `user_registration.enabled: true` for auto member grants.

---

## Gate a page

```yaml
---
title: Members Lounge
access:
  site.member: true
login:
  visibility: private
---
```

---

## API

| Route | Auth | Purpose |
|-------|------|---------|
| `GET /api/v1/mud-mambers/whoami` | session | username, tier, permissions |
| `GET /api/v1/mud-mambers/members` | public | Directory listing (`?search=&page=&limit=`) |
| `GET /api/v1/mud-mambers/profile/{username}` | public | Public profile JSON |
| `GET /api/v1/mud-mambers/profile` | session | Own profile |
| `PATCH /api/v1/mud-mambers/profile` | session | Update bio, links, visibility |
| `POST /api/v1/mud-mambers/profile/cover` | session | Upload cover image |

**Front-end:** `/members` · `/members/{username}` · `/members/me`

Legacy fallback: `GET /api/mud-mambers/whoami` when API bridge is disabled.

---

## Related

- [GRAVMUD-MAMBERS.md](https://github.com/GravMUD/GRAV-MUD/blob/main/Docs/GRAVMUD-MAMBERS.md) — full spec
- **Shop** — builds after Mambers Lite (customer accounts)

**Maintainer:** FutureVision Labs · Team DC

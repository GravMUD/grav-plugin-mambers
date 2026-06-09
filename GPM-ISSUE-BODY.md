I would like to add my new plugin to the Grav Repository.

**Repository:** https://github.com/GravMUD/grav-plugin-mambers
**Release:** https://github.com/GravMUD/grav-plugin-mambers/releases/tag/0.1.0
**Direct install:** https://github.com/GravMUD/grav-plugin-mambers/releases/download/0.1.0/grav-plugin-mambers.zip
**Plugin name:** Mambers
**Plugin slug:** mambers
**License:** MIT (Lite edition)
**Grav target:** Grav 2.0 / Admin2 / Login
**Site / docs:** https://mambers.gravmud.site
**Discussions:** https://github.com/GravMUD/grav-plugin-mambers/discussions

---

## Summary

**Mambers** extends Grav Login with **member tiers and content gating** — one identity for community plugins, no fork of Grav users. Lite (MIT) grants `site.member` on registration, gates pages, exposes `/api/v1/mud-mambers/whoami`, and injects member fields into Admin2 user accounts. Pro (commercial roadmap) adds Members panel, Stripe webhooks, and Messenger/Forumz/Shop bridges.

Not a membership platform fork — a layer on `user/accounts/*.yaml` + Login events.

---

## Dependencies

- grav >= 2.0.0
- login >= 1.7.0
- admin2 >= 1.0.0
- api >= 1.0.0

Optional (Pro bridges): forumz, shop · Messenger bridge is Lite+ (handled by messenger when Mambers is installed)

---

## Suggested maintainer test plan (~10 min)

```bash
bin/gpm direct-install https://github.com/GravMUD/grav-plugin-mambers/releases/download/0.1.0/grav-plugin-mambers.zip
bin/grav cache
```

1. Enable **Login** with `user_registration.enabled: true`.
2. Enable **Mambers** in Admin2 → Plugins.
3. Register a test user → confirm `access.site.member: true` on account YAML.
4. Create a page with `access.site.member: true` → anonymous denied, member allowed.
5. `GET /api/v1/mud-mambers/whoami` returns tier + permissions when logged in.

---

## GPM checklist

- [x] MIT LICENSE
- [x] README.md with install + Lite/Pro tiers
- [x] blueprints.yaml (semver **0.1.0**, slug **mambers**)
- [x] CHANGELOG.md (Grav format)
- [x] GitHub release zip attached
- [x] Docs / promo page at `docs/` (GitHub Pages)

---

## Notes

- Legacy config key `grav-mud-mambers` read if `mambers` config empty.
- Pro features are config-gated (`edition: pro`); license validation with The Mud Bazaar later.
- Required before **Shop** plugin (customer accounts).

Maintainer: FutureVision Labs · Team DC · chief@gravmud.site

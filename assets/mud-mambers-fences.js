(function () {
  "use strict";

  var API = window.GRAVMUD_MAMBERS_API || "/api/v1/mud-mambers";

  function esc(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/"/g, "&quot;");
  }

  function getApi(root) {
    return root.getAttribute("data-api") || API;
  }

  function fetchJson(url) {
    return fetch(url, { credentials: "same-origin" }).then(function (r) {
      return r.json();
    });
  }

  function memberCard(member) {
    var cover = member.cover
      ? ' style="background-image:url(' + esc(member.cover) + ')"'
      : "";
    var bio = member.bio_excerpt
      ? '<p class="mambers-card-bio">' + esc(member.bio_excerpt) + "</p>"
      : "";

    return (
      '<a class="mambers-card" href="' +
      esc(member.profile_url) +
      '">' +
      '<div class="mambers-card-hero">' +
      '<div class="mambers-card-cover' +
      (member.cover ? "" : " mambers-card-cover--empty") +
      '"' +
      cover +
      '><div class="mambers-card-gradient" aria-hidden="true"></div></div>' +
      '<img class="mambers-card-avatar" src="' +
      esc(member.avatar) +
      '" alt="" width="64" height="64" loading="lazy">' +
      "</div>" +
      '<div class="mambers-card-body">' +
      "<h2>" +
      esc(member.display_name) +
      "</h2>" +
      '<p class="mambers-muted">@' +
      esc(member.username) +
      " · " +
      esc(member.tier) +
      "</p>" +
      bio +
      "</div></a>"
    );
  }

  function renderDirectory(root) {
    var api = getApi(root);
    var limit = root.getAttribute("data-limit") || "24";
    var title = root.getAttribute("data-title") || "# Mambers";
    var search = root.getAttribute("data-search") || "";
    var qs =
      "?limit=" +
      encodeURIComponent(limit) +
      (search ? "&search=" + encodeURIComponent(search) : "");

    fetchJson(api + "/members" + qs).then(function (data) {
      var items = (data && data.items) || [];
      var html =
        '<header class="mud-mambers-head"><h2 class="mud-mambers-title">' +
        esc(title) +
        "</h2></header>";

      if (!items.length) {
        root.innerHTML =
          html + '<p class="mambers-empty">No public member profiles yet.</p>';
        return;
      }

      html += '<div class="mambers-grid">';
      items.forEach(function (m) {
        html += memberCard(m);
      });
      html += "</div>";
      root.innerHTML = html;
    });
  }

  function renderFeatured(root) {
    var api = getApi(root);
    var title = root.getAttribute("data-title") || "Featured Mambers";
    var users = (root.getAttribute("data-users") || "")
      .split(",")
      .map(function (s) {
        return s.trim().toLowerCase();
      })
      .filter(Boolean);
    var tier = (root.getAttribute("data-tier") || "").toLowerCase();
    var limit = parseInt(root.getAttribute("data-limit") || "6", 10);

    fetchJson(api + "/members?limit=100").then(function (data) {
      var items = (data && data.items) || [];

      if (users.length) {
        items = items.filter(function (m) {
          return users.indexOf(String(m.username).toLowerCase()) !== -1;
        });
        items.sort(function (a, b) {
          return (
            users.indexOf(String(a.username).toLowerCase()) -
            users.indexOf(String(b.username).toLowerCase())
          );
        });
      } else if (tier) {
        items = items.filter(function (m) {
          return String(m.tier || "").toLowerCase() === tier;
        });
      }

      items = items.slice(0, limit);

      var html =
        '<header class="mud-mambers-head"><h2 class="mud-mambers-title">' +
        esc(title) +
        "</h2></header>";

      if (!items.length) {
        root.innerHTML = html + '<p class="mambers-empty">No featured members.</p>';
        return;
      }

      html += '<div class="mambers-grid mambers-grid--featured">';
      items.forEach(function (m) {
        html += memberCard(m);
      });
      html += "</div>";
      root.innerHTML = html;
    });
  }

  function renderProfile(root) {
    var api = getApi(root);
    var user = root.getAttribute("data-user") || "";

    if (!user) {
      fetchJson(api + "/whoami").then(function (who) {
        if (who && who.username) {
          root.setAttribute("data-user", who.username);
          renderProfile(root);
        } else {
          root.innerHTML =
            '<p class="mambers-empty">Set <code>user=</code> on the fence or log in.</p>';
        }
      });
      return;
    }

    fetchJson(api + "/profile/" + encodeURIComponent(user)).then(function (data) {
      if (!data || !data.ok) {
        root.innerHTML = '<p class="mambers-empty">Profile not found.</p>';
        return;
      }

      var p = data.profile || data;
      var cover = p.cover
        ? ' style="background-image:url(' + esc(p.cover) + ')"'
        : "";
      var links = (p.links || [])
        .map(function (l) {
          return (
            '<li><a href="' +
            esc(l.url) +
            '" rel="noopener noreferrer">' +
            esc(l.title) +
            "</a></li>"
          );
        })
        .join("");

      root.innerHTML =
        '<article class="mambers-profile mambers-profile--fence">' +
        '<header class="mambers-hero mambers-hero--compact">' +
        '<div class="mambers-hero-cover' +
        (p.cover ? "" : " mambers-hero-cover--empty") +
        '"' +
        cover +
        '><div class="mambers-hero-gradient"></div>' +
        '<div class="mambers-hero-identity">' +
        '<img class="mambers-hero-avatar" src="' +
        esc(p.avatar) +
        '" alt="" width="96" height="96">' +
        '<div class="mambers-hero-text"><h2>' +
        esc(p.display_name) +
        "</h2>" +
        '<p class="mambers-muted">@' +
        esc(p.username) +
        " · " +
        esc(p.tier) +
        "</p></div></div></div></header>" +
        (p.bio ? '<div class="mambers-bio">' + esc(p.bio) + "</div>" : "") +
        (links ? '<ul class="mambers-links">' + links + "</ul>" : "") +
        '<p><a class="mambers-link" href="' +
        esc(p.profile_url) +
        '">Full profile →</a></p></article>';
    });
  }

  function renderAuthCard(root, mode) {
    var loginUrl = root.getAttribute("data-login-url") || "/login";
    var registerUrl = root.getAttribute("data-register-url") || "/user_register";
    var profileUrl = root.getAttribute("data-profile-url") || "/members/me";
    var regOpen = root.getAttribute("data-registration-open") === "1";

    fetchJson(getApi(root) + "/whoami").then(function (who) {
      if (who && who.authenticated) {
        root.innerHTML =
          '<div class="mambers-auth-card mambers-auth-card--session">' +
          '<span class="mambers-auth-badge">Mambers</span>' +
          "<h2>Welcome, " +
          esc(who.display_name || who.username) +
          "</h2>" +
          '<p class="mambers-auth-lead">You are logged in.</p>' +
          '<a class="mambers-auth-btn" href="' +
          esc(profileUrl) +
          '">Your profile</a></div>';
        return;
      }

      if (mode === "login") {
        root.innerHTML =
          '<div class="mambers-auth-card mambers-auth-card--login">' +
          '<span class="mambers-auth-badge">Mambers</span>' +
          "<h2>Welcome back</h2>" +
          '<p class="mambers-auth-lead">Log in for your profile and member areas.</p>' +
          '<a class="mambers-auth-btn" href="' +
          esc(loginUrl) +
          '">Log in →</a>' +
          (regOpen
            ? '<p class="mambers-muted">No account? <a class="mambers-link" href="' +
              esc(registerUrl) +
              '">Create one</a></p>'
            : "") +
          "</div>";
        return;
      }

      if (mode === "register") {
        root.innerHTML =
          '<div class="mambers-auth-card mambers-auth-card--register">' +
          '<span class="mambers-auth-badge">Mambers</span>' +
          "<h2>Join the village</h2>" +
          '<p class="mambers-auth-lead">Create your member profile on Grav.</p>' +
          (regOpen
            ? '<a class="mambers-auth-btn" href="' +
              esc(registerUrl) +
              '">Create account →</a>'
            : '<p class="mambers-muted">Registration is closed.</p>') +
          '<p class="mambers-muted">Already a member? <a class="mambers-link" href="' +
          esc(loginUrl) +
          '">Log in</a></p></div>';
        return;
      }

      root.innerHTML =
        '<div class="mambers-auth-card mambers-auth-card--auth">' +
        '<span class="mambers-auth-badge">Mambers</span>' +
        "<h2>Member access</h2>" +
        '<p class="mambers-auth-lead">Log in or create an account.</p>' +
        '<div class="mambers-auth-actions">' +
        '<a class="mambers-auth-btn" href="' +
        esc(loginUrl) +
        '">Log in</a>' +
        (regOpen
          ? '<a class="mambers-auth-btn mambers-auth-btn--ghost" href="' +
            esc(registerUrl) +
            '">Register</a>'
          : "") +
        "</div></div>";
    });
  }

  function boot(root) {
    var mode = root.getAttribute("data-mode") || "directory";

    if (mode === "directory") return renderDirectory(root);
    if (mode === "featured") return renderFeatured(root);
    if (mode === "profile") return renderProfile(root);
    if (mode === "login" || mode === "register" || mode === "auth") {
      return renderAuthCard(root, mode);
    }
  }

  document.querySelectorAll("[data-mud-mambers]").forEach(boot);
})();

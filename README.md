# Portal Navigator Favorites

**Portal Navigator Favorites** is a lightweight WordPress plugin that lets you display a **page navigation list** with per-user **favorites** and a simple **custom user login** system.  
Users can browse your pages, click the ❤️ icon to save them, and access their own personalized list of favorites — all without needing a WordPress account.

---

## ✨ Features

- 🗂 **Page Navigator** – Generates a site sitemap with clickable page links.
- ❤️ **Favorites System** – Each custom user can save pages for quick access later.
- 🔐 **Custom User Login** – Works independently from WordPress users (own database table).
- 🖌 **Modern UI** – Gradient buttons, responsive cards, and mobile-friendly layout.
- ⚡ **AJAX-Powered** – Favorites update instantly without page reloads.
- 📦 **Drop-in Ready** – Just install and add shortcodes.

---

## 📥 Installation

1. Download or clone this repository into your WordPress `/wp-content/plugins/` directory.
2. Activate **Portal Navigator Favorites** in your WordPress admin dashboard.
3. Add the shortcodes to your pages where you want the features to appear.

---

## 🛠 Shortcodes

| Shortcode         | Description |
|-------------------|-------------|
| `[pn_login]`      | Displays the custom user login form. |
| `[pn_sitemap]`    | Shows the page navigation list with favorites toggle. |
| `[pn_favorites]`  | Displays the logged-in user’s list of favorite pages. |

---

## 📷 Screenshots

1. **Login Form** – Custom user login with gradient buttons.
2. **Page Navigator** – Full sitemap with heart icons.
3. **Favorites List** – Grid display of saved pages.

---

## 📚 Use Cases

- **Documentation Portals** – Allow staff or members to bookmark important pages.
- **Knowledge Bases** – Help users quickly return to their most used articles.
- **Internal Portals** – Provide custom users with tailored navigation.

---

## 🔧 Technical Details

- Uses its own database tables:
  - `wp_pn_custom_users` – Stores custom user accounts.
  - `wp_pn_user_favorites` – Stores favorite-page relationships.
- Login sessions are handled via PHP sessions (no WP user login required).
- AJAX endpoints for fast toggling of favorites.
- Fully namespaced with `pn_` function prefixes to prevent conflicts.

---

## 📜 License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

---

## 🤝 Contributing

1. Fork the repository.
2. Create your feature branch (`git checkout -b feature/amazing-feature`).
3. Commit your changes (`git commit -m 'Add amazing feature'`).
4. Push to the branch (`git push origin feature/amazing-feature`).
5. Open a Pull Request.

---

## 📬 Support

If you encounter any issues or have feature requests, please [open an issue](../../issues) here on GitHub.

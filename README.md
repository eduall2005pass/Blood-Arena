# 🩸 Blood Arena

**Blood Arena** is a blood donation management portal built for **SHSMC** (Sylhet Government High School & Model College). It connects blood donors with people in need of emergency blood transfusions, with a fully bilingual interface (Bangla & English).

🌐 **Live Site:** [bloodarenabd.app](https://bloodarenabd.app)

---

## ✨ Features

- 🔍 **Donor Search** — Find donors by blood group, district, and availability
- 🆘 **Emergency Request** — Post urgent blood requests visible to all donors
- 📋 **Donor Registration** — Register as a blood donor with contact info and location
- 🏅 **Badge System** — Donors earn badges (New → Active → Hero → Legend) based on donation count
- 📱 **Progressive Web App (PWA)** — Installable on Android/iOS with offline support via Service Worker
- 🔔 **Push Notifications** — Emergency alert notifications for registered donors
- 🛡️ **Secure Admin Panel** — Password-protected dashboard with brute-force protection, IP whitelist, session management, and audit logs
- 🌙 **Dark UI** — Mobile-first responsive dark-themed interface
- 🇧🇩 **Bangla Language Support** — Full UTF-8 / `utf8mb4` support for Bangla text

---

## 🗂️ Project Structure

```
Blood-Arena/
├── index.php               # Main portal (donor search, registration, emergency requests, PWA manifest)
├── db.php                  # MySQL database connection
├── sw.js                   # Service Worker for PWA / offline caching
├── sitemap.xml
├── robots.txt
├── admin/
│   ├── admin.php           # Secure admin dashboard (donor management, call logs, bulk actions)
│   ├── admin_setup.php     # One-time admin password setup (delete after use)
│   └── admin_config.php    # Hashed admin credentials (auto-generated, do NOT expose)
└── assets/
    ├── icon.png            # App icon
    ├── logo.png
    ├── logo1.png
    ├── rafi.jpg
    └── siam.jpg
```

---

## 🛠️ Tech Stack

| Layer      | Technology                  |
|------------|-----------------------------|
| Backend    | PHP 8+                      |
| Database   | MySQL / MariaDB (`utf8mb4`) |
| Frontend   | HTML5, CSS3, Vanilla JS     |
| PWA        | Web App Manifest + Service Worker |
| Hosting    | InfinityFree / cPanel       |

---

## 🚀 Installation & Setup

### 1. Prerequisites
- PHP 8.0+
- MySQL / MariaDB
- A web server (Apache / Nginx)

### 2. Database Setup
1. Create a MySQL database and user.
2. Import the required schema (create `donors` and any related tables).
3. Update `db.php` with your database credentials:

```php
$servername = "localhost";
$username   = "your_db_user";
$password   = "your_db_password";
$dbname     = "your_db_name";
```

### 3. Admin Setup
1. Open `admin/admin_setup.php` in your browser.
2. Set a strong admin password (min 10 chars, uppercase, number, special character).
3. **Delete `admin/admin_setup.php` immediately after setup** to prevent unauthorized access.

### 4. Deploy
Upload all files to your web server's public root directory.

---

## 🔐 Security Notes

- `db.php` and `admin/admin_config.php` contain sensitive credentials — **never expose them publicly**.
- The admin panel includes:
  - Rate-limited login (max 5 attempts, 15-minute lockout)
  - Session idle & hard timeout
  - IP whitelist support
  - CSRF protection and security headers
- `admin/admin_setup.php` must be **deleted** after initial setup.

---

## 👨‍💻 Contributors

| Name            | Role      |
|-----------------|-----------|
| Siam-258 (Sh-20) | Developer |

---

## 📄 License

This project is intended for educational and community use within SHSMC.

---

> _"Donate blood, save lives."_ 🩸

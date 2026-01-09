<div align="center">

# 📺 Stalker Portal Manager
**Advanced Stalker Middleware to M3U Converter**

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Status](https://img.shields.io/badge/Status-Active-success?style=for-the-badge)
![License](https://img.shields.io/badge/License-Personal_Use_Only-orange?style=for-the-badge)
![Developer](https://img.shields.io/badge/Developed_By-LazyyXD-blueviolet?style=for-the-badge)

[![Download ZIP](https://img.shields.io/badge/📦_Download_ZIP-Click_Here-blue?style=for-the-badge&logo=github)](https://github.com/lazyop/Stalker_pro/archive/refs/heads/main.zip)

</div>

---

## 📖 Overview

**Stalker Portal Manager** is a premium, self-hosted PHP application that empowers you to manage various Stalker Middleware portals and convert them into universal **M3U Playlists**. 

Designed with a **Glassmorphic UI** and streamlined backend, it works flawlessly on local environments (XAMPP, KSWEB) and live web hosting.

---

## 📸 Screenshots

<div align="center">

### 🔐 Login Page
<img src="https://i.ibb.co/zKT6bDB/login.png" alt="Login Page" width="700">

### 📊 Dashboard
<img src="https://i.ibb.co/7dsGgsj6/dashboard.png" alt="Dashboard" width="700">

</div>

---

## ✨ Key Features

- **🌐 Universal Compatibility:** Works perfectly on **local servers** (Localhost/XAMPP), **Android Servers** (KSWEB), and **Shared Hosting**.
- **🔌 Auto-Configuration:** Zero-setup IP detection! The script automatically adapts to your server's IP (e.g., `192.168.1.100` or domain).
- **📱 Optimised for OTT Players:** Built specifically for **TiviMate**, **OTT Navigator**, and **Televizo**.
- **🔄 Smart Sync:** Auto-syncs channels and updates token sessions in the background.
- **🎨 Modern UI:** Beautiful, responsive Glassmorphism dashboard for easy management.

---

## 📱 Supported Players

This script generates strictly formatted M3U playlists designed for **maximum compatibility** with premium IPTV players.

| Client App | Status | Notes |
| :--- | :---: | :--- |
| **TiviMate** | ✅ **Perfect** | Highly Recommended. Full EPG & faster loading. |
| **OTT Navigator** | ✅ **Perfect** | Excellent support for archives and catch-up. |

> **Note:** This script is **strictly optimized** for TiviMate and OTT Navigator. Other players may not work correctly.

---

## 🎮 Dashboard Controls

Each portal card in your dashboard comes with a set of powerful controls. Here is what they do:

### 1. 🔄 Sync
*   **Function:** Manually triggers a connection to the Stalker server.
*   **Use Case:** Use this if your channels aren't loading, or if you want to refresh the playlist to get the latest channel updates.
*   **Behavior:** It fetches new tokens and updates the local cache.

### 2. ✏️ Edit
*   **Function:** Opens a modal to modify the portal's details.
*   **Use Case:** Updating a changed MAC address, Portal URL, or adjusting advanced settings like **Device ID**, **Serial Number**, or **Model** (e.g., changing from MAG250 to MAG322).

### 3. 🔀 New ID (Rotate)
*   **Function:** Generates a fresh, random **Portal ID** (and consequently, a new M3U URL).
*   **Use Case:** Security. If you suspect your playlist URL has been leaked or shared without permission, click this to invalidate the old URL immediately. You will need to enter the new URL into your player.

### 4. 🗑️ Delete
*   **Function:** Permanently removes the portal.
*   **Use Case:** When a subscription has expired or you no longer need the portal.
*   **Warning:** This action is irreversible and deletes the local channel cache.

---

## ⚙️ Server Requirements

Before you begin, ensure your server meets these minimum requirements:

*   **PHP Version:** 7.4 or higher (8.0+ recommended)
*   **Extensions:** `cURL`, `mbstring`, `json` enabled
*   **Permissions:** Write access to the locally created `data` folder

---

## 🛠️ Full Installation Guide

[![Download ZIP](https://img.shields.io/badge/📦_Download_ZIP-Click_Here-blue?style=for-the-badge&logo=github)](https://github.com/lazyop/Stalker_pro/archive/refs/heads/main.zip)

### Option 1: Web Hosting (cPanel / Shared Hosting)

1.  **Upload Files:**
    *   Upload all project files to your `public_html` directory (or a subdirectory like `public_html/stalker`).
2.  **Set Permissions (Critical):**
    *   Once uploaded, the script will automatically create a `data` folder when you first run it.
    *   Ensure the script has permission to write to this folder. If you see permission errors, manually create a `data` folder and set its permissions to **777** (Read/Write/Execute for everyone).
3.  **Access the Dashboard:**
    *   Open your browser and navigate to your URL (e.g., `https://yourdomain.com/stalker`).
4.  **Login:**
    *   **Username:** `LazyyXD`
    *   **Password:** `Pass@LazyyXD`

### Option 2: Localhost (XAMPP / Windows)

> 📥 **Download XAMPP:** [https://www.apachefriends.org/download.html](https://www.apachefriends.org/download.html)

1.  **Place Files:**
    *   Copy the project files into `C:\xampp\htdocs\Stalker_pro`.
2.  **Start Apache:**
    *   Open XAMPP Control Panel and start the **Apache** module.
3.  **Access:**
    *   Open your browser and type: `http://localhost/Stalker_pro`.

### Option 3: Android (KSWEB)

> 📥 **Download KSWEB:** [https://tsneh.vercel.app/ksweb_3.987.apk](https://tsneh.vercel.app/ksweb_3.987.apk)

1.  **Copy Files:**
    *   Move the project files to your server root (usually `htdocs` on your internal storage).
2.  **Start Server:**
    *   Open KSWEB and ensure Lighttpd/Apache and PHP are running.
3.  **Access:**
    *   Use the IP address shown in KSWEB (e.g., `http://192.168.1.100:8080`).

---

## 🔧 Configuration

The `config.php` file handles the core settings.

### 1. Change Admin Password (Recommended)
By default, the login is `LazyyXD` / `Pass@LazyyXD`. To change this:
1.  Open `config.php`.
2.  Locate `ADMIN_PASSWORD_HASH`.
3.  You must generate a **BCrypt hash** for your new password. You can use an online generator or a simple PHP script: `echo password_hash('MY_NEW_PASSWORD', PASSWORD_DEFAULT);`.
4.  Replace the hash in `config.php`.

### 2. Branding
You can change the `LOGO_URL` constant in `config.php` to use your own custom logo.

---

## ⚠️ Disclaimer & License

> [!IMPORTANT]
> **THIS SOFTWARE IS FOR PERSONAL & EDUCATIONAL USE ONLY.**

*   🚫 **Commercial use is strictly prohibited.**
*   🚫 **Reselling this code is not allowed.**
*   This tool is a bridge/manager and does not provide any content itself.

<div align="center">

### Developed with ❤️ by LazyyXD

[![Telegram](https://img.shields.io/badge/Telegram-@lazyyXD-blue?style=for-the-badge&logo=telegram)](https://t.me/lazyyXD)

</div>


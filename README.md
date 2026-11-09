# DOCTOR CONNECT
### Token-Based Online Doctor Consultation Platform

DoctorConnect is a telehealth web application built with **PHP (PDO)**, **MySQL**, **HTML5/CSS3**, and **Vanilla JavaScript**. It replaces chaotic virtual appointment systems with an orderly, race-safe **Token Queue workflow**:

```
[ Patient Books Token ] ──> [ Race-Safe Token # Generated ] ──> [ Live Queue Tracking ] ──> [ Video Meeting Room (Jitsi) ] ──> [ Digital Prescription ]
```

---

## 🌟 Key Features & Architectural Highlights

### 1. Atomic Race-Safe Token Generation
- **Requirement 3.3 & Decision 1:** Enforced at both the application level and the database level.
- **Database Constraint:**
  ```sql
  UNIQUE KEY `unique_token_per_doctor_day` (`doctor_id`, `appointment_date`, `token_number`)
  ```
- **Transaction Safety:** Token allocation uses an atomic MySQL transaction with row-level locks (`SELECT COALESCE(MAX(token_number), 0) FROM appointments ... FOR UPDATE`).
- **Resilience:** Automatic retry with exponential jitter if concurrency collisions occur.

### 2. Dynamic Slot Computation
- **Decision 2:** Available slots are dynamically derived from `doctor_schedules`, doctor leave days (`doctor_leaves`), and existing booked tokens.
- **Estimated Meeting Time:** Automatically computed as:
  $$\text{estimated\_time} = \text{session\_start} + (\text{token\_number} - 1) \times \text{avg\_consultation\_mins}$$

### 3. Strict Video Meeting Link Gatekeeper
- **Requirement 3.4 & Decision 3:** The video meeting link (free Jitsi Meet tele-room `meet.jit.si/doctor-connect-{appointment_id}` or custom Zoom/Google Meet) is **strictly hidden and locked** until the patient's token is either **NEXT IN LINE** or **CURRENTLY SERVING**.
- Patients can participate directly within an embedded browser window or launch in a new tab.

### 4. Real-Time Live Queue Polling (<= 3s updates)
- Built-in polling engine (`assets/js/queue.js`) polls every 3 seconds to deliver live updates of:
  - Currently serving token number
  - Next token in line
  - Waiting patient count
  - Real-time queue position ("2 patients ahead of you")
- **Audio Chime:** Synthesized sound bell powered by the browser's **Web Audio API** (no external audio assets or MP3 dependencies).

### 5. Multi-Role Ecosystem & Consoles
- **Patient Portal:** Browse doctors, schedule tokens with dynamic slot preview, track live queue, join consultation, view past consultations, print prescriptions.
- **Doctor Console:** Call next patient in line, mark serving, mark no-show, interactive video consultation room with live patient history, and prescription writer.
- **Receptionist / Admin Console:** Today's multi-doctor queue monitor, advance queues, handle no-shows, issue instant **Walk-In Tokens**, manage doctor rosters and schedules, configure clinic settings, and **Export Detailed CSV Reports**.

---

## 🛠️ Technology Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.1+ (PDO prepared statements, OOP, Session timeout) |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ (InnoDB, UTF8mb4, Foreign Keys, Unique Indexes) |
| **Frontend** | Responsive Semantic HTML5, Custom Healthcare CSS3 variables, Vanilla JavaScript ES6 |
| **Video Telehealth** | Jitsi Meet (Free room integration: `meet.jit.si/doctor-connect-{id}`) / Custom URL support |
| **Security** | `password_hash()` bcrypt, CSRF token validation on POST, XSS escaping, Session fixation prevention |

---

## 🚀 Quick Setup & Installation

### Option A: 1-Click Automated Web Installer (Recommended)
1. Copy or clone this folder into your web root (e.g. `C:\xampp\htdocs\optima` or `/var/www/html/optima`).
2. Start **Apache** and **MySQL** (e.g. from XAMPP Control Panel).
3. Open your browser and navigate to:
   ```
   http://localhost/optima/setup.php
   ```
4. Click **"Initialize Database & Seed Demo Data"**. The installer will create the `doctor_connect` database, run the schema, and provision seed users automatically.

### Option B: Manual MySQL Import via CLI
If you prefer running SQL scripts manually:
```bash
mysql -u root -p < config/schema.sql
mysql -u root -p < config/seed.sql
```

### Configuration (`config/config.php`)
You can adjust credentials via environment variables or directly inside `config/config.php`:
```php
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'doctor_connect');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
```

---

## 👥 Demo Accounts (Credentials)

All seed accounts share the default password: **`password123`**
(The login screen also provides **1-Click Quick Fill Buttons** for testing).

| Role | Email | Password | Details |
|---|---|---|---|
| **Admin / Receptionist** | `admin@doctorconnect.com` | `password123` | Full clinic queue controller, doctor CRUD, reports & settings |
| **Doctor** | `dr.vance@doctorconnect.com` | `password123` | Dr. Marcus Vance (General Physician & Internal Medicine) |
| **Doctor** | `dr.sarah@doctorconnect.com` | `password123` | Dr. Sarah Jenkins (Cardiologist) |
| **Doctor** | `dr.thorne@doctorconnect.com` | `password123` | Dr. Aris Thorne (Dermatologist) |
| **Patient** | `john.doe@example.com` | `password123` | Has active demo token #3 for today! |
| **Patient** | `emily.w@example.com` | `password123` | Currently in consultation (Token #2) |

---

## 📂 Project Directory Structure

```
c:\optima/
├── config/
│   ├── config.php          # Database credentials, timezone, session settings, base URL
│   ├── database.php        # PDO connection wrapper with connection testing
│   ├── schema.sql          # Complete DDL schema with foreign keys and unique constraints
│   └── seed.sql            # Demo data (Settings, doctors, schedules, sample queue)
├── includes/
│   ├── auth.php            # Role guards, session timeout, password verification
│   ├── csrf.php            # CSRF token generator & validator
│   ├── helpers.php         # Estimated time formula, XSS escape, audit logger, notification helper
│   ├── header.php          # Common responsive navbar, flash messages, user pill
│   └── footer.php          # Common footer and script tags
├── api/
│   ├── queue_status.php    # Live queue polling JSON endpoint (<= 3s response)
│   ├── call_token.php      # Doctor/reception queue controller (call next, served, no-show)
│   ├── get_slots.php       # Dynamic slot capacity & next token time computation
│   ├── notifications.php   # Real-time in-app notification poller
│   └── book_walkin.php     # Receptionist instant walk-in token generator
├── patient/
│   ├── dashboard.php       # Active token callout, recent appointments, stats
│   ├── book.php            # Dynamic slot preview, atomic race-safe token booking
│   ├── queue.php           # Real-time live queue board, audio chime, meeting room
│   ├── history.php         # Past consultation history & prescription access
│   └── profile.php         # Personal details & password updater
├── doctor/
│   ├── dashboard.php       # Queue controller, one-click Call Next, today's queue table
│   ├── consult.php         # Live Jitsi video consultation room & prescription writer
│   ├── schedule.php        # Weekly consultation hours & leave-day marking
│   └── history.php         # Historical patient records with keyword search
├── admin/
│   ├── dashboard.php       # Multi-doctor queue overview, no-show rate KPI cards
│   ├── queue_monitor.php   # Reception live queue controller & walk-in patient modal
│   ├── doctors.php         # Add/edit doctors, assign specialties, fees, and slot times
│   ├── reports.php         # Filterable appointment analytics & 1-click CSV export
│   └── settings.php        # Clinic name, contact, cancellation window, Jitsi domain
├── assets/
│   ├── css/
│   │   └── style.css       # Clean healthcare aesthetic, pulse animations, print styles
│   └── js/
│       ├── main.js         # Web Audio API chime generator, toast alerts, notification poller
│       └── queue.js        # High-frequency queue polling engine
├── index.php               # Landing page with workflow steps & physician roster
├── login.php               # Universal sign-in with 1-click demo fillers
├── register.php            # Patient registration with validation
├── logout.php              # Session teardown
├── prescription.php        # Printable Rx document with official clinical layout
├── setup.php               # Web & CLI database installer & seeder
└── README.md               # Complete platform documentation
```

---

## 🔒 Security & Best Practices Implemented
1. **Prepared Statements Everywhere:** 100% of queries use PDO parameter binding to prevent SQL injection.
2. **CSRF Tokens:** All POST requests and state-changing forms include cryptographic CSRF tokens.
3. **Session Inactivity Timeout:** 30-minute idle session timeout protects unattended medical consoles.
4. **Privacy Enforced Meeting URLs:** Telehealth video links remain inaccessible until the patient's token is called.
5. **Printable Medical Format:** `prescription.php` includes specialized `@media print` rules for clean, border-accurate PDF rendering without browser chrome or buttons.

# Digital Coaching DFS Platform

This repository implements a digital coaching platform with integrated attendance, exams, fees, chat, notices, and a distributed file system (DFS). It uses PHP, MySQL, Google OAuth, and Google Drive for secure storage and authentication.

---

## Features

- Role-based dashboards for admins, teachers, and students.
- GPS-based attendance with batch schedules and calendars.
- Batch-wise fees and online payments via Razorpay.
- Exam engine with timed tests, coding questions, and analytics.
- Encrypted chat with reactions and Google Drive media sharing.
- Distributed file storage using encrypted chunks on user Drives.
- Global notices and batch-scoped notes distribution.

---

## Directory Structure

The structure below shows the main files and how they group by responsibility.

```text
project-root/
├─ admin_dashboard.php        # Admin control panel and app launcher
├─ attendance.php             # GPS attendance for students and staff
├─ authenticate.php           # Local email/password login handler
├─ chat_console.php           # Full-screen encrypted chat UI and AJAX API
├─ composer.json              # PHP dependency definitions
├─ composer.lock              # Locked versions of dependencies
├─ db_config.php              # Database and Google OAuth configuration
├─ distribute_file.php        # DFS management console and job dashboard
├─ drive_actions.php          # CRUD actions for Google Drive files
├─ drive_manager.php          # User Google Drive file browser
├─ exam_management.php        # Exam creation, attempt, and analytics
├─ fees_management.php        # Fees dashboard for admins and students
├─ get_file.php               # Secure endpoint for DFS file assembly
├─ get_job_status.php         # AJAX endpoint for DFS job status polling
├─ google_callback.php        # Google OAuth login callback
├─ image_proxy.php            # Cached image proxy for Drive images
├─ index.php                  # Main login page (local and Google)
├─ job_control.php            # Admin actions on DFS jobs
├─ link_google.php            # Start Google Drive linking flow
├─ link_google_callback.php   # Callback for Drive linking
├─ login_system_db.sql        # Database schema and sample data
├─ logout.php                 # Log out and clear sessions
├─ notes_management.php       # Batch notes distribution and retrieval
├─ notice_post.php            # Admin notice creation and management
├─ profile.php                # Student profile and mini app launcher
├─ reset_password.php         # Email-based password reset flow
├─ schedule_batch.php         # Batch scheduler and enrollment manager
├─ send_message.php           # Chat API (simpler interface)
├─ set_password.php           # First-time local password setup
├─ teachers_dashboard.php     # Teacher-focused batch and attendance view
├─ theme_config.php           # Shared theme variables and base CSS
├─ trigger_worker.php         # Non-blocking DFS worker launcher
├─ upload_image.php           # Standalone image upload to Drive
├─ assemble_file.php          # DFS file assembler and download endpoint
├─ start_worker.bat           # Windows loop script for DFS worker
├─ worker_process.php         # CLI DFS job processor
├─ User.class.php             # Core data access and helper class
│
├─ vendor/                    # Composer-installed PHP dependencies
│   └─ ...                    # Google API client, Guzzle, etc.
│
├─ cache/
│   └─ chat_images/           # Cached Drive images for proxy
│
└─ temp_uploads/              # Temporary DFS upload storage (created at runtime)
```

---

## High-Level System Architecture

The application links browser clients, the PHP app, the database, Google APIs, and external gateways. It centralizes business logic in PHP endpoints and the `User` class.

```mermaid
flowchart TD
    UserBrowser[User Browser] --> PHPApp[PHP Application Layer]
    PHPApp --> DB[MySQL Database]
    PHPApp --> GoogleAuth[Google OAuth Service]
    PHPApp --> GoogleDrive[Google Drive API]
    PHPApp --> Razorpay[Payment Gateway]
    PHPApp --> EmailSim[Reset Email Logging]

    subgraph Roles
        AdminRole[Admin]
        TeacherRole[Teacher]
        StudentRole[Student]
    end

    AdminRole --> UserBrowser
    TeacherRole --> UserBrowser
    StudentRole --> UserBrowser
```

---

## Module-Level Architecture

Each major business area is implemented in one or more entry PHP files, backed by shared tables and utilities. The diagram shows how main modules connect through the `User` class and the database.

```mermaid
flowchart TD
    UserClass[User.class.php] --> UsersTbl[users]
    UserClass --> BatchesTbl[batches]
    UserClass --> FeesTbl[fees]
    UserClass --> ExamsTbl[tests]
    UserClass --> NotesTbl[notes_registry]
    UserClass --> ChatTbl[chat_messages]
    UserClass --> DfsTbl[chunk_registry]
    UserClass --> DistQueueTbl[distribution_queue]
    UserClass --> ActivityTbl[activity_log]

    AdminDash[admin_dashboard.php] --> UserClass
    TeacherDash[teachers_dashboard.php] --> UserClass
    ProfilePage[profile.php] --> UserClass
    AttendancePage[attendance.php] --> UserClass
    FeesPage[fees_management.php] --> UserClass
    ExamPage[exam_management.php] --> UserClass
    NotesPage[notes_management.php] --> UserClass
    ChatConsole[chat_console.php] --> UserClass
    DFSConsole[distribute_file.php] --> UserClass
```

---

## User Flows Overview

### Authentication and Role Routing

Users authenticate and get routed to the correct dashboard, depending on their roles and linked Google account. Concurrent session protection prevents multiple active sessions per account.

```mermaid
flowchart TD
    Start[Open index.php] --> ChooseMethod{Select Login Method}
    ChooseMethod -->|Local| LocalAuth[authenticate.php]
    ChooseMethod -->|Google| OAuthFlow[google_callback.php]
    LocalAuth --> SessionCheck[Concurrent Session Check]
    OAuthFlow --> SessionCheck

    SessionCheck -->|OK| RoleRoute[Route by Role]
    SessionCheck -->|Blocked| ErrorPage[Show Concurrent Session Error]

    RoleRoute -->|Admin| AdminDashboard[admin_dashboard.php]
    RoleRoute -->|Teacher| TeacherDashboard[teachers_dashboard.php]
    RoleRoute -->|Student| StudentProfile[profile.php]
```

---

### DFS Upload, Distribution, and Download

Admins use the DFS console to upload files and later retrieve them. A worker process handles the heavy work asynchronously, using Google Drive for chunk storage.

```mermaid
flowchart TD
    AdminUpload[Admin uploads file in distribute_file.php] --> CreateJob[Create PENDING job in distribution_queue]
    CreateJob --> TriggerWorker[trigger_worker.php optionally launches worker]
    TriggerWorker --> Worker[worker_process.php loop]

    Worker --> ReadJob[Read PENDING job]
    ReadJob --> Split[Split local file into chunks]
    Split --> EncryptChunks[Encrypt each chunk]
    EncryptChunks --> AssignDrives[Assign chunk owners with Drive tokens]
    AssignDrives --> UploadToDrive[Upload to appDataFolder]
    UploadToDrive --> RegisterChunks[Insert chunk_registry rows]
    RegisterChunks --> MarkComplete[Mark job COMPLETE]

    AdminDownload[Admin clicks download DFS file] --> AssembleMeta[Read chunk_registry for file UUID]
    AssembleMeta --> FetchChunk[Fetch encrypted chunks from Drive]
    FetchChunk --> DecryptChunk[Decrypt and combine chunks]
    DecryptChunk --> ServeFile[Stream original file to browser]
```

---

### Learning and Teaching Flow

The main teaching loop connects batches, attendance, homework, exams, notes, and communication. Each day, these modules coordinate around batch definitions and membership.

```mermaid
flowchart TD
    AdminBatches[schedule_batch.php manages batches] --> BatchesDB[batches + batch_students]
    BatchesDB --> AttendanceModule[attendance.php]
    BatchesDB --> TeacherHub[teachers_dashboard.php]
    BatchesDB --> ExamsModule[exam_management.php]
    BatchesDB --> FeesModule[fees_management.php]
    BatchesDB --> NotesModule[notes_management.php]

    TeacherHub --> Homework[Post homework and tests]
    AttendanceModule --> AttendanceData[attendance table]
    ExamsModule --> ExamResults[exam_submissions and responses]
    FeesModule --> FeeStatus[fees and payments]
    NotesModule --> NotesLinks[notes_registry and DFS chunks]

    StudentPortal[profile.php and student views] --> AttendanceModule
    StudentPortal --> ExamsModule
    StudentPortal --> FeesModule
    StudentPortal --> NotesModule
    StudentPortal --> ChatConsole
```

---

## Data Storage Architecture

The database organizes information around users, batches, and activities. Chunks and job queues implement the DFS layer, while standard tables hold educational data.

```mermaid
erDiagram
    USERS ||--o{ ACTIVITY_LOG : logs
    USERS ||--o{ BATCH_STUDENTS : enrolls
    BATCHES ||--o{ BATCH_STUDENTS : has
    BATCHES ||--o{ ATTENDANCE : schedules
    USERS ||--o{ ATTENDANCE : marks

    USERS ||--o{ FEES : billed
    FEES ||--o{ PAYMENTS : paid
    BATCHES ||--o{ FEES : per_batch

    BATCHES ||--o{ TESTS : assigned
    TESTS ||--o{ EXAM_QUESTIONS : contains
    TESTS ||--o{ EXAM_SUBMISSIONS : attempted
    EXAM_SUBMISSIONS ||--o{ EXAM_RESPONSES : answers

    USERS ||--o{ CHAT_MESSAGES : sends
    CHAT_MESSAGES ||--o{ CHAT_REACTIONS : reacts

    DISTRIBUTION_QUEUE ||--o{ CHUNK_REGISTRY : outputs
    NOTES_REGISTRY ||--o{ CHUNK_REGISTRY : references

    USERS ||--o{ NOTICES : posts
```

---

## Summary

This README describes how directories, modules, and subsystems fit together in the digital coaching DFS platform. It highlights the main user flows, data relationships, and integration points, without diving into specific implementation details.

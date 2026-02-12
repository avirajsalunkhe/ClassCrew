

---

# 🎓 ClassCrew- Digital Coaching DFS Platform

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php\&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql\&logoColor=white)
![Google OAuth](https://img.shields.io/badge/Auth-Google%20OAuth-4285F4?logo=google\&logoColor=white)
![Google Drive API](https://img.shields.io/badge/Storage-Google%20Drive-34A853?logo=google-drive\&logoColor=white)
![Razorpay](https://img.shields.io/badge/Payments-Razorpay-0C2451?logo=razorpay\&logoColor=white)
![License](https://img.shields.io/badge/License-Private-red)
![Status](https://img.shields.io/badge/Status-Production%20Ready-brightgreen)

> A Secure, Distributed, Cloud-Integrated Learning Management System with Encrypted File Storage.

---

## 🚀 Overview

The **Digital Coaching DFS Platform** is a modular, role-driven digital coaching ecosystem designed for institutes and academic organizations. It integrates attendance tracking, exam management, fee processing, encrypted communication, and a distributed file system powered by Google Drive.

Built with scalable and secure architecture principles, the system ensures:

* Cloud-backed distributed storage
* Role-based dashboards
* Asynchronous background processing
* Secure OAuth authentication
* Real-time academic workflows

---

# ✨ Core Capabilities

### 🔐 Role-Based Access

* Admin Dashboard
* Teacher Dashboard
* Student Portal
* Concurrent session restriction

### 📍 Smart Attendance

* GPS validation
* Batch scheduling
* Calendar-based tracking

### 💳 Fee & Payment System

* Batch-wise fee configuration
* Razorpay integration
* Payment status tracking

### 📝 Exam Engine

* Timed assessments
* MCQ + coding questions
* Submission analytics
* Result tracking

### 💬 Encrypted Communication

* Secure chat console
* Reactions
* Drive-based media sharing
* Cached image proxy

### 📂 Distributed File System (DFS)

* File chunking
* AES encryption
* Google Drive storage
* Background worker processing
* Secure reassembly on download

### 📢 Notices & Notes

* Global announcements
* Batch-scoped distribution
* DFS-backed file attachments

---

# 🗂 Project Structure

```text
project-root/
├─ admin_dashboard.php
├─ attendance.php
├─ authenticate.php
├─ chat_console.php
├─ composer.json
├─ db_config.php
├─ distribute_file.php
├─ drive_manager.php
├─ exam_management.php
├─ fees_management.php
├─ google_callback.php
├─ index.php
├─ notes_management.php
├─ notice_post.php
├─ profile.php
├─ schedule_batch.php
├─ teachers_dashboard.php
├─ worker_process.php
├─ User.class.php
│
├─ vendor/
├─ cache/
└─ temp_uploads/
```

---

# 🏗 System Architecture

```mermaid
flowchart TD
    Client[User Browser] --> App[PHP Application Layer]
    App --> DB[(MySQL)]
    App --> OAuth[Google OAuth]
    App --> Drive[Google Drive API]
    App --> Payment[Razorpay Gateway]
```

### Architecture Principles

* Thin frontend, strong backend logic
* Centralized business abstraction via `User.class.php`
* Encrypted distributed storage
* Asynchronous worker-based processing
* Strict role validation

---

# 🔄 Distributed File Workflow

```mermaid
flowchart TD
    Upload --> CreateJob
    CreateJob --> WorkerProcess
    WorkerProcess --> Split
    Split --> Encrypt
    Encrypt --> UploadToDrive
    UploadToDrive --> RegisterDB
    RegisterDB --> Complete
```

### DFS Highlights

* Files split into encrypted chunks
* Each chunk stored in Google Drive `appDataFolder`
* Metadata stored in `chunk_registry`
* Secure reassembly via streaming endpoint

---

# 🗄 Database Design Snapshot

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

# 🗄Learning and Teaching Flow

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
The main teaching loop connects batches, attendance, homework, exams, notes, and communication. Each day, these modules coordinate around batch definitions and membership.

---

---

# 📝DFS Upload, Distribution, and Download

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
Admins use the DFS console to upload files and later retrieve them. A worker process handles the heavy work asynchronously, using Google Drive for chunk storage.

---

# 🔒 Security Design

* AES-based chunk encryption
* OAuth 2.0 secure login
* Google Drive token isolation
* Role-based query enforcement
* Secure file streaming
* Session concurrency protection

---

# ⚙ Background Worker Engine

The DFS system runs through:

* `worker_process.php` (CLI processor)
* `trigger_worker.php` (non-blocking launcher)
* Windows `start_worker.bat`

This ensures:

* Non-blocking uploads
* Queue-based execution
* Fault tolerance
* Scalable processing

---

# 🧩 Technology Stack

| Layer        | Technology            |
| ------------ | --------------------- |
| Backend      | PHP 8.x               |
| Database     | MySQL                 |
| Auth         | Google OAuth 2.0      |
| Storage      | Google Drive API      |
| Payments     | Razorpay              |
| Async Engine | CLI Worker            |
| Frontend     | HTML, CSS, JavaScript |

---

# 📈 Design Strengths

* Modular architecture
* Distributed encrypted storage
* Clean role separation
* Background job engine
* Cloud-native integration
* Scalable database schema

---

# 🏁 Final Summary

The Digital Coaching DFS Platform represents a **secure, distributed, cloud-powered academic management system** designed with enterprise-level architecture principles.

It combines:

✔ Learning management
✔ Secure communication
✔ Payment automation
✔ Distributed encrypted storage
✔ Modular scalability

---

🚀 Digital Coaching & Distributed File System (DFS) Platform

A sophisticated, multi-tenant digital coaching ecosystem. This platform goes beyond standard LMS features by implementing a proprietary Distributed File System (DFS) that shards, encrypts, and distributes data across user-linked Google Drives, ensuring high availability and cost-effective secure storage.

🌟 Key Pillars of the Platform

🛠️ Infrastructure & Security (DFS)

Encrypted Sharding: Files are split into chunks, encrypted, and distributed across multiple student/staff Google Drives.

Redundant Assembly: High-speed file re-assembly logic using the chunk_registry.

Google OAuth 2.0: Secure, scoped authentication for seamless Drive integration.

Asynchronous Processing: Dedicated background worker for non-blocking file distribution.

🎓 Academic Management

Exam Engine: Full-featured testing suite supporting timed constraints, coding challenges, and automated grading.

Smart Attendance: Precision GPS-based attendance tracking for both physical and virtual batch sessions.

Note Distribution: Batch-scoped academic resource sharing integrated with the DFS backend.

💰 Financials & Operations

Razorpay Integration: Automated fee collection with real-time payment status webhooks.

Batch Scheduling: Dynamic enrollment management and automated batch-wise fee structures.

Global Notices: Multi-channel notice broadcasting (Admin to Batch or Admin to All).

💬 Real-time Collaboration

Encrypted Chat: E2E-simulated chat environment with media-rich messaging.

Drive Proxy: Secure image/file previewing without exposing direct Google Drive URLs.

📂 System Architecture & Layout

📦 project-root
 ┣ 📂 core/                # System logic & definitions
 ┃ ┗ 📜 User.class.php     # Data Access Layer & Business Logic
 ┣ 📂 dfs/                 # Distributed File System Module
 ┃ ┣ 📜 worker_process.php # CLI Job Processor
 ┃ ┣ 📜 get_file.php       # Secure Assembly Endpoint
 ┃ ┗ 📜 distribute_file.php# Management Console
 ┣ 📂 modules/             # Business Logic Modules
 ┃ ┣ 📜 attendance.php     # GPS & Batch Attendance
 ┃ ┣ 📜 exam_management.php # Testing & Analytics
 ┃ ┣ 📜 fees_management.php # Razorpay Integration
 ┃ ┗ 📜 chat_console.php   # Collaboration Hub
 ┣ 📂 auth/                # Security & Authentication
 ┃ ┣ 📜 google_callback.php# OAuth Handling
 ┃ ┗ 📜 reset_password.php # Recovery Flows
 ┣ 📜 db_config.php        # Environment Variables
 ┗ 📜 index.php            # Application Entry Point


📊 Technical Flowcharts

1. High-Level Logic Flow

The platform acts as a central orchestrator between the local database and third-party API gateways.

flowchart TD
    User((User)) --> WebApp[PHP Application Layer]
    WebApp <--> DB[(MySQL DB)]
    WebApp --> GAuth[Google OAuth]
    WebApp --> GDrive[Google Drive API]
    WebApp --> RPay[Razorpay API]
    
    subgraph DFS_Engine [DFS Internal Engine]
        WebApp --> Queue[Job Queue]
        Queue --> Worker[Worker Process]
        Worker --> Chunks[Encrypted Chunks]
    end


2. DFS Distribution Logic (The Innovation)

Detailed look at how files are transformed from local uploads to a distributed cloud state.

sequenceDiagram
    participant A as Admin
    participant W as Worker (CLI)
    participant R as Chunk Registry
    participant D as Student Drive

    A->>W: Upload Large File
    W->>W: Split into N Chunks
    W->>W: Encrypt (AES-256)
    loop For each Chunk
        W->>D: Upload to appDataFolder
        D-->>W: File ID
        W->>R: Map FileID to StudentID
    end
    W-->>A: Distribution Complete


🛠️ Installation & Setup

Dependencies: Run composer install to fetch Google API Client and Guzzle.

Environment:

Rename db_config.sample.php to db_config.php.

Input your Google Client ID, Secret, and Razorpay API keys.

Database: Import login_system_db.sql into your MySQL instance.

DFS Worker:

For Windows: Run start_worker.bat.

For Linux: Execute php worker_process.php as a background service.

🛡️ Security Model

Session Guard: Concurrent session detection prevents multiple logins on a single account.

DFS Isolation: Chunks are stored in the appDataFolder of Google Drive, meaning users cannot see or delete the chunks manually.

API Proxying: image_proxy.php ensures that Google Drive authentication tokens are never exposed to the client-side frontend.

Developed for professional coaching institutions requiring high-security infrastructure.

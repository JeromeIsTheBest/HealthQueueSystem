-- HealthQueue Database Schema
-- Import via phpMyAdmin or: mysql -u root healthqueue_db < schema.sql
-- (Create the database first: CREATE DATABASE healthqueue_db;)

CREATE DATABASE IF NOT EXISTS healthqueue_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE healthqueue_db;

-- ---------------------------------------------------------------
-- Clinics (Data Dictionary Table 4)
-- Starting point: only what the landing page's Featured Clinics
-- section needs. Additional columns/tables (Users, Roles, Appointments,
-- QueueTokens, etc.) are added as those modules are built.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Clinic (
    ClinicID            INT AUTO_INCREMENT PRIMARY KEY,
    ClinicName          VARCHAR(150)   NOT NULL,
    Address             VARCHAR(255)   NOT NULL,
    ContactNumber       VARCHAR(20)    NOT NULL,
    BaseConsultationFee DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    PhotoUrl             VARCHAR(255)  NULL,
    Description          TEXT          NULL,
    -- Comma-separated list shown as tags/filters on the patient clinic browser.
    Specialties          VARCHAR(255)  NULL,
    -- Opening hours: ISO weekdays (1 = Monday ... 7 = Sunday), comma-separated.
    OpenDays             VARCHAR(20)   NOT NULL DEFAULT '1,2,3,4,5,6',
    OpenTime             TIME          NOT NULL DEFAULT '08:00:00',
    CloseTime            TIME          NOT NULL DEFAULT '17:00:00',
    RegistrationDate    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt           DATETIME       NULL,
    Status               VARCHAR(20)   NOT NULL DEFAULT 'Active',
    archived             TINYINT       NOT NULL DEFAULT 0
);

-- Clinic registration inquiries submitted from the public landing page.
-- An administrator can review these before creating an active Clinic record.
CREATE TABLE IF NOT EXISTS ClinicRegistrationInquiry (
    InquiryID       INT AUTO_INCREMENT PRIMARY KEY,
    ClinicName      VARCHAR(150) NOT NULL,
    ContactName     VARCHAR(150) NOT NULL,
    Email           VARCHAR(150) NOT NULL,
    Phone           VARCHAR(30)  NOT NULL,
    City            VARCHAR(150) NOT NULL,
    PhysiciansCount INT NULL,
    StaffCount      INT NULL,
    BaseConsultationFee DECIMAL(10,2) NULL,
    Status          VARCHAR(20)  NOT NULL DEFAULT 'Pending',
    SubmittedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------
-- Roles (Data Dictionary Table 5)
-- Patients self-register through auth/register.php; Staff, Physician
-- and Admin accounts are created later by a System Administrator, per
-- the manuscript's onboarding workflow (Figure 12 / Network Topology).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Roles (
    RoleID   INT AUTO_INCREMENT PRIMARY KEY,
    RoleName VARCHAR(50) NOT NULL UNIQUE
);

INSERT IGNORE INTO Roles (RoleName) VALUES
    ('Patient'), ('Staff'), ('Physician'), ('Admin');

-- ---------------------------------------------------------------
-- Users (Data Dictionary Table 8)
-- Deviation from the strict data dictionary: ClinicID is nullable here.
-- Patients and platform Admins are not scoped to a single clinic (a
-- patient books across many clinics), while Staff/Physician accounts
-- -- created by an Admin once a clinic is onboarded -- do carry a
-- ClinicID. ContactNumber is added for SMS notifications (storyboard
-- Figure 24's sign-up form) though it isn't in the printed dictionary.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Users (
    UserID        INT AUTO_INCREMENT PRIMARY KEY,
    -- Public 10-digit ID shown to users: RR YY NNNNNN = role code
    -- (01 Patient, 02 Physician, 03 Staff, 04 Admin), 2-digit registration
    -- year, per-role-per-year sequence. Set by assignUserIdNumber() in
    -- includes/auth.php right after the account is created.
    IDNumber      CHAR(10)     NULL UNIQUE,
    ClinicID      INT NULL,
    RoleID        INT NOT NULL,
    FirstName     VARCHAR(50)  NOT NULL,
    LastName      VARCHAR(50)  NOT NULL,
    Email         VARCHAR(100) NOT NULL UNIQUE,
    EmailVerifiedAt DATETIME NULL,
    ContactNumber VARCHAR(20)  NULL,
    PasswordHash  VARCHAR(255) NOT NULL,
    CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt     DATETIME     NULL,
    Status        VARCHAR(20)  NOT NULL DEFAULT 'Active',
    Archived      TINYINT      NOT NULL DEFAULT 0,
    -- Physician's own self-reported availability toggle, shown on their
    -- dashboard. Meaningless for other roles; left at the default for them.
    AvailabilityStatus VARCHAR(20) NOT NULL DEFAULT 'Available',
    ProfilePhoto  VARCHAR(255) NULL,
    -- Patient wallet balance. Meaningless for other roles; a simulated
    -- store of value only -- top-ups and payments never touch real money.
    WalletBalance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Patient-entered medical information shown on their profile.
    -- Meaningless for other roles; left NULL for them.
    BloodType              VARCHAR(5)   NULL,
    Allergies              VARCHAR(500) NULL,
    EmergencyContactName   VARCHAR(100) NULL,
    EmergencyContactNumber VARCHAR(20)  NULL,
    CONSTRAINT fk_users_role  FOREIGN KEY (RoleID)   REFERENCES Roles (RoleID),
    CONSTRAINT fk_users_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID)
);

-- Stores hashed, single-use, time-limited password reset tokens.
-- Only the SHA-256 hash of the token is stored (never the raw token),
-- matching the same principle as PasswordHash on Users.
CREATE TABLE IF NOT EXISTS PasswordResets (
    ResetID   INT AUTO_INCREMENT PRIMARY KEY,
    UserID    INT NOT NULL,
    TokenHash CHAR(64) NOT NULL,
    ExpiresAt DATETIME NOT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UsedAt    DATETIME NULL,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (UserID) REFERENCES Users (UserID),
    UNIQUE KEY uq_token_hash (TokenHash)
);

-- Stores hashed, single-use email verification links for patient signup.
CREATE TABLE IF NOT EXISTS EmailVerificationTokens (
    VerificationID INT AUTO_INCREMENT PRIMARY KEY,
    UserID        INT NOT NULL,
    TokenHash     CHAR(64) NOT NULL UNIQUE,
    ExpiresAt     DATETIME NOT NULL,
    UsedAt        DATETIME NULL,
    CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (UserID) REFERENCES Users (UserID) ON DELETE CASCADE,
    INDEX idx_email_verification_user (UserID)
);

-- Short-lived email verification tokens issued before a patient account exists.
CREATE TABLE IF NOT EXISTS SignupEmailVerifications (
    SignupVerificationID INT AUTO_INCREMENT PRIMARY KEY,
    Email               VARCHAR(100) NOT NULL,
    TokenHash           CHAR(64) NOT NULL UNIQUE,
    ExpiresAt            DATETIME NOT NULL,
    VerifiedAt           DATETIME NULL,
    UsedAt               DATETIME NULL,
    CreatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_signup_verification_email (Email)
);

-- Physician weekly availability (Data Dictionary Table 11). A physician
-- manages their own rows here to advertise which days/times they see
-- patients (storyboard Figure 40). Not yet enforced by the patient
-- booking form -- see physician/availability.php's note.
CREATE TABLE IF NOT EXISTS PhysicianAvailability (
    AvailabilityID INT AUTO_INCREMENT PRIMARY KEY,
    PhysicianID    INT NOT NULL,
    DayOfWeek      TINYINT NOT NULL, -- 1=Monday .. 7=Sunday (ISO-8601)
    StartTime      TIME NOT NULL,
    EndTime        TIME NOT NULL,
    CONSTRAINT fk_availability_physician FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    INDEX idx_availability_physician (PhysicianID)
);

-- Patient appointment requests. Clinic staff can later confirm these and
-- assign a physician/queue token from the staff workflow.
CREATE TABLE IF NOT EXISTS Appointments (
    AppointmentID   INT AUTO_INCREMENT PRIMARY KEY,
    PatientID       INT NOT NULL,
    ClinicID        INT NOT NULL,
    PhysicianID     INT NULL,
    AppointmentDate DATE NOT NULL,
    AppointmentTime TIME NULL,
    Concern         TEXT NULL,
    Status          VARCHAR(20) NOT NULL DEFAULT 'Pending',
    -- The simulated checkout gate: a request only reaches the clinic's
    -- Appointment Requests queue once the patient has "paid" the booking
    -- fee (no real payment gateway is integrated -- see patient/checkout.php).
    BookingFeePaid    TINYINT  NOT NULL DEFAULT 0,
    BookingFeePaidAt  DATETIME NULL,
    -- Patient-supplied reason when they cancel their own request (one of a
    -- fixed set of choices, or free text when they pick "Others").
    CancellationReason VARCHAR(255) NULL,
    CreatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appointments_patient FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_appointments_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_appointments_physician FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    INDEX idx_appointments_patient_date (PatientID, AppointmentDate),
    INDEX idx_appointments_clinic_date (ClinicID, AppointmentDate)
);

-- Patient reschedule proposals are reviewed by clinic staff. The original
-- appointment remains active until a staff member approves the proposal.
CREATE TABLE IF NOT EXISTS AppointmentRescheduleRequests (
    RequestID       INT AUTO_INCREMENT PRIMARY KEY,
    AppointmentID  INT NOT NULL,
    PatientID       INT NOT NULL,
    ClinicID        INT NOT NULL,
    RequestedDate   DATE NOT NULL,
    RequestedTime   TIME NOT NULL,
    Status          VARCHAR(20) NOT NULL DEFAULT 'Pending',
    RequestedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ReviewedBy      INT NULL,
    ReviewedAt      DATETIME NULL,
    CONSTRAINT fk_reschedule_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_reschedule_patient FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_reschedule_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_reschedule_reviewer FOREIGN KEY (ReviewedBy) REFERENCES Users (UserID),
    INDEX idx_reschedule_clinic_status (ClinicID, Status, RequestedAt)
);

-- Financial record created only when an appointment payment succeeds.
-- The split is retained at the time of payment: 90% to the physician and
-- 10% to HealthQueue as the platform commission.
CREATE TABLE IF NOT EXISTS AppointmentPayments (
    PaymentID           INT AUTO_INCREMENT PRIMARY KEY,
    AppointmentID       INT NOT NULL,
    GrossAmount         DECIMAL(10,2) NOT NULL,
    PhysicianRevenue    DECIMAL(10,2) NOT NULL,
    PlatformCommission  DECIMAL(10,2) NOT NULL,
    PaymentStatus       VARCHAR(20) NOT NULL DEFAULT 'Paid',
    PaidAt              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    RefundedAt          DATETIME NULL,
    CONSTRAINT fk_payments_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    UNIQUE KEY uq_payment_appointment (AppointmentID),
    INDEX idx_payments_status_paidat (PaymentStatus, PaidAt)
);

-- Broadcast messages an Admin posts to patients/staff/physicians (Figure 48).
CREATE TABLE IF NOT EXISTS Announcements (
    AnnouncementID INT AUTO_INCREMENT PRIMARY KEY,
    PostedByUserID INT NOT NULL,
    Title         VARCHAR(150) NOT NULL,
    Message       TEXT NOT NULL,
    Audience      VARCHAR(20) NOT NULL DEFAULT 'Everyone',
    CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_announcements_user FOREIGN KEY (PostedByUserID) REFERENCES Users (UserID)
);

-- Admin activity trail (Data Dictionary Table 19, Figure 49). ClinicID is
-- nullable here (unlike the printed dictionary) since several logged
-- actions -- posting an announcement, reviewing a registration inquiry
-- before a Clinic row even exists -- aren't scoped to one clinic.
CREATE TABLE IF NOT EXISTS AuditLogs (
    LogID          INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID       INT NULL,
    UserID         INT NULL,
    ActionExecuted VARCHAR(150) NOT NULL,
    Description    VARCHAR(255) NULL,
    CreatedAt      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auditlogs_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_auditlogs_user FOREIGN KEY (UserID) REFERENCES Users (UserID),
    INDEX idx_auditlogs_created (CreatedAt)
);

-- Consultations (Data Dictionary Table 15). One row per completed visit;
-- the actual clinical content lives in ConsultationVersions so edits keep
-- a full history instead of silently overwriting the record.
CREATE TABLE IF NOT EXISTS Consultations (
    ConsultationID       INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID             INT NOT NULL,
    AppointmentID        INT NOT NULL,
    PatientID            INT NOT NULL,
    DoctorID             INT NOT NULL,
    AudioFilePath        VARCHAR(255) NULL,
    AudioDurationSeconds INT NULL,
    CompletedAt          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt            DATETIME NULL,
    Status               VARCHAR(20) NOT NULL DEFAULT 'Finalized',
    Archived             TINYINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_consultations_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_consultations_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_consultations_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_consultations_doctor      FOREIGN KEY (DoctorID) REFERENCES Users (UserID),
    UNIQUE KEY uq_consultation_appointment (AppointmentID)
);

-- AI Transcript Versions (Data Dictionary Table 17). TranscriptType is one
-- of Original_STT / Doctor_Corrected / AI_Summary. WordErrorRate is filled
-- in only once a real STT engine is wired up (see includes/ai-transcription.php).
CREATE TABLE IF NOT EXISTS AITranscriptVersions (
    TranscriptVersionID INT AUTO_INCREMENT PRIMARY KEY,
    ConsultationID      INT NOT NULL,
    TranscriptType      VARCHAR(30) NOT NULL,
    TranscriptText      TEXT NOT NULL,
    WordErrorRate       DECIMAL(5,2) NULL,
    GeneratedAt         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aitranscripts_consultation FOREIGN KEY (ConsultationID) REFERENCES Consultations (ConsultationID),
    INDEX idx_aitranscripts_consultation (ConsultationID, TranscriptType, GeneratedAt)
);

-- Consultation Versions (Data Dictionary Table 16). Every save inserts a
-- new row rather than updating in place, so notes can never be silently
-- lost -- only the latest RevisionNumber is shown by default.
CREATE TABLE IF NOT EXISTS ConsultationVersions (
    VersionID           INT AUTO_INCREMENT PRIMARY KEY,
    ConsultationID       INT NOT NULL,
    EditedByPhysicianID  INT NOT NULL,
    RevisionNumber       INT NOT NULL,
    ClinicalNotes        TEXT NOT NULL,
    PrescriptionText     TEXT NULL,
    UpdatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DeletedAt            DATETIME NULL,
    Status               VARCHAR(20) NOT NULL DEFAULT 'Active',
    Archived             TINYINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_consultationversions_consultation FOREIGN KEY (ConsultationID) REFERENCES Consultations (ConsultationID),
    CONSTRAINT fk_consultationversions_physician FOREIGN KEY (EditedByPhysicianID) REFERENCES Users (UserID),
    INDEX idx_consultationversions_consultation (ConsultationID, RevisionNumber)
);

-- One row per patient waiting in a clinic's walk-in/day-of queue. Always
-- tied to an Appointment (walk-ins get one created on the spot with no
-- prior request step); the physician is copied in at registration time so
-- the queue can be filtered even if the appointment's physician changes.
CREATE TABLE IF NOT EXISTS Queue (
    QueueID          INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID         INT NOT NULL,
    AppointmentID    INT NOT NULL,
    PhysicianID      INT NULL,
    QueueNumber      INT NOT NULL,
    ScheduledNumber  INT NULL,
    RegularNumber    INT NULL,
    Status           VARCHAR(20) NOT NULL DEFAULT 'Waiting', -- Waiting, Calling, Serving, Completed, Forfeited_Late, Removed
    CreatedByStaffID INT NULL,
    CreatedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CalledAt         DATETIME NULL,
    ServedAt         DATETIME NULL,
    CONSTRAINT fk_queue_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_queue_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_queue_physician   FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    CONSTRAINT fk_queue_staff       FOREIGN KEY (CreatedByStaffID) REFERENCES Users (UserID),
    INDEX idx_queue_clinic_date (ClinicID, CreatedAt)
);

-- Lightweight, clinic-wide (not per-user) notification feed for staff --
-- read state is shared across everyone on the clinic's team, which keeps
-- "Mark all read" meaningful without a separate per-user read-tracking table.
CREATE TABLE IF NOT EXISTS Notifications (
    NotificationID       INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID             INT NOT NULL,
    AnnouncementID INT NULL,
    Message              VARCHAR(255) NOT NULL,
    RelatedAppointmentID INT NULL,
    IsRead               TINYINT NOT NULL DEFAULT 0,
    CreatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_notifications_appointment FOREIGN KEY (RelatedAppointmentID) REFERENCES Appointments (AppointmentID),
    INDEX idx_notifications_clinic_read (ClinicID, IsRead),
    CONSTRAINT fk_staff_notice_announcement FOREIGN KEY (AnnouncementID) REFERENCES Announcements(AnnouncementID) ON DELETE CASCADE
);

-- Patient-uploaded medical records (e.g. lab results, prior prescriptions).
-- Files are stored outside the webroot and served only through
-- patient/record-stream.php after an ownership check, same pattern as the
-- consultation audio recordings.
CREATE TABLE IF NOT EXISTS MedicalRecords (
    RecordID      INT AUTO_INCREMENT PRIMARY KEY,
    PatientID     INT NOT NULL,
    Title         VARCHAR(150) NOT NULL,
    Category      VARCHAR(30)  NOT NULL DEFAULT 'Other', -- Lab result, Imaging, Prescription, Medical certificate, Other
    Description   TEXT NULL,
    FilePath      VARCHAR(255) NOT NULL,
    FileType      VARCHAR(50) NOT NULL,
    UploadedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medicalrecords_patient FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    INDEX idx_medicalrecords_patient (PatientID)
);

-- Personal notification feed for one patient (unlike the clinic-shared
-- Notifications table used by staff, read state here is per patient).
CREATE TABLE IF NOT EXISTS PatientNotifications (
    NotificationID       INT AUTO_INCREMENT PRIMARY KEY,
    PatientID            INT NOT NULL,
    AnnouncementID INT NULL,
    Message              VARCHAR(255) NOT NULL,
    RelatedAppointmentID INT NULL,
    IsRead               TINYINT NOT NULL DEFAULT 0,
    CreatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_patientnotifications_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_patientnotifications_appointment FOREIGN KEY (RelatedAppointmentID) REFERENCES Appointments (AppointmentID),
    INDEX idx_patientnotifications_patient_read (PatientID, IsRead),
    CONSTRAINT fk_patient_notice_announcement FOREIGN KEY (AnnouncementID) REFERENCES Announcements(AnnouncementID) ON DELETE CASCADE
);

-- Patient feedback for one completed visit. One row per appointment --
-- patients can only rate a visit once it's Completed, and only once.
CREATE TABLE IF NOT EXISTS Feedback (
    FeedbackID    INT AUTO_INCREMENT PRIMARY KEY,
    AppointmentID INT NOT NULL,
    PatientID     INT NOT NULL,
    ClinicID      INT NOT NULL,
    PhysicianID   INT NULL,
    Rating        TINYINT NOT NULL,
    Comment       TEXT NULL,
    CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_feedback_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_feedback_clinic      FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_feedback_physician   FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    UNIQUE KEY uq_feedback_appointment (AppointmentID),
    INDEX idx_feedback_clinic (ClinicID)
);

-- Append-only ledger backing Users.WalletBalance -- every top-up, booking
-- payment, and cancellation refund gets its own row so the balance can
-- always be reconstructed/audited, never just overwritten.
CREATE TABLE IF NOT EXISTS WalletTransactions (
    TransactionID        INT AUTO_INCREMENT PRIMARY KEY,
    PatientID             INT NOT NULL,
    Type                  VARCHAR(20) NOT NULL, -- TopUp, BookingPayment, Refund
    Amount                DECIMAL(10,2) NOT NULL,
    BalanceAfter          DECIMAL(10,2) NOT NULL,
    RelatedAppointmentID  INT NULL,
    Description           VARCHAR(255) NULL,
    CreatedAt             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallettx_patient     FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_wallettx_appointment FOREIGN KEY (RelatedAppointmentID) REFERENCES Appointments (AppointmentID),
    INDEX idx_wallettx_patient (PatientID, CreatedAt)
);

-- Upgrade path for databases created before the medical-info columns
-- existed (CREATE TABLE IF NOT EXISTS above won't add them).
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS BloodType              VARCHAR(5)   NULL,
    ADD COLUMN IF NOT EXISTS Allergies              VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS EmergencyContactName   VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS EmergencyContactNumber VARCHAR(20)  NULL;

-- Existing users get their IDNumber backfilled by assignUserIdNumber().
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS IDNumber CHAR(10) NULL AFTER UserID,
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_idnumber (IDNumber);

ALTER TABLE Clinic
    ADD COLUMN IF NOT EXISTS Specialties VARCHAR(255) NULL AFTER Description,
    ADD COLUMN IF NOT EXISTS OpenDays    VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5,6' AFTER Specialties,
    ADD COLUMN IF NOT EXISTS OpenTime    TIME         NOT NULL DEFAULT '08:00:00' AFTER OpenDays,
    ADD COLUMN IF NOT EXISTS CloseTime   TIME         NOT NULL DEFAULT '17:00:00' AFTER OpenTime;

INSERT INTO Clinic (ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl)
VALUES
    ('Sunrise Medical Center', '123 Rizal Ave, Manila', '+63 2 8123 4567', 300.00, 'sunrise.jpg'),
    ('Northgate Family Clinic', '456 Aurora Blvd, Quezon City', '+63 2 8987 6543', 250.00, 'northgate.jpg'),
    ('Bayview Health Hub', '789 Roxas Blvd, Pasay', '+63 2 8555 0102', 350.00, 'bayview.jpg'),
    ('Eastside Wellness Clinic', '321 Marcos Highway, Antipolo', '+63 2 8222 7788', 280.00, 'eastside.jpg');

ALTER TABLE MedicalRecords
    ADD COLUMN IF NOT EXISTS Category VARCHAR(30) NOT NULL DEFAULT 'Other' AFTER Title;

-- Announcements: optional source clinic (NULL = HealthQueue platform-wide),
-- a category for the patient-side tag, and an optional date the announcement
-- affects (patients booked at that clinic on that date see it pinned).
ALTER TABLE Announcements
    ADD COLUMN IF NOT EXISTS ClinicID    INT         NULL AFTER PostedByUserID,
    ADD COLUMN IF NOT EXISTS Category    VARCHAR(30) NOT NULL DEFAULT 'General' AFTER Title, -- General, Closure, Schedule change, Event, Health advisory
    ADD COLUMN IF NOT EXISTS AffectsDate DATE        NULL AFTER Audience;
ALTER TABLE Announcements
    ADD CONSTRAINT fk_announcements_clinic FOREIGN KEY IF NOT EXISTS (ClinicID) REFERENCES Clinic (ClinicID);

-- When the patient last opened Announcements (drives the "New" dots/count).
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS AnnouncementsSeenAt DATETIME NULL;

-- Announcements a patient has deleted from their own view. Announcements are
-- shared, so "delete" only hides it for that user.
CREATE TABLE IF NOT EXISTS AnnouncementDismissals (
    UserID         INT NOT NULL,
    AnnouncementID INT NOT NULL,
    DismissedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (UserID, AnnouncementID),
    CONSTRAINT fk_ann_dismiss_user FOREIGN KEY (UserID) REFERENCES Users (UserID),
    CONSTRAINT fk_ann_dismiss_announcement FOREIGN KEY (AnnouncementID) REFERENCES Announcements (AnnouncementID) ON DELETE CASCADE
);

-- Reason the clinic gave when declining a request (shown to the patient).
-- Separate from CancellationReason, which is the patient's own reason.
ALTER TABLE Appointments
    ADD COLUMN IF NOT EXISTS DeclineReason VARCHAR(255) NULL AFTER CancellationReason;

ALTER TABLE Appointments MODIFY COLUMN AppointmentTime TIME NULL;

-- Queue ordering + priority. Position lets front-desk staff reorder the
-- waiting line (NULL = natural order, QueueNumber * 10); Priority tags
-- walk-ins such as seniors/PWD/pregnant who are placed ahead of the line.
ALTER TABLE Queue
    ADD COLUMN IF NOT EXISTS Position INT         NULL AFTER QueueNumber,
    ADD COLUMN IF NOT EXISTS Priority VARCHAR(20) NULL AFTER Position,
    ADD COLUMN IF NOT EXISTS ScheduledNumber INT NULL AFTER QueueNumber,
    ADD COLUMN IF NOT EXISTS RegularNumber INT NULL AFTER ScheduledNumber;

-- Optional photo attached to an announcement (file in assets/uploads/announcements).
ALTER TABLE Announcements
    ADD COLUMN IF NOT EXISTS PhotoPath VARCHAR(255) NULL AFTER Message;

-- Weekly schedule extras: optional booking capacity per hour, and an Active
-- flag so a physician can switch a whole day off without losing its hours.
ALTER TABLE PhysicianAvailability
    ADD COLUMN IF NOT EXISTS PatientsPerHour TINYINT NULL AFTER EndTime,
    ADD COLUMN IF NOT EXISTS Active TINYINT NOT NULL DEFAULT 1 AFTER PatientsPerHour;

-- Physician availability by calendar date (replaces the weekly
-- PhysicianAvailability schedule). A date with hour blocks is bookable; a
-- row with IsDayOff = 1 marks a day off; a date with no rows is "not set"
-- and not bookable.
CREATE TABLE IF NOT EXISTS PhysicianDateAvailability (
    SlotID          INT AUTO_INCREMENT PRIMARY KEY,
    PhysicianID     INT NOT NULL,
    AvailDate       DATE NOT NULL,
    StartTime       TIME NULL,
    EndTime         TIME NULL,
    PatientsPerHour TINYINT NULL,
    IsDayOff        TINYINT NOT NULL DEFAULT 0,
    CreatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dateavail_physician FOREIGN KEY (PhysicianID) REFERENCES Users (UserID),
    INDEX idx_dateavail_physician_date (PhysicianID, AvailDate)
);

-- Structured consultation notes (SOAP sections, vitals, medicine rows) kept
-- as JSON alongside the plain-text ClinicalNotes/PrescriptionText, which are
-- still written for the patient-facing record pages.
ALTER TABLE ConsultationVersions
    ADD COLUMN IF NOT EXISTS NotesJson TEXT NULL AFTER PrescriptionText;

-- Whether the patient consented to the consultation being recorded.
ALTER TABLE Consultations
    ADD COLUMN IF NOT EXISTS RecordingConsent TINYINT NOT NULL DEFAULT 0 AFTER AudioDurationSeconds;

-- How the (simulated) booking fee was paid: wallet, gcash, maya or card.
ALTER TABLE Appointments
    ADD COLUMN IF NOT EXISTS BookingPaymentMethod VARCHAR(20) NULL AFTER BookingFeePaidAt;
CREATE TABLE IF NOT EXISTS PatientProfileDetails (
    UserID INT PRIMARY KEY,
    Details TEXT NOT NULL,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (UserID) REFERENCES Users(UserID) ON DELETE CASCADE
);

-- Semaphore SMS outbox
CREATE TABLE IF NOT EXISTS SmsOutbox (
    SmsID INT AUTO_INCREMENT PRIMARY KEY,
    PatientID INT NOT NULL,
    AppointmentID INT NOT NULL,
    EventKey VARCHAR(120) NOT NULL UNIQUE,
    EventType VARCHAR(30) NOT NULL,
    Message VARCHAR(320) NOT NULL,
    Status VARCHAR(20) NOT NULL DEFAULT 'pending',
    Attempts INT NOT NULL DEFAULT 0,
    ProviderMessageID VARCHAR(80) NULL,
    ProviderStatus VARCHAR(80) NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sms_pending (Status, CreatedAt)
);
CREATE TABLE IF NOT EXISTS ClinicPhoto (
    PhotoID INT AUTO_INCREMENT PRIMARY KEY,
    ClinicID INT NOT NULL,
    Filename VARCHAR(255) NOT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clinic_photo (ClinicID, PhotoID),
    CONSTRAINT fk_clinic_photo_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID) ON DELETE CASCADE
);
ALTER TABLE Clinic ADD COLUMN IF NOT EXISTS Latitude DECIMAL(10,7) NULL;
ALTER TABLE Clinic ADD COLUMN IF NOT EXISTS Longitude DECIMAL(10,7) NULL;
ALTER TABLE ClinicPhoto ADD COLUMN IF NOT EXISTS SortOrder INT NOT NULL DEFAULT 0;

-- Reliable queue reminder email tracking.
CREATE TABLE IF NOT EXISTS QueueEmailOutbox (
    EmailID INT AUTO_INCREMENT PRIMARY KEY,
    QueueID INT NOT NULL,
    PatientID INT NOT NULL,
    AppointmentID INT NOT NULL,
    EventType VARCHAR(20) NOT NULL,
    Status VARCHAR(20) NOT NULL DEFAULT 'pending',
    Attempts INT NOT NULL DEFAULT 0,
    LastError VARCHAR(200) NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_queue_email_event (QueueID, EventType),
    INDEX idx_queue_email_pending (Status, UpdatedAt),
    FOREIGN KEY (QueueID) REFERENCES Queue(QueueID) ON DELETE CASCADE,
    FOREIGN KEY (PatientID) REFERENCES Users(UserID) ON DELETE CASCADE,
    FOREIGN KEY (AppointmentID) REFERENCES Appointments(AppointmentID) ON DELETE CASCADE
);

-- Capstone simulation only. These records never initiate bank transfers.
CREATE TABLE IF NOT EXISTS DemoSettlements (
 SettlementID INT AUTO_INCREMENT PRIMARY KEY,
 RequestKey VARCHAR(64) NOT NULL,
 Reference VARCHAR(40) NOT NULL,
 GrossAmount DECIMAL(12,2) NOT NULL,
 CreatedByUserID INT NOT NULL,
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_demo_settlement_request (RequestKey),
 UNIQUE KEY uq_demo_settlement_reference (Reference),
 CONSTRAINT fk_demo_settlement_admin FOREIGN KEY (CreatedByUserID) REFERENCES Users(UserID)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS DemoSettlementItems (
 SettlementID INT NOT NULL,
 PaymentID INT NOT NULL,
 PRIMARY KEY (SettlementID,PaymentID),
 UNIQUE KEY uq_demo_settled_payment (PaymentID),
 CONSTRAINT fk_demo_item_settlement FOREIGN KEY (SettlementID) REFERENCES DemoSettlements(SettlementID),
 CONSTRAINT fk_demo_item_payment FOREIGN KEY (PaymentID) REFERENCES AppointmentPayments(PaymentID)
) ENGINE=InnoDB;

-- Simulated physician withdrawals only. No external transfers.
CREATE TABLE IF NOT EXISTS PhysicianWithdrawals (
 WithdrawalID INT AUTO_INCREMENT PRIMARY KEY,
 Reference VARCHAR(40) NOT NULL UNIQUE,
 RequestKey VARCHAR(64) NOT NULL UNIQUE,
 PhysicianID INT NOT NULL,
 Amount DECIMAL(12,2) NOT NULL,
 PayoutMethod VARCHAR(20) NOT NULL,
 AccountName VARCHAR(150) NOT NULL,
 AccountNumber VARCHAR(34) NOT NULL,
 BankName VARCHAR(100) NULL,
 Status VARCHAR(20) NOT NULL DEFAULT 'Pending',
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ReviewedAt DATETIME NULL,
 ReviewedByUserID INT NULL,
 INDEX idx_physician_withdrawals_status (PhysicianID,Status),
 CONSTRAINT fk_withdrawal_physician FOREIGN KEY (PhysicianID) REFERENCES Users(UserID),
 CONSTRAINT fk_withdrawal_reviewer FOREIGN KEY (ReviewedByUserID) REFERENCES Users(UserID)
) ENGINE=InnoDB;

-- Audit protections: idempotent top-ups, throttling, and explicit AI publication.
ALTER TABLE WalletTransactions ADD COLUMN IF NOT EXISTS RequestKey CHAR(64) NULL;
ALTER TABLE WalletTransactions ADD UNIQUE INDEX IF NOT EXISTS uq_wallet_request_key (RequestKey);
CREATE TABLE IF NOT EXISTS RequestRateLimits (
 BucketKey CHAR(64) PRIMARY KEY,
 WindowStart DATETIME NOT NULL,
 Attempts INT NOT NULL DEFAULT 1,
 INDEX idx_rate_window (WindowStart)
) ENGINE=InnoDB;
ALTER TABLE AITranscriptVersions ADD COLUMN IF NOT EXISTS ApprovedAt DATETIME NULL;
ALTER TABLE AITranscriptVersions ADD COLUMN IF NOT EXISTS ApprovedByPhysicianID INT NULL;
ALTER TABLE AITranscriptVersions ADD COLUMN IF NOT EXISTS ApprovedVersionID INT NULL;

ALTER TABLE AITranscriptVersions ADD FOREIGN KEY IF NOT EXISTS fk_ai_approved_physician (ApprovedByPhysicianID) REFERENCES Users(UserID);
ALTER TABLE AITranscriptVersions ADD FOREIGN KEY IF NOT EXISTS fk_ai_approved_version (ApprovedVersionID) REFERENCES ConsultationVersions(VersionID);

-- Clinic inquiries now request staff count; legacy physician counts are preserved.
ALTER TABLE ClinicRegistrationInquiry ADD COLUMN IF NOT EXISTS StaffCount INT NULL AFTER PhysiciansCount;

ALTER TABLE Users ADD COLUMN IF NOT EXISTS PhysicianSpecialty VARCHAR(100) NULL;
-- One financial owner per clinic. Clinical assignments remain unchanged.
CREATE TABLE IF NOT EXISTS PhysicianAccountOwners (
 ClinicID INT PRIMARY KEY,
 MainPhysicianID INT NOT NULL UNIQUE,
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID),
 FOREIGN KEY (MainPhysicianID) REFERENCES Users(UserID)
);
INSERT IGNORE INTO PhysicianAccountOwners (ClinicID,MainPhysicianID)
 SELECT u.ClinicID,MIN(u.UserID) FROM Users u JOIN Roles r ON r.RoleID=u.RoleID
 WHERE r.RoleName='Physician' AND u.ClinicID IS NOT NULL AND u.DeletedAt IS NULL GROUP BY u.ClinicID;
ALTER TABLE AppointmentPayments ADD COLUMN IF NOT EXISTS FinancialOwnerUserID INT NULL,
 ADD INDEX IF NOT EXISTS idx_payment_financial_owner (FinancialOwnerUserID);
ALTER TABLE PhysicianWithdrawals ADD COLUMN IF NOT EXISTS FinancialOwnerUserID INT NULL,
 ADD INDEX IF NOT EXISTS idx_withdrawal_financial_owner (FinancialOwnerUserID);
-- Preserve the performing physician and original requesting physician; snapshot pooled ownership.
UPDATE AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID
 LEFT JOIN PhysicianAccountOwners o ON o.ClinicID=a.ClinicID
 SET p.FinancialOwnerUserID=COALESCE(o.MainPhysicianID,a.PhysicianID) WHERE p.FinancialOwnerUserID IS NULL;
UPDATE PhysicianWithdrawals w JOIN Users u ON u.UserID=w.PhysicianID
 LEFT JOIN PhysicianAccountOwners o ON o.ClinicID=u.ClinicID
 SET w.FinancialOwnerUserID=COALESCE(o.MainPhysicianID,w.PhysicianID) WHERE w.FinancialOwnerUserID IS NULL;
ALTER TABLE AppointmentPayments ADD FOREIGN KEY IF NOT EXISTS fk_payment_financial_owner (FinancialOwnerUserID) REFERENCES Users(UserID);
ALTER TABLE PhysicianWithdrawals ADD FOREIGN KEY IF NOT EXISTS fk_withdrawal_financial_owner (FinancialOwnerUserID) REFERENCES Users(UserID);

-- NULL and empty optional numbers do not collide. Populated numbers are unique across roles.
ALTER TABLE Users
 ADD COLUMN IF NOT EXISTS AccountMobileNumber VARCHAR(20) GENERATED ALWAYS AS (NULLIF(TRIM(ContactNumber),'')) PERSISTENT,
 ADD UNIQUE INDEX IF NOT EXISTS uq_users_account_mobile (AccountMobileNumber);

ALTER TABLE AITranscriptVersions
 ADD COLUMN IF NOT EXISTS SourceTranscriptVersionID INT NULL,
 ADD INDEX IF NOT EXISTS idx_ai_source_version (SourceTranscriptVersionID),
 ADD FOREIGN KEY IF NOT EXISTS fk_ai_source_version (SourceTranscriptVersionID) REFERENCES AITranscriptVersions(TranscriptVersionID);

ALTER TABLE ClinicRegistrationInquiry ADD COLUMN IF NOT EXISTS BaseConsultationFee DECIMAL(10,2) NULL AFTER StaffCount;

-- Time-based queue: a late patient yields to one named successor without changing booked time.
ALTER TABLE Queue ADD COLUMN IF NOT EXISTS LateAfterQueueID INT NULL, ADD COLUMN IF NOT EXISTS LastLateNoticeAt DATETIME NULL;

-- Staff-confirmed arrival and one-time scheduled appointment grace extension.
ALTER TABLE Queue ADD COLUMN IF NOT EXISTS ArrivedAt DATETIME NULL, ADD COLUMN IF NOT EXISTS GraceExtendedAt DATETIME NULL, ADD COLUMN IF NOT EXISTS GraceRescheduleRequestedAt DATETIME NULL;
ALTER TABLE Queue ADD COLUMN IF NOT EXISTS GracePolicyEnabledAt DATETIME NULL;

ALTER TABLE Appointments ADD COLUMN IF NOT EXISTS BookingFeeAmount DECIMAL(10,2) NULL;
CREATE TABLE IF NOT EXISTS ClinicFeeRequests (
 RequestID INT AUTO_INCREMENT PRIMARY KEY,
 ClinicID INT NOT NULL,
 RequestedBy INT NOT NULL,
 CurrentFee DECIMAL(10,2) NOT NULL,
 ProposedFee DECIMAL(10,2) NOT NULL,
 EffectiveDate DATE NOT NULL,
 Reason VARCHAR(1000) NOT NULL,
 Status ENUM('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
 RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ReviewedBy INT NULL,
 ReviewedAt DATETIME NULL,
 UNIQUE KEY uq_fee_request_replay (ClinicID,RequestID),
 INDEX idx_clinic_fee_effective (ClinicID,Status,EffectiveDate),
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID),
 FOREIGN KEY (RequestedBy) REFERENCES Users(UserID),
 FOREIGN KEY (ReviewedBy) REFERENCES Users(UserID)
);
-- Freeze pre-existing bookings before introducing fee changes. Paid wallet evidence wins.
UPDATE Appointments a JOIN Clinic c ON c.ClinicID=a.ClinicID
LEFT JOIN (SELECT RelatedAppointmentID,SUM(Amount) Paid FROM WalletTransactions WHERE Type='BookingPayment' GROUP BY RelatedAppointmentID) t ON t.RelatedAppointmentID=a.AppointmentID
SET a.BookingFeeAmount=COALESCE(t.Paid,c.BaseConsultationFee) WHERE a.BookingFeeAmount IS NULL;

CREATE TABLE IF NOT EXISTS PhysicianNotifications (
 NotificationID INT AUTO_INCREMENT PRIMARY KEY,
 PhysicianID INT NOT NULL,
 FeeRequestID INT NULL,
 AnnouncementID INT NULL,
 Message TEXT NOT NULL,
 IsRead TINYINT NOT NULL DEFAULT 0,
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_physician_fee_notice (FeeRequestID),
 INDEX idx_physician_notice_unread (PhysicianID,IsRead,CreatedAt),
 FOREIGN KEY (PhysicianID) REFERENCES Users(UserID),
 FOREIGN KEY (FeeRequestID) REFERENCES ClinicFeeRequests(RequestID),
 CONSTRAINT fk_physician_notice_announcement FOREIGN KEY (AnnouncementID) REFERENCES Announcements(AnnouncementID) ON DELETE CASCADE
);

ALTER TABLE PhysicianNotifications ADD COLUMN IF NOT EXISTS AnnouncementID INT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_physician_announcement ON PhysicianNotifications(PhysicianID,AnnouncementID);

ALTER TABLE Notifications ADD COLUMN IF NOT EXISTS AnnouncementID INT NULL;
ALTER TABLE PatientNotifications ADD COLUMN IF NOT EXISTS AnnouncementID INT NULL;
ALTER TABLE Notifications MODIFY Message TEXT NOT NULL;
ALTER TABLE PatientNotifications MODIFY Message TEXT NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_announcement ON Notifications(ClinicID,AnnouncementID);
CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_announcement ON PatientNotifications(PatientID,AnnouncementID);

ALTER TABLE Users
 ADD COLUMN IF NOT EXISTS IsWalkInAccount TINYINT NOT NULL DEFAULT 0,
 MODIFY COLUMN AccountMobileNumber VARCHAR(20) GENERATED ALWAYS AS (CASE WHEN IsWalkInAccount=1 THEN NULL ELSE NULLIF(TRIM(ContactNumber),'') END) PERSISTENT;

CREATE TABLE IF NOT EXISTS PlatformDemoBankTransfers (
 TransferID INT AUTO_INCREMENT PRIMARY KEY,
 PaymentID INT NOT NULL,
 Reference VARCHAR(50) NOT NULL,
 Amount DECIMAL(12,2) NOT NULL,
 BankLabel VARCHAR(100) NOT NULL DEFAULT 'HealthQueue Demo Bank',
 AccountLastFour CHAR(4) NOT NULL DEFAULT '0000',
 Status VARCHAR(30) NOT NULL DEFAULT 'CompletedSimulated',
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_platform_bank_payment (PaymentID),
 UNIQUE KEY uq_platform_bank_reference (Reference),
 FOREIGN KEY (PaymentID) REFERENCES AppointmentPayments(PaymentID)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS VisitCompletionReviews (
 AppointmentID INT PRIMARY KEY,
 PatientID INT NOT NULL,
 ClinicID INT NOT NULL,
 FinalizedVersionID INT NOT NULL,
 Status VARCHAR(24) NOT NULL DEFAULT 'AwaitingPatient',
 IssueText TEXT NULL,
 RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PatientRespondedAt DATETIME NULL,
 CompletedAt DATETIME NULL,
 CompletedByUserID INT NULL,
 CompletionSource VARCHAR(20) NULL,
 StaffResolution TEXT NULL,
 INDEX idx_visit_completion_clinic (ClinicID,Status),
 INDEX idx_visit_completion_patient (PatientID,Status),
 FOREIGN KEY (AppointmentID) REFERENCES Appointments(AppointmentID) ON DELETE CASCADE,
 FOREIGN KEY (PatientID) REFERENCES Users(UserID),
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID),
 FOREIGN KEY (FinalizedVersionID) REFERENCES ConsultationVersions(VersionID),
 FOREIGN KEY (CompletedByUserID) REFERENCES Users(UserID)
) ENGINE=InnoDB;

ALTER TABLE Queue ADD COLUMN IF NOT EXISTS FirstCalledAt DATETIME NULL;
CREATE TABLE IF NOT EXISTS AITranscriptEvaluations (
 EvaluationID INT AUTO_INCREMENT PRIMARY KEY,
 TranscriptVersionID INT NOT NULL UNIQUE,
 PhysicianID INT NOT NULL,
 ClinicID INT NOT NULL,
 Language VARCHAR(30) NOT NULL,
 ReferenceText MEDIUMTEXT NOT NULL,
 ReferenceHash CHAR(64) NOT NULL,
 HypothesisHash CHAR(64) NOT NULL,
 ReferenceWords INT NOT NULL,
 HypothesisWords INT NOT NULL,
 Substitutions INT NOT NULL,
 Deletions INT NOT NULL,
 Insertions INT NOT NULL,
 WordErrorRate DECIMAL(9,4) NOT NULL,
 EvaluatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ai_evaluation_scope (ClinicID,PhysicianID,EvaluatedAt),
 FOREIGN KEY (TranscriptVersionID) REFERENCES AITranscriptVersions(TranscriptVersionID),
 FOREIGN KEY (PhysicianID) REFERENCES Users(UserID),
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS MedicalRecordAccessRequests (
 RequestID INT AUTO_INCREMENT PRIMARY KEY,
 AppointmentID INT NOT NULL,
 PatientID INT NOT NULL,
 PhysicianID INT NOT NULL,
 Status VARCHAR(20) NOT NULL DEFAULT 'Pending',
 RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 RespondedAt DATETIME NULL,
 EndedAt DATETIME NULL,
 UNIQUE KEY uq_record_access_visit (AppointmentID,PhysicianID),
 INDEX idx_record_access_patient (PatientID,Status),
 FOREIGN KEY (AppointmentID) REFERENCES Appointments(AppointmentID),
 FOREIGN KEY (PatientID) REFERENCES Users(UserID),
 FOREIGN KEY (PhysicianID) REFERENCES Users(UserID)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS MedicalRecordAccessFiles (
 RequestID INT NOT NULL,
 RecordID INT NOT NULL,
 PRIMARY KEY(RequestID,RecordID),
 FOREIGN KEY(RequestID) REFERENCES MedicalRecordAccessRequests(RequestID) ON DELETE CASCADE,
 FOREIGN KEY(RecordID) REFERENCES MedicalRecords(RecordID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS MedicalRecordAccessNotifications (
 NotificationID INT NOT NULL PRIMARY KEY,
 RequestID INT NOT NULL UNIQUE,
 FOREIGN KEY(NotificationID) REFERENCES PatientNotifications(NotificationID) ON DELETE CASCADE,
 FOREIGN KEY(RequestID) REFERENCES MedicalRecordAccessRequests(RequestID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS VisitCompletionNotifications (
 NotificationID INT NOT NULL PRIMARY KEY,
 AppointmentID INT NOT NULL,
 FinalizedVersionID INT NOT NULL,
 UNIQUE KEY uq_visit_confirmation_notice(AppointmentID,FinalizedVersionID),
 FOREIGN KEY(NotificationID) REFERENCES PatientNotifications(NotificationID) ON DELETE CASCADE,
 FOREIGN KEY(AppointmentID) REFERENCES Appointments(AppointmentID),
 FOREIGN KEY(FinalizedVersionID) REFERENCES ConsultationVersions(VersionID)
) ENGINE=InnoDB;

-- Additive relationships for previously standalone tables. Existing unknown tracking links remain NULL.
-- Prefer scripts/setup-standalone-links.php --apply for preflight and a private backup.
ALTER TABLE ClinicRegistrationInquiry ADD COLUMN IF NOT EXISTS ApprovedClinicID INT NULL DEFAULT NULL;
ALTER TABLE SignupEmailVerifications ADD COLUMN IF NOT EXISTS UserID INT NULL DEFAULT NULL;
ALTER TABLE RequestRateLimits ADD COLUMN IF NOT EXISTS UserID INT NULL DEFAULT NULL;
ALTER TABLE SmsOutbox MODIFY COLUMN PatientID INT NULL DEFAULT NULL, MODIFY COLUMN AppointmentID INT NULL DEFAULT NULL;
ALTER TABLE ClinicRegistrationInquiry ADD CONSTRAINT fk_inquiry_approved_clinic FOREIGN KEY(ApprovedClinicID) REFERENCES Clinic(ClinicID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE SignupEmailVerifications ADD CONSTRAINT fk_signup_verification_user FOREIGN KEY(UserID) REFERENCES Users(UserID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE RequestRateLimits ADD CONSTRAINT fk_rate_limit_user FOREIGN KEY(UserID) REFERENCES Users(UserID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE SmsOutbox ADD CONSTRAINT fk_sms_patient FOREIGN KEY(PatientID) REFERENCES Users(UserID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE SmsOutbox ADD CONSTRAINT fk_sms_appointment FOREIGN KEY(AppointmentID) REFERENCES Appointments(AppointmentID) ON DELETE SET NULL ON UPDATE RESTRICT;

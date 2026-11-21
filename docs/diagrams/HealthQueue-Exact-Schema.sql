-- HealthQueue exact MariaDB table definitions; structure only.
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `aitranscriptevaluations` (
  `EvaluationID` int(11) NOT NULL AUTO_INCREMENT,
  `TranscriptVersionID` int(11) NOT NULL,
  `PhysicianID` int(11) NOT NULL,
  `ClinicID` int(11) NOT NULL,
  `Language` varchar(30) NOT NULL,
  `ReferenceText` mediumtext NOT NULL,
  `ReferenceHash` char(64) NOT NULL,
  `HypothesisHash` char(64) NOT NULL,
  `ReferenceWords` int(11) NOT NULL,
  `HypothesisWords` int(11) NOT NULL,
  `Substitutions` int(11) NOT NULL,
  `Deletions` int(11) NOT NULL,
  `Insertions` int(11) NOT NULL,
  `WordErrorRate` decimal(9,4) NOT NULL,
  `EvaluatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`EvaluationID`),
  UNIQUE KEY `TranscriptVersionID` (`TranscriptVersionID`),
  KEY `idx_ai_evaluation_scope` (`ClinicID`,`PhysicianID`,`EvaluatedAt`),
  KEY `PhysicianID` (`PhysicianID`),
  CONSTRAINT `aitranscriptevaluations_ibfk_1` FOREIGN KEY (`TranscriptVersionID`) REFERENCES `aitranscriptversions` (`TranscriptVersionID`),
  CONSTRAINT `aitranscriptevaluations_ibfk_2` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `aitranscriptevaluations_ibfk_3` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `aitranscriptversions` (
  `TranscriptVersionID` int(11) NOT NULL AUTO_INCREMENT,
  `ConsultationID` int(11) NOT NULL,
  `TranscriptType` varchar(30) NOT NULL,
  `TranscriptText` text NOT NULL,
  `WordErrorRate` decimal(5,2) DEFAULT NULL,
  `GeneratedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ApprovedAt` datetime DEFAULT NULL,
  `ApprovedByPhysicianID` int(11) DEFAULT NULL,
  `ApprovedVersionID` int(11) DEFAULT NULL,
  `SourceTranscriptVersionID` int(11) DEFAULT NULL,
  PRIMARY KEY (`TranscriptVersionID`),
  KEY `idx_aitranscripts_consultation` (`ConsultationID`,`TranscriptType`,`GeneratedAt`),
  KEY `fk_ai_approved_physician` (`ApprovedByPhysicianID`),
  KEY `fk_ai_approved_version` (`ApprovedVersionID`),
  KEY `idx_ai_source_version` (`SourceTranscriptVersionID`),
  CONSTRAINT `fk_ai_approved_physician` FOREIGN KEY (`ApprovedByPhysicianID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_ai_approved_version` FOREIGN KEY (`ApprovedVersionID`) REFERENCES `consultationversions` (`VersionID`),
  CONSTRAINT `fk_ai_source_version` FOREIGN KEY (`SourceTranscriptVersionID`) REFERENCES `aitranscriptversions` (`TranscriptVersionID`),
  CONSTRAINT `fk_aitranscripts_consultation` FOREIGN KEY (`ConsultationID`) REFERENCES `consultations` (`ConsultationID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcementdismissals` (
  `UserID` int(11) NOT NULL,
  `AnnouncementID` int(11) NOT NULL,
  `DismissedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`UserID`,`AnnouncementID`),
  KEY `fk_ann_dismiss_announcement` (`AnnouncementID`),
  CONSTRAINT `fk_ann_dismiss_announcement` FOREIGN KEY (`AnnouncementID`) REFERENCES `announcements` (`AnnouncementID`) ON DELETE CASCADE,
  CONSTRAINT `fk_ann_dismiss_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcements` (
  `AnnouncementID` int(11) NOT NULL AUTO_INCREMENT,
  `PostedByUserID` int(11) NOT NULL,
  `ClinicID` int(11) DEFAULT NULL,
  `Title` varchar(150) NOT NULL,
  `Category` varchar(30) NOT NULL DEFAULT 'General',
  `Message` text NOT NULL,
  `PhotoPath` varchar(255) DEFAULT NULL,
  `Audience` varchar(20) NOT NULL DEFAULT 'Everyone',
  `AffectsDate` date DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AnnouncementID`),
  KEY `fk_announcements_user` (`PostedByUserID`),
  KEY `fk_announcements_clinic` (`ClinicID`),
  CONSTRAINT `fk_announcements_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_announcements_user` FOREIGN KEY (`PostedByUserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointmentpayments` (
  `PaymentID` int(11) NOT NULL AUTO_INCREMENT,
  `AppointmentID` int(11) NOT NULL,
  `GrossAmount` decimal(10,2) NOT NULL,
  `PhysicianRevenue` decimal(10,2) NOT NULL,
  `PlatformCommission` decimal(10,2) NOT NULL,
  `PaymentStatus` varchar(20) NOT NULL DEFAULT 'Paid',
  `PaidAt` datetime NOT NULL DEFAULT current_timestamp(),
  `RefundedAt` datetime DEFAULT NULL,
  `FinancialOwnerUserID` int(11) DEFAULT NULL,
  PRIMARY KEY (`PaymentID`),
  UNIQUE KEY `uq_payment_appointment` (`AppointmentID`),
  KEY `idx_payments_status_paidat` (`PaymentStatus`,`PaidAt`),
  KEY `idx_payment_financial_owner` (`FinancialOwnerUserID`),
  CONSTRAINT `fk_payment_financial_owner` FOREIGN KEY (`FinancialOwnerUserID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_payments_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointmentreschedulerequests` (
  `RequestID` int(11) NOT NULL AUTO_INCREMENT,
  `AppointmentID` int(11) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `ClinicID` int(11) NOT NULL,
  `RequestedDate` date NOT NULL,
  `RequestedTime` time NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Pending',
  `RequestedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`RequestID`),
  KEY `fk_reschedule_appointment` (`AppointmentID`),
  KEY `fk_reschedule_patient` (`PatientID`),
  KEY `fk_reschedule_reviewer` (`ReviewedBy`),
  KEY `idx_reschedule_clinic_status` (`ClinicID`,`Status`,`RequestedAt`),
  CONSTRAINT `fk_reschedule_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_reschedule_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_reschedule_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_reschedule_reviewer` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointments` (
  `AppointmentID` int(11) NOT NULL AUTO_INCREMENT,
  `PatientID` int(11) NOT NULL,
  `ClinicID` int(11) NOT NULL,
  `PhysicianID` int(11) DEFAULT NULL,
  `AppointmentDate` date NOT NULL,
  `AppointmentTime` time DEFAULT NULL,
  `Concern` text DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Pending',
  `BookingFeePaid` tinyint(4) NOT NULL DEFAULT 0,
  `BookingFeePaidAt` datetime DEFAULT NULL,
  `BookingPaymentMethod` varchar(20) DEFAULT NULL,
  `CancellationReason` varchar(255) DEFAULT NULL,
  `DeclineReason` varchar(255) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `BookingFeeAmount` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`AppointmentID`),
  KEY `fk_appointments_physician` (`PhysicianID`),
  KEY `idx_appointments_patient_date` (`PatientID`,`AppointmentDate`),
  KEY `idx_appointments_clinic_date` (`ClinicID`,`AppointmentDate`),
  CONSTRAINT `fk_appointments_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_appointments_physician` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `auditlogs` (
  `LogID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicID` int(11) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `ActionExecuted` varchar(150) NOT NULL,
  `Description` varchar(255) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`LogID`),
  KEY `fk_auditlogs_clinic` (`ClinicID`),
  KEY `fk_auditlogs_user` (`UserID`),
  KEY `idx_auditlogs_created` (`CreatedAt`),
  CONSTRAINT `fk_auditlogs_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_auditlogs_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinic` (
  `ClinicID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicName` varchar(150) NOT NULL,
  `Address` varchar(255) NOT NULL,
  `ContactNumber` varchar(20) NOT NULL,
  `BaseConsultationFee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `PhotoUrl` varchar(255) DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Specialties` varchar(255) DEFAULT NULL,
  `OpenDays` varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6',
  `OpenTime` time NOT NULL DEFAULT '08:00:00',
  `CloseTime` time NOT NULL DEFAULT '17:00:00',
  `RegistrationDate` datetime NOT NULL DEFAULT current_timestamp(),
  `DeletedAt` datetime DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Active',
  `archived` tinyint(4) NOT NULL DEFAULT 0,
  `Latitude` decimal(10,7) DEFAULT NULL,
  `Longitude` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`ClinicID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinicfeerequests` (
  `RequestID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicID` int(11) NOT NULL,
  `RequestedBy` int(11) NOT NULL,
  `CurrentFee` decimal(10,2) NOT NULL,
  `ProposedFee` decimal(10,2) NOT NULL,
  `EffectiveDate` date NOT NULL,
  `Reason` varchar(1000) NOT NULL,
  `Status` enum('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
  `RequestedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`RequestID`),
  UNIQUE KEY `uq_fee_request_replay` (`ClinicID`,`RequestID`),
  KEY `idx_clinic_fee_effective` (`ClinicID`,`Status`,`EffectiveDate`),
  KEY `RequestedBy` (`RequestedBy`),
  KEY `ReviewedBy` (`ReviewedBy`),
  CONSTRAINT `clinicfeerequests_ibfk_1` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `clinicfeerequests_ibfk_2` FOREIGN KEY (`RequestedBy`) REFERENCES `users` (`UserID`),
  CONSTRAINT `clinicfeerequests_ibfk_3` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinicphoto` (
  `PhotoID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicID` int(11) NOT NULL,
  `Filename` varchar(255) NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `SortOrder` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`PhotoID`),
  KEY `idx_clinic_photo` (`ClinicID`,`PhotoID`),
  CONSTRAINT `fk_clinic_photo_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinicregistrationinquiry` (
  `InquiryID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicName` varchar(150) NOT NULL,
  `ContactName` varchar(150) NOT NULL,
  `Email` varchar(150) NOT NULL,
  `Phone` varchar(30) NOT NULL,
  `City` varchar(150) NOT NULL,
  `PhysiciansCount` int(11) DEFAULT NULL,
  `StaffCount` int(11) DEFAULT NULL,
  `BaseConsultationFee` decimal(10,2) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Pending',
  `SubmittedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ApprovedClinicID` int(11) DEFAULT NULL,
  PRIMARY KEY (`InquiryID`),
  KEY `fk_inquiry_approved_clinic` (`ApprovedClinicID`),
  CONSTRAINT `fk_inquiry_approved_clinic` FOREIGN KEY (`ApprovedClinicID`) REFERENCES `clinic` (`ClinicID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `consultations` (
  `ConsultationID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicID` int(11) NOT NULL,
  `AppointmentID` int(11) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `DoctorID` int(11) NOT NULL,
  `AudioFilePath` varchar(255) DEFAULT NULL,
  `AudioDurationSeconds` int(11) DEFAULT NULL,
  `RecordingConsent` tinyint(4) NOT NULL DEFAULT 0,
  `CompletedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `DeletedAt` datetime DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Finalized',
  `Archived` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ConsultationID`),
  UNIQUE KEY `uq_consultation_appointment` (`AppointmentID`),
  KEY `fk_consultations_clinic` (`ClinicID`),
  KEY `fk_consultations_patient` (`PatientID`),
  KEY `fk_consultations_doctor` (`DoctorID`),
  CONSTRAINT `fk_consultations_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_consultations_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_consultations_doctor` FOREIGN KEY (`DoctorID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_consultations_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `consultationversions` (
  `VersionID` int(11) NOT NULL AUTO_INCREMENT,
  `ConsultationID` int(11) NOT NULL,
  `EditedByPhysicianID` int(11) NOT NULL,
  `RevisionNumber` int(11) NOT NULL,
  `ClinicalNotes` text NOT NULL,
  `PrescriptionText` text DEFAULT NULL,
  `NotesJson` text DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `DeletedAt` datetime DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Active',
  `Archived` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`VersionID`),
  KEY `fk_consultationversions_physician` (`EditedByPhysicianID`),
  KEY `idx_consultationversions_consultation` (`ConsultationID`,`RevisionNumber`),
  CONSTRAINT `fk_consultationversions_consultation` FOREIGN KEY (`ConsultationID`) REFERENCES `consultations` (`ConsultationID`),
  CONSTRAINT `fk_consultationversions_physician` FOREIGN KEY (`EditedByPhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `demosettlementitems` (
  `SettlementID` int(11) NOT NULL,
  `PaymentID` int(11) NOT NULL,
  PRIMARY KEY (`SettlementID`,`PaymentID`),
  UNIQUE KEY `uq_demo_settled_payment` (`PaymentID`),
  CONSTRAINT `fk_demo_item_payment` FOREIGN KEY (`PaymentID`) REFERENCES `appointmentpayments` (`PaymentID`),
  CONSTRAINT `fk_demo_item_settlement` FOREIGN KEY (`SettlementID`) REFERENCES `demosettlements` (`SettlementID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `demosettlements` (
  `SettlementID` int(11) NOT NULL AUTO_INCREMENT,
  `RequestKey` varchar(64) NOT NULL,
  `Reference` varchar(40) NOT NULL,
  `GrossAmount` decimal(12,2) NOT NULL,
  `CreatedByUserID` int(11) NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`SettlementID`),
  UNIQUE KEY `uq_demo_settlement_request` (`RequestKey`),
  UNIQUE KEY `uq_demo_settlement_reference` (`Reference`),
  KEY `fk_demo_settlement_admin` (`CreatedByUserID`),
  CONSTRAINT `fk_demo_settlement_admin` FOREIGN KEY (`CreatedByUserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `emailverificationtokens` (
  `VerificationID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `TokenHash` char(64) NOT NULL,
  `ExpiresAt` datetime NOT NULL,
  `UsedAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`VerificationID`),
  UNIQUE KEY `TokenHash` (`TokenHash`),
  KEY `idx_email_verification_user` (`UserID`),
  CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `feedback` (
  `FeedbackID` int(11) NOT NULL AUTO_INCREMENT,
  `AppointmentID` int(11) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `ClinicID` int(11) NOT NULL,
  `PhysicianID` int(11) DEFAULT NULL,
  `Rating` tinyint(4) NOT NULL,
  `Comment` text DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`FeedbackID`),
  UNIQUE KEY `uq_feedback_appointment` (`AppointmentID`),
  KEY `fk_feedback_patient` (`PatientID`),
  KEY `fk_feedback_physician` (`PhysicianID`),
  KEY `idx_feedback_clinic` (`ClinicID`),
  CONSTRAINT `fk_feedback_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_feedback_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_feedback_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_feedback_physician` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `medicalrecordaccessfiles` (
  `RequestID` int(11) NOT NULL,
  `RecordID` int(11) NOT NULL,
  PRIMARY KEY (`RequestID`,`RecordID`),
  KEY `RecordID` (`RecordID`),
  CONSTRAINT `medicalrecordaccessfiles_ibfk_1` FOREIGN KEY (`RequestID`) REFERENCES `medicalrecordaccessrequests` (`RequestID`) ON DELETE CASCADE,
  CONSTRAINT `medicalrecordaccessfiles_ibfk_2` FOREIGN KEY (`RecordID`) REFERENCES `medicalrecords` (`RecordID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `medicalrecordaccessnotifications` (
  `NotificationID` int(11) NOT NULL,
  `RequestID` int(11) NOT NULL,
  PRIMARY KEY (`NotificationID`),
  UNIQUE KEY `RequestID` (`RequestID`),
  CONSTRAINT `medicalrecordaccessnotifications_ibfk_1` FOREIGN KEY (`NotificationID`) REFERENCES `patientnotifications` (`NotificationID`) ON DELETE CASCADE,
  CONSTRAINT `medicalrecordaccessnotifications_ibfk_2` FOREIGN KEY (`RequestID`) REFERENCES `medicalrecordaccessrequests` (`RequestID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `medicalrecordaccessrequests` (
  `RequestID` int(11) NOT NULL AUTO_INCREMENT,
  `AppointmentID` int(11) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `PhysicianID` int(11) NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Pending',
  `RequestedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `RespondedAt` datetime DEFAULT NULL,
  `EndedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`RequestID`),
  UNIQUE KEY `uq_record_access_visit` (`AppointmentID`,`PhysicianID`),
  KEY `idx_record_access_patient` (`PatientID`,`Status`),
  KEY `PhysicianID` (`PhysicianID`),
  CONSTRAINT `medicalrecordaccessrequests_ibfk_1` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `medicalrecordaccessrequests_ibfk_2` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `medicalrecordaccessrequests_ibfk_3` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `medicalrecords` (
  `RecordID` int(11) NOT NULL AUTO_INCREMENT,
  `PatientID` int(11) NOT NULL,
  `Title` varchar(150) NOT NULL,
  `Category` varchar(30) NOT NULL DEFAULT 'Other',
  `Description` text DEFAULT NULL,
  `FilePath` varchar(255) NOT NULL,
  `FileType` varchar(50) NOT NULL,
  `UploadedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`RecordID`),
  KEY `idx_medicalrecords_patient` (`PatientID`),
  CONSTRAINT `fk_medicalrecords_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `NotificationID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicID` int(11) NOT NULL,
  `Message` text NOT NULL,
  `RelatedAppointmentID` int(11) DEFAULT NULL,
  `IsRead` tinyint(4) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `AnnouncementID` int(11) DEFAULT NULL,
  PRIMARY KEY (`NotificationID`),
  UNIQUE KEY `uq_staff_announcement` (`ClinicID`,`AnnouncementID`),
  KEY `fk_notifications_appointment` (`RelatedAppointmentID`),
  KEY `idx_notifications_clinic_read` (`ClinicID`,`IsRead`),
  KEY `fk_staff_notice_announcement` (`AnnouncementID`),
  CONSTRAINT `fk_notifications_appointment` FOREIGN KEY (`RelatedAppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_notifications_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_staff_notice_announcement` FOREIGN KEY (`AnnouncementID`) REFERENCES `announcements` (`AnnouncementID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `passwordresets` (
  `ResetID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `TokenHash` char(64) NOT NULL,
  `ExpiresAt` datetime NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UsedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`ResetID`),
  UNIQUE KEY `uq_token_hash` (`TokenHash`),
  KEY `fk_password_resets_user` (`UserID`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `patientnotifications` (
  `NotificationID` int(11) NOT NULL AUTO_INCREMENT,
  `PatientID` int(11) NOT NULL,
  `Message` text NOT NULL,
  `RelatedAppointmentID` int(11) DEFAULT NULL,
  `IsRead` tinyint(4) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `AnnouncementID` int(11) DEFAULT NULL,
  PRIMARY KEY (`NotificationID`),
  UNIQUE KEY `uq_patient_announcement` (`PatientID`,`AnnouncementID`),
  KEY `fk_patientnotifications_appointment` (`RelatedAppointmentID`),
  KEY `idx_patientnotifications_patient_read` (`PatientID`,`IsRead`),
  KEY `fk_patient_notice_announcement` (`AnnouncementID`),
  CONSTRAINT `fk_patient_notice_announcement` FOREIGN KEY (`AnnouncementID`) REFERENCES `announcements` (`AnnouncementID`) ON DELETE CASCADE,
  CONSTRAINT `fk_patientnotifications_appointment` FOREIGN KEY (`RelatedAppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_patientnotifications_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `patientprofiledetails` (
  `UserID` int(11) NOT NULL,
  `Details` text NOT NULL,
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`UserID`),
  CONSTRAINT `patientprofiledetails_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `physicianaccountowners` (
  `ClinicID` int(11) NOT NULL,
  `MainPhysicianID` int(11) NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ClinicID`),
  UNIQUE KEY `MainPhysicianID` (`MainPhysicianID`),
  CONSTRAINT `physicianaccountowners_ibfk_1` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `physicianaccountowners_ibfk_2` FOREIGN KEY (`MainPhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `physicianavailability` (
  `AvailabilityID` int(11) NOT NULL AUTO_INCREMENT,
  `PhysicianID` int(11) NOT NULL,
  `DayOfWeek` tinyint(4) NOT NULL,
  `StartTime` time NOT NULL,
  `EndTime` time NOT NULL,
  `PatientsPerHour` tinyint(4) DEFAULT NULL,
  `Active` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`AvailabilityID`),
  KEY `idx_availability_physician` (`PhysicianID`),
  CONSTRAINT `fk_availability_physician` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `physiciandateavailability` (
  `SlotID` int(11) NOT NULL AUTO_INCREMENT,
  `PhysicianID` int(11) NOT NULL,
  `AvailDate` date NOT NULL,
  `StartTime` time DEFAULT NULL,
  `EndTime` time DEFAULT NULL,
  `PatientsPerHour` tinyint(4) DEFAULT NULL,
  `IsDayOff` tinyint(4) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`SlotID`),
  KEY `idx_dateavail_physician_date` (`PhysicianID`,`AvailDate`),
  CONSTRAINT `fk_dateavail_physician` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `physiciannotifications` (
  `NotificationID` int(11) NOT NULL AUTO_INCREMENT,
  `PhysicianID` int(11) NOT NULL,
  `FeeRequestID` int(11) DEFAULT NULL,
  `Message` text NOT NULL,
  `IsRead` tinyint(4) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `AnnouncementID` int(11) DEFAULT NULL,
  PRIMARY KEY (`NotificationID`),
  UNIQUE KEY `uq_physician_fee_notice` (`FeeRequestID`),
  UNIQUE KEY `uq_physician_announcement` (`PhysicianID`,`AnnouncementID`),
  KEY `idx_physician_notice_unread` (`PhysicianID`,`IsRead`,`CreatedAt`),
  KEY `fk_physician_notice_announcement` (`AnnouncementID`),
  CONSTRAINT `fk_physician_notice_announcement` FOREIGN KEY (`AnnouncementID`) REFERENCES `announcements` (`AnnouncementID`) ON DELETE CASCADE,
  CONSTRAINT `physiciannotifications_ibfk_1` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `physiciannotifications_ibfk_2` FOREIGN KEY (`FeeRequestID`) REFERENCES `clinicfeerequests` (`RequestID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `physicianwithdrawals` (
  `WithdrawalID` int(11) NOT NULL AUTO_INCREMENT,
  `Reference` varchar(40) NOT NULL,
  `RequestKey` varchar(64) NOT NULL,
  `PhysicianID` int(11) NOT NULL,
  `Amount` decimal(12,2) NOT NULL,
  `PayoutMethod` varchar(20) NOT NULL,
  `AccountName` varchar(150) NOT NULL,
  `AccountNumber` varchar(34) NOT NULL,
  `BankName` varchar(100) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Pending',
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ReviewedAt` datetime DEFAULT NULL,
  `ReviewedByUserID` int(11) DEFAULT NULL,
  `FinancialOwnerUserID` int(11) DEFAULT NULL,
  PRIMARY KEY (`WithdrawalID`),
  UNIQUE KEY `Reference` (`Reference`),
  UNIQUE KEY `RequestKey` (`RequestKey`),
  KEY `idx_physician_withdrawals_status` (`PhysicianID`,`Status`),
  KEY `fk_withdrawal_reviewer` (`ReviewedByUserID`),
  KEY `idx_withdrawal_financial_owner` (`FinancialOwnerUserID`),
  CONSTRAINT `fk_withdrawal_financial_owner` FOREIGN KEY (`FinancialOwnerUserID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_withdrawal_physician` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_withdrawal_reviewer` FOREIGN KEY (`ReviewedByUserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `platformdemobanktransfers` (
  `TransferID` int(11) NOT NULL AUTO_INCREMENT,
  `PaymentID` int(11) NOT NULL,
  `Reference` varchar(50) NOT NULL,
  `Amount` decimal(12,2) NOT NULL,
  `BankLabel` varchar(100) NOT NULL DEFAULT 'HealthQueue Demo Bank',
  `AccountLastFour` char(4) NOT NULL DEFAULT '0000',
  `Status` varchar(30) NOT NULL DEFAULT 'CompletedSimulated',
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`TransferID`),
  UNIQUE KEY `uq_platform_bank_payment` (`PaymentID`),
  UNIQUE KEY `uq_platform_bank_reference` (`Reference`),
  CONSTRAINT `platformdemobanktransfers_ibfk_1` FOREIGN KEY (`PaymentID`) REFERENCES `appointmentpayments` (`PaymentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `queue` (
  `QueueID` int(11) NOT NULL AUTO_INCREMENT,
  `ClinicID` int(11) NOT NULL,
  `AppointmentID` int(11) NOT NULL,
  `PhysicianID` int(11) DEFAULT NULL,
  `QueueNumber` int(11) NOT NULL,
  `Position` int(11) DEFAULT NULL,
  `Priority` varchar(20) DEFAULT NULL,
  `ScheduledNumber` int(11) DEFAULT NULL,
  `RegularNumber` int(11) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Waiting',
  `CreatedByStaffID` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `CalledAt` datetime DEFAULT NULL,
  `ServedAt` datetime DEFAULT NULL,
  `LateAfterQueueID` int(11) DEFAULT NULL,
  `LastLateNoticeAt` datetime DEFAULT NULL,
  `ArrivedAt` datetime DEFAULT NULL,
  `GraceExtendedAt` datetime DEFAULT NULL,
  `GraceRescheduleRequestedAt` datetime DEFAULT NULL,
  `GracePolicyEnabledAt` datetime DEFAULT NULL,
  `FirstCalledAt` datetime DEFAULT NULL,
  PRIMARY KEY (`QueueID`),
  KEY `fk_queue_appointment` (`AppointmentID`),
  KEY `fk_queue_physician` (`PhysicianID`),
  KEY `fk_queue_staff` (`CreatedByStaffID`),
  KEY `idx_queue_clinic_date` (`ClinicID`,`CreatedAt`),
  CONSTRAINT `fk_queue_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_queue_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_queue_physician` FOREIGN KEY (`PhysicianID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `fk_queue_staff` FOREIGN KEY (`CreatedByStaffID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `queueemailoutbox` (
  `EmailID` int(11) NOT NULL AUTO_INCREMENT,
  `QueueID` int(11) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `AppointmentID` int(11) NOT NULL,
  `EventType` varchar(20) NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'pending',
  `Attempts` int(11) NOT NULL DEFAULT 0,
  `LastError` varchar(200) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`EmailID`),
  UNIQUE KEY `uq_queue_email_event` (`QueueID`,`EventType`),
  KEY `idx_queue_email_pending` (`Status`,`UpdatedAt`),
  KEY `PatientID` (`PatientID`),
  KEY `AppointmentID` (`AppointmentID`),
  CONSTRAINT `queueemailoutbox_ibfk_1` FOREIGN KEY (`QueueID`) REFERENCES `queue` (`QueueID`) ON DELETE CASCADE,
  CONSTRAINT `queueemailoutbox_ibfk_2` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `queueemailoutbox_ibfk_3` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `requestratelimits` (
  `BucketKey` char(64) NOT NULL,
  `WindowStart` datetime NOT NULL,
  `Attempts` int(11) NOT NULL DEFAULT 1,
  `UserID` int(11) DEFAULT NULL,
  PRIMARY KEY (`BucketKey`),
  KEY `idx_rate_window` (`WindowStart`),
  KEY `fk_rate_limit_user` (`UserID`),
  CONSTRAINT `fk_rate_limit_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `roles` (
  `RoleID` int(11) NOT NULL AUTO_INCREMENT,
  `RoleName` varchar(50) NOT NULL,
  PRIMARY KEY (`RoleID`),
  UNIQUE KEY `RoleName` (`RoleName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `signupemailverifications` (
  `SignupVerificationID` int(11) NOT NULL AUTO_INCREMENT,
  `Email` varchar(100) NOT NULL,
  `TokenHash` char(64) NOT NULL,
  `ExpiresAt` datetime NOT NULL,
  `VerifiedAt` datetime DEFAULT NULL,
  `UsedAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UserID` int(11) DEFAULT NULL,
  PRIMARY KEY (`SignupVerificationID`),
  UNIQUE KEY `TokenHash` (`TokenHash`),
  KEY `idx_signup_verification_email` (`Email`),
  KEY `fk_signup_verification_user` (`UserID`),
  CONSTRAINT `fk_signup_verification_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `smsoutbox` (
  `SmsID` int(11) NOT NULL AUTO_INCREMENT,
  `PatientID` int(11) DEFAULT NULL,
  `AppointmentID` int(11) DEFAULT NULL,
  `EventKey` varchar(120) NOT NULL,
  `EventType` varchar(30) NOT NULL,
  `Message` varchar(320) NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'pending',
  `Attempts` int(11) NOT NULL DEFAULT 0,
  `ProviderMessageID` varchar(80) DEFAULT NULL,
  `ProviderStatus` varchar(80) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`SmsID`),
  UNIQUE KEY `EventKey` (`EventKey`),
  KEY `idx_sms_pending` (`Status`,`CreatedAt`),
  KEY `fk_sms_patient` (`PatientID`),
  KEY `fk_sms_appointment` (`AppointmentID`),
  CONSTRAINT `fk_sms_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE SET NULL,
  CONSTRAINT `fk_sms_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `UserID` int(11) NOT NULL AUTO_INCREMENT,
  `IDNumber` char(10) DEFAULT NULL,
  `ClinicID` int(11) DEFAULT NULL,
  `RoleID` int(11) NOT NULL,
  `FirstName` varchar(50) NOT NULL,
  `LastName` varchar(50) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `EmailVerifiedAt` datetime DEFAULT NULL,
  `ContactNumber` varchar(20) DEFAULT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `DeletedAt` datetime DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Active',
  `Archived` tinyint(4) NOT NULL DEFAULT 0,
  `AvailabilityStatus` varchar(20) NOT NULL DEFAULT 'Available',
  `ProfilePhoto` varchar(255) DEFAULT NULL,
  `WalletBalance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `BloodType` varchar(5) DEFAULT NULL,
  `Allergies` varchar(500) DEFAULT NULL,
  `EmergencyContactName` varchar(100) DEFAULT NULL,
  `EmergencyContactNumber` varchar(20) DEFAULT NULL,
  `AnnouncementsSeenAt` datetime DEFAULT NULL,
  `PhysicianSpecialty` varchar(100) DEFAULT NULL,
  `AccountMobileNumber` varchar(20) GENERATED ALWAYS AS (case when `IsWalkInAccount` = 1 then NULL else nullif(trim(`ContactNumber`),'') end) STORED,
  `IsWalkInAccount` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Email` (`Email`),
  UNIQUE KEY `IDNumber` (`IDNumber`),
  UNIQUE KEY `uq_users_idnumber` (`IDNumber`),
  UNIQUE KEY `uq_users_account_mobile` (`AccountMobileNumber`),
  KEY `fk_users_role` (`RoleID`),
  KEY `fk_users_clinic` (`ClinicID`),
  CONSTRAINT `fk_users_clinic` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`RoleID`) REFERENCES `roles` (`RoleID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `visitcompletionnotifications` (
  `NotificationID` int(11) NOT NULL,
  `AppointmentID` int(11) NOT NULL,
  `FinalizedVersionID` int(11) NOT NULL,
  PRIMARY KEY (`NotificationID`),
  UNIQUE KEY `uq_visit_confirmation_notice` (`AppointmentID`,`FinalizedVersionID`),
  KEY `FinalizedVersionID` (`FinalizedVersionID`),
  CONSTRAINT `visitcompletionnotifications_ibfk_1` FOREIGN KEY (`NotificationID`) REFERENCES `patientnotifications` (`NotificationID`) ON DELETE CASCADE,
  CONSTRAINT `visitcompletionnotifications_ibfk_2` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `visitcompletionnotifications_ibfk_3` FOREIGN KEY (`FinalizedVersionID`) REFERENCES `consultationversions` (`VersionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `visitcompletionreviews` (
  `AppointmentID` int(11) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `ClinicID` int(11) NOT NULL,
  `FinalizedVersionID` int(11) NOT NULL,
  `Status` varchar(24) NOT NULL DEFAULT 'AwaitingPatient',
  `IssueText` text DEFAULT NULL,
  `RequestedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `PatientRespondedAt` datetime DEFAULT NULL,
  `CompletedAt` datetime DEFAULT NULL,
  `CompletedByUserID` int(11) DEFAULT NULL,
  `CompletionSource` varchar(20) DEFAULT NULL,
  `StaffResolution` text DEFAULT NULL,
  PRIMARY KEY (`AppointmentID`),
  KEY `idx_visit_completion_clinic` (`ClinicID`,`Status`),
  KEY `idx_visit_completion_patient` (`PatientID`,`Status`),
  KEY `FinalizedVersionID` (`FinalizedVersionID`),
  KEY `CompletedByUserID` (`CompletedByUserID`),
  CONSTRAINT `visitcompletionreviews_ibfk_1` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE CASCADE,
  CONSTRAINT `visitcompletionreviews_ibfk_2` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`),
  CONSTRAINT `visitcompletionreviews_ibfk_3` FOREIGN KEY (`ClinicID`) REFERENCES `clinic` (`ClinicID`),
  CONSTRAINT `visitcompletionreviews_ibfk_4` FOREIGN KEY (`FinalizedVersionID`) REFERENCES `consultationversions` (`VersionID`),
  CONSTRAINT `visitcompletionreviews_ibfk_5` FOREIGN KEY (`CompletedByUserID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wallettransactions` (
  `TransactionID` int(11) NOT NULL AUTO_INCREMENT,
  `PatientID` int(11) NOT NULL,
  `Type` varchar(20) NOT NULL,
  `Amount` decimal(10,2) NOT NULL,
  `BalanceAfter` decimal(10,2) NOT NULL,
  `RelatedAppointmentID` int(11) DEFAULT NULL,
  `Description` varchar(255) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `RequestKey` char(64) DEFAULT NULL,
  PRIMARY KEY (`TransactionID`),
  UNIQUE KEY `uq_wallet_request_key` (`RequestKey`),
  KEY `fk_wallettx_appointment` (`RelatedAppointmentID`),
  KEY `idx_wallettx_patient` (`PatientID`,`CreatedAt`),
  CONSTRAINT `fk_wallettx_appointment` FOREIGN KEY (`RelatedAppointmentID`) REFERENCES `appointments` (`AppointmentID`),
  CONSTRAINT `fk_wallettx_patient` FOREIGN KEY (`PatientID`) REFERENCES `users` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;

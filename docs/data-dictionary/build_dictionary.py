from pathlib import Path
from collections import defaultdict
from xml.sax.saxutils import escape
from zipfile import ZipFile, ZIP_DEFLATED
import json, re

ROOT=Path(__file__).parent
D=json.loads((ROOT/'schema-metadata.json').read_text())
TABLES=defaultdict(list)
for c in D['columns']: TABLES[c['TABLE_NAME']].append(c)
PURPOSE={
'roles':'Defines the application account roles: Patient, Physician, Staff and Admin.',
'users':'Stores all account roles in one table, including identity, access status, patient profile details and simulated wallet balance.',
'clinic':'Stores participating clinic information, operating hours, location and base consultation fee.',
'clinicregistrationinquiry':'Stores clinic onboarding inquiries submitted through the public website.',
'clinicphoto':'Stores clinic gallery image references and display order.',
'appointments':'Stores patient booking requests, selected clinic and physician, schedule, booking-fee status and cancellation details.',
'appointmentreschedulerequests':'Stores proposed appointment changes and the clinic review outcome.',
'appointmentpayments':'Stores consultation payment records and physician/platform revenue allocation; separate from the booking-wallet ledger.',
'queue':'Stores patient queue tickets, ordering, priority and progression through clinic service.',
'consultations':'Links a consultation to its patient, physician, clinic and appointment, including recording reference and consent.',
'consultationversions':'Stores draft and finalized revisions of physician notes and prescriptions.',
'aitranscriptversions':'Stores speech transcripts, physician corrections and generated AI draft summaries.',
'physicianavailability':'Stores recurring weekly physician working periods.',
'physiciandateavailability':'Stores physician date-specific working hours and days off.',
'medicalrecords':'Stores patient-uploaded medical record metadata and file references.',
'patientprofiledetails':'Stores additional patient profile values and notification preferences in a structured Details payload.',
'wallettransactions':'Records changes to the simulated patient wallet, including top-ups, booking payments and refunds.',
'patientnotifications':'Stores in-app notices addressed to individual patients.',
'notifications':'Stores clinic-level notices for staff.',
'queueemailoutbox':'Tracks independent near-turn and called-patient reminder emails, sending attempts and outcomes.',
'smsoutbox':'Queues SMS events for the background textbee worker and records API acceptance or failures.',
'feedback':'Stores patient ratings and comments associated with visits.',
'announcements':'Stores clinic announcements, intended audience and publication information.',
'announcementdismissals':'Records which announcements individual users have dismissed.',
'auditlogs':'Records account actions and associated clinic/context details.',
'passwordresets':'Stores hashed, expiring account-recovery tokens.',
'emailverificationtokens':'Stores hashed email-verification tokens linked to existing user accounts.',
'signupemailverifications':'Stores email verification before a patient account is created.'}
DESC={
'UserID':'Identifier of the user account.','RoleID':'Identifier of the account role.','RoleName':'Name of the application role.',
'ClinicID':'Identifier of the associated clinic.','FirstName':'Given name of the account holder.','LastName':'Family name of the account holder.',
'Email':'Email address for account access or correspondence.','EmailVerifiedAt':'Time the account email was verified.',
'ContactNumber':'Account contact or mobile number.','PasswordHash':'One-way password hash; not a plain-text password.',
'IDNumber':'Public 10-digit account ID composed of role code, registration year and sequence.',
'AvailabilityStatus':'Physician-reported availability, such as Available, On Break or Unavailable.',
'ProfilePhoto':'Reference to the account profile image.','WalletBalance':'Current simulated patient wallet balance; not a real payment-processor balance.',
'BloodType':'Patient-entered blood group.','Allergies':'Reported patient allergies.','EmergencyContactName':'Name of the patient emergency contact.',
'EmergencyContactNumber':'Telephone number of the emergency contact.','AnnouncementsSeenAt':'Most recent time the user viewed announcements.',
'ClinicName':'Display name of the clinic.','Address':'Clinic street address or location text.','City':'City or location provided in the inquiry.',
'BaseConsultationFee':'Clinic base fee used in booking/consultation payment calculations.',
'OpenDays':'Clinic operating days.','OpenTime':'Clinic opening time.','CloseTime':'Clinic closing time.',
'Latitude':'Latitude coordinate of the clinic map location.','Longitude':'Longitude coordinate of the clinic map location.',
'PhotoUrl':'Stored clinic image reference.','Specialties':'Clinic-listed specialties.','PatientsPerHour':'Configured patient throughput used in scheduling.',
'Active':'Flag indicating whether the clinic/record is active.','RegistrationDate':'Date the clinic was registered.',
'ContactName':'Contact person for the clinic inquiry.','Phone':'Telephone number supplied in the inquiry.',
'PhysiciansCount':'Number of physicians declared in the clinic inquiry.','SubmittedAt':'Time the clinic inquiry was submitted.',
'AppointmentID':'Identifier of the appointment.','PatientID':'User account acting as the patient.','PhysicianID':'User account acting as the physician.',
'AppointmentDate':'Calendar date of the visit.','AppointmentTime':'Specific visit time; NULL represents a regular queue booking where supported.',
'Concern':'Patient-stated reason for the visit.','BookingFeePaid':'Flag indicating that the booking fee is marked paid.',
'BookingFeePaidAt':'Time the booking fee was marked paid.','BookingPaymentMethod':'Method recorded for the booking-fee payment.',
'CancellationReason':'Reason supplied when cancelling a booking.','DeclineReason':'Reason the clinic declined the booking.',
'RequestID':'Identifier of the appointment reschedule request.','RequestedDate':'New date requested by the patient.',
'RequestedTime':'New time requested by the patient.','ReviewedBy':'User account that reviewed the request.',
'ReviewedAt':'Time the request was reviewed.','RequestedAt':'Time the request was made.',
'PaymentID':'Identifier of the consultation payment record.','GrossAmount':'Total amount recorded for the consultation payment.',
'PhysicianRevenue':'Amount allocated to the physician.','PlatformCommission':'Amount allocated as platform commission.',
'PaymentStatus':'Lifecycle state of the recorded payment.','PaidAt':'Time the recorded payment was paid.','RefundedAt':'Time the payment was refunded.',
'QueueID':'Identifier of the queue entry.','QueueNumber':'Clinic queue sequence value.',
'ScheduledNumber':'Ticket sequence for scheduled visits, displayed with the S- prefix.',
'RegularNumber':'Ticket sequence for regular queue visits.','Priority':'Priority classification recorded for the queue entry.',
'Position':'Explicit queue-order override; queue number is used when no override exists.',
'CreatedByStaffID':'Staff user who created the queue entry.','CalledAt':'Time the patient was called.',
'ServedAt':'Time service/consultation began.','CompletedAt':'Time the consultation was completed.',
'ConsultationID':'Identifier of the consultation.','DoctorID':'Physician user responsible for the consultation.',
'AudioFilePath':'Reference to the saved consultation recording.','AudioDurationSeconds':'Duration of the recorded audio in seconds.',
'RecordingConsent':'Flag indicating patient consent to recording.','VersionID':'Identifier of a consultation-note revision.',
'EditedByPhysicianID':'Physician account that edited the notes.','RevisionNumber':'Sequential revision number for consultation notes.',
'ClinicalNotes':'Rendered clinical-note text.','PrescriptionText':'Rendered prescription text.',
'NotesJson':'Structured note and prescription data used to reconstruct the consultation form.',
'TranscriptVersionID':'Identifier of the transcript/summary version.','TranscriptType':'Classification such as Original_STT, Doctor_Corrected or AI_Summary.',
'TranscriptText':'Transcript, corrected transcript or generated draft summary text, according to TranscriptType.',
'WordErrorRate':'Optional stored transcription word-error metric.','GeneratedAt':'Time the transcript or summary version was generated.',
'AvailabilityID':'Identifier of the weekly physician availability row.','DayOfWeek':'Day-of-week value for recurring availability.',
'StartTime':'Beginning of the working period.','EndTime':'End of the working period.',
'SlotID':'Identifier of the date-specific availability entry.','AvailDate':'Calendar date covered by the availability entry.',
'IsDayOff':'Flag indicating a physician day off.','RecordID':'Identifier of a patient-uploaded medical record.',
'Title':'Display title of the record or announcement.','Category':'Classification of the uploaded record or announcement.',
'Description':'Descriptive text or transaction explanation.','FilePath':'Stored reference to an uploaded medical-record file.',
'FileType':'Recorded uploaded-file type.','UploadedAt':'Time the file was uploaded.','Details':'Additional structured profile values; includes patient preferences where applicable.',
'TransactionID':'Identifier of the wallet ledger entry.','Type':'Wallet transaction category: TopUp, BookingPayment or Refund.',
'Amount':'Amount of the simulated wallet transaction.','BalanceAfter':'Wallet balance immediately after the transaction.',
'RelatedAppointmentID':'Appointment associated with the notification or wallet transaction, when present.',
'NotificationID':'Identifier of the in-app notification.','Message':'Content of the notice or outgoing SMS.',
'IsRead':'Flag indicating whether the notification has been read.',
'EmailID':'Identifier of the queue email dispatch entry.','SmsID':'Identifier of the SMS dispatch entry.',
'EventType':'Notification event classification, such as confirmed, near or called.','EventKey':'Unique event identifier used to prevent duplicate SMS events.',
'Attempts':'Number of attempted sends.','LastError':'Last recorded email dispatch failure or skip explanation.',
'ProviderMessageID':'Provider message/batch ID; textbee uses smsBatchId.','ProviderStatus':'Provider acceptance or error detail; acceptance is not proof of handset delivery.',
'FeedbackID':'Identifier of the patient feedback entry.','Rating':'Patient rating submitted for the visit.','Comment':'Patient feedback comment.',
'AnnouncementID':'Identifier of the announcement.','PostedByUserID':'User account that published the announcement.',
'Audience':'Intended announcement audience.','PhotoPath':'Stored announcement image reference.',
'AffectsDate':'Date affected by an announcement, when relevant.','DismissedAt':'Time the user dismissed the announcement.',
'LogID':'Identifier of the audit entry.','ActionExecuted':'Description of the action recorded in the audit log.',
'ResetID':'Identifier of the password-reset token.','VerificationID':'Identifier of the existing-account email verification token.',
'SignupVerificationID':'Identifier of the pre-account email-verification request.','TokenHash':'Hash of the token; the raw token is not stored in this column.',
'ExpiresAt':'Time after which the token is invalid.','UsedAt':'Time the token was consumed.','VerifiedAt':'Time the signup email was verified.',
'PhotoID':'Identifier of the clinic gallery photo.','Filename':'Stored clinic gallery image filename.','SortOrder':'Display order of the clinic gallery photo.',
'CreatedAt':'Time the record was created.','UpdatedAt':'Time the record was last updated.',
'DeletedAt':'Soft-deletion timestamp, when the record is marked deleted.','Archived':'Flag indicating the record is archived.','archived':'Flag indicating the record is archived.'}
STATUS={
'appointments':'Booking lifecycle state, including Pending, Confirmed, Cancelled or Completed.',
'queue':'Queue lifecycle state, including Waiting, Calling, Serving, Completed, Removed or Forfeited_Late.',
'consultationversions':'Revision state, such as Draft or Finalized.',
'users':'Account lifecycle state, such as Active or Inactive.',
'smsoutbox':'Dispatch state: pending, sending, accepted, failed, skipped or unknown. accepted is API acceptance, not delivery.',
'queueemailoutbox':'Dispatch state: pending, sending, sent, failed, skipped or unknown. sent means the mail send returned success.',
'appointmentreschedulerequests':'Review state, such as Pending, Approved, Declined or Withdrawn.'}
FK={(r['TABLE_NAME'],r['COLUMN_NAME']):r for r in D['relations']}
INDEX=defaultdict(list)
for x in D['indexes']: INDEX[(x['TABLE_NAME'],x['INDEX_NAME'])].append(x)

def e(s): return escape(str(s))
def para(text='',style=None,bold=False,size=None):
 props=(f'<w:pStyle w:val="{style}"/>' if style else '')
 rp=('<w:b/>' if bold else '')+(f'<w:sz w:val="{size}"/>' if size else '')
 return f'<w:p><w:pPr>{props}<w:spacing w:after="65"/></w:pPr><w:r><w:rPr>{rp}</w:rPr><w:t xml:space="preserve">{e(text)}</w:t></w:r></w:p>'
def pagebreak(): return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
def table(headers,rows,widths):
 xml='<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLayout w:type="fixed"/><w:tblBorders>'+''.join(f'<w:{x} w:val="single" w:sz="4" w:color="C9D9E5"/>' for x in ['top','left','bottom','right','insideH','insideV'])+'</w:tblBorders><w:tblCellMar><w:top w:w="50" w:type="dxa"/><w:left w:w="65" w:type="dxa"/><w:bottom w:w="50" w:type="dxa"/><w:right w:w="65" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'+''.join(f'<w:gridCol w:w="{w}"/>' for w in widths)+'</w:tblGrid>'
 for ri,row in enumerate([headers]+rows):
  xml+='<w:tr><w:trPr><w:cantSplit/>'+('<w:tblHeader/>' if ri==0 else '')+'</w:trPr>'
  for i,text in enumerate(row):
   color='0077B3' if ri==0 else 'F2F7FB' if ri%2 else 'FFFFFF'
   xml+=f'<w:tc><w:tcPr><w:tcW w:w="{widths[i]}" w:type="dxa"/><w:shd w:fill="{color}"/></w:tcPr><w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr>'+('<w:b/><w:color w:val="FFFFFF"/>' if ri==0 else '<w:color w:val="203F53"/>')+f'<w:sz w:val="17"/></w:rPr><w:t xml:space="preserve">{e(text)}</w:t></w:r></w:p></w:tc>'
  xml+='</w:tr>'
 return xml+'</w:tbl>'

parts=[]
parts+=[para('HealthQueue','Title'),para('Database Data Dictionary','Subtitle'),para('Schema snapshot: November 16, 2026 | Database: healthqueue_db'),para(f'{len(TABLES)} tables | {len(D["columns"])} fields | {len(D["relations"])} declared foreign-key relationships'),para('Prepared for capstone system documentation. Definitions are based on database metadata and application usage. No account credentials, patient records or medical content are included.'),para('Conventions','Heading1'),para('PK = primary key; FK = foreign key; UQ = unique constraint. Composite keys and unique indexes are listed below each table. Nullable refers to whether SQL NULL is permitted. A dash means no default value was reported. Status values described in prose are application examples, not an assertion that a database CHECK constraint exists.'),para('Architecture notes','Heading1'),para('Patient, Physician, Staff and Admin accounts share Users and are distinguished through Roles. Wallet funds and booking payments are simulated. AppointmentPayments stores consultation revenue records separately from WalletTransactions. SmsOutbox contains application-level user/appointment references without declared foreign keys; these are explicitly identified as logical references. Textbee API acceptance is not confirmed SMS delivery.'),para('Table index','Heading1')]
parts.append(table(['No.','Table','Fields','Purpose'],[[i+1,t,len(cs),PURPOSE.get(t,'System data records.')] for i,(t,cs) in enumerate(TABLES.items())],[500,2350,650,10900]))
for n,(t,columns) in enumerate(TABLES.items(),1):
 parts+=[pagebreak(),para(f'{n}. {t}','Heading1'),para(PURPOSE[t]),para(f'Fields: {len(columns)}')]
 rows=[]
 for c in columns:
  name=c['COLUMN_NAME'];flags=[]
  for (it,ix),items in INDEX.items():
   if it!=t: continue
   cols=[v['COLUMN_NAME'] for v in items]
   if name in cols:
    if ix=='PRIMARY':flags.append('PK')
    elif items[0]['NON_UNIQUE']==0:flags.append('UQ' if len(cols)==1 else 'UQ part')
  rel=FK.get((t,name))
  if rel:flags.append('FK')
  logical=t=='smsoutbox' and name in ['PatientID','AppointmentID']
  if logical:flags.append('Logical ref')
  desc=STATUS.get(t,'Lifecycle/review state of this record.') if name=='Status' else DESC.get(name,c['COLUMN_COMMENT'] or 'Stores '+re.sub(r'(?<!^)(?=[A-Z])',' ',name).lower()+'.')
  if rel:desc+=f' References {rel["REFERENCED_TABLE_NAME"]}.{rel["REFERENCED_COLUMN_NAME"]}.'
  if logical:desc+=' Application reference to '+('users.UserID' if name=='PatientID' else 'appointments.AppointmentID')+'; no declared FK.'
  if c['EXTRA']:desc+=' Database behavior: '+c['EXTRA']+'.'
  default=c['COLUMN_DEFAULT'] if c['COLUMN_DEFAULT'] is not None else '?'
  rows.append([name,c['COLUMN_TYPE'],', '.join(dict.fromkeys(flags)) or '?','Yes' if c['IS_NULLABLE']=='YES' else 'No',default,desc])
 parts.append(table(['Field','SQL type','Key','Nullable','Default','Description'],rows,[2200,1700,850,700,1400,7550]))
 keys=[]
 for (it,ix),items in INDEX.items():
  if it==t and (ix=='PRIMARY' or items[0]['NON_UNIQUE']==0):keys.append(('Primary key' if ix=='PRIMARY' else 'Unique '+ix)+': '+', '.join(v['COLUMN_NAME'] for v in items))
 if keys:parts.append(para('Constraints: '+' | '.join(keys),size=17))
parts+=[pagebreak(),para('Declared foreign-key relationships','Heading1'),para('These relationships are enforced by the database. Optionality is documented by Nullable in each field table. An FK alone does not imply one-to-one; review unique constraints on the referencing columns.')]
parts.append(table(['Child table','FK column','Parent table','Referenced column'],[[r['TABLE_NAME'],r['COLUMN_NAME'],r['REFERENCED_TABLE_NAME'],r['REFERENCED_COLUMN_NAME']] for r in D['relations']],[4200,3000,4200,3000]))
sect='<w:sectPr><w:headerReference w:type="default" r:id="rId2"/><w:footerReference w:type="default" r:id="rId3"/><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="300" w:footer="300"/></w:sectPr>'
ns='xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
document=f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document {ns}><w:body>'+''.join(parts)+sect+'</w:body></w:document>'
styles="""<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="20"/><w:color w:val="203F53"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:color w:val="023047"/><w:sz w:val="60"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:rPr><w:color w:val="0077B3"/><w:sz w:val="36"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:keepNext/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="0077B3"/><w:sz w:val="30"/></w:rPr></w:style></w:styles>"""
content_types="""<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>"""
rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'
docrels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>'
header=f'<w:hdr {ns}>'+para('HealthQueue | Database Data Dictionary',size=16)+'</w:hdr>'
footer=f'<w:ftr {ns}><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:t>HealthQueue | Page </w:t></w:r><w:fldSimple w:instr="PAGE"/></w:p></w:ftr>'
output=ROOT/'HealthQueue-Data-Dictionary.docx'
with ZipFile(output,'w',ZIP_DEFLATED) as z:
 for name,text in {'[Content_Types].xml':content_types,'_rels/.rels':rels,'word/document.xml':document,'word/styles.xml':styles,'word/_rels/document.xml.rels':docrels,'word/header1.xml':header,'word/footer1.xml':footer}.items(): z.writestr(name,text.encode('utf-8'))
print(f'Created {output}: {len(TABLES)} tables, {len(D["columns"])} fields, {len(D["relations"])} relationships.')

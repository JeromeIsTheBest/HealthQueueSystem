# HealthQueue ERD

Generated from live database schema metadata on November 16, 2026. No patient data is included.

- Overview PNG/SVG: selected attributes from 15 core tables.
- Full PNG/SVG: all 28 tables, all attributes and 47 declared foreign-key relationships.
- Arrows point from child FK column to referenced parent column. These are relational diagrams, not crow-foot cardinality notation.
- SmsOutbox.PatientID and AppointmentID are application-level associations without database foreign keys; they are deliberately not drawn as enforced relationships.
- Four account roles share Users rather than separate patient/physician/staff/admin tables.
- schema.json preserves metadata only. render-erd.ps1 regenerates PNG files from layout JSON.

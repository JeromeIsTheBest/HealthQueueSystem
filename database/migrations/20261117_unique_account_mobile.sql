-- NULL and empty optional numbers do not collide. Populated numbers are unique across roles.
ALTER TABLE Users
 ADD COLUMN IF NOT EXISTS AccountMobileNumber VARCHAR(20) GENERATED ALWAYS AS (NULLIF(TRIM(ContactNumber),'')) PERSISTENT,
 ADD UNIQUE INDEX IF NOT EXISTS uq_users_account_mobile (AccountMobileNumber);

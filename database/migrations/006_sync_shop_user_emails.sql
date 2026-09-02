-- ====================================================================
-- PrimePrint Migration 006: Sync Shop User Emails with Shop Profile Emails
-- Ensures that any shop whose email was updated in the shops table has
-- its corresponding user login account email synchronized.
-- ====================================================================

UPDATE `users` u
INNER JOIN `shops` s ON u.shop_id = s.id
SET u.email = s.email
WHERE u.role = 'shop'
  AND u.email != s.email
  AND s.email IS NOT NULL
  AND s.email != '';

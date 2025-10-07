CREATE OR REPLACE VIEW `wp_blb_salesforce_territories_v` AS
WITH src AS (
  SELECT
    t.`Brand` AS BaseBrand,
    t.`Franchisee Name`,
    /* NEW: remove any newlines from Address and trim */
    TRIM(REGEXP_REPLACE(COALESCE(t.`Address`, ''), '\\r?\\n+', ' ')) AS `Address`,
    t.`City`,
    t.`State`,
    t.`ZIP`,
    /* Normalize country to 'US' */
    CASE
      WHEN UPPER(COALESCE(t.`Country`, '')) IN ('US','USA','UNITED STATES') THEN 'US'
      ELSE t.`Country`
    END AS `Country`,
    /* Raw phones stripped of non-digits */
    REGEXP_REPLACE(COALESCE(t.`Business Phone`, ''), '[^0-9]', '') AS bp_raw,
    REGEXP_REPLACE(COALESCE(t.`Phone`, ''),           '[^0-9]', '') AS p_raw,
    REGEXP_REPLACE(COALESCE(t.`Work Phone`, ''),      '[^0-9]', '') AS wp_raw,
    t.`Personal Email`  AS personal_email,
    t.`Business Email`  AS business_email
  FROM `wp_blb_salesforce_territories` t
),
phones AS (
  SELECT
    BaseBrand, `Franchisee Name`, `Address`, `City`, `State`, `ZIP`, `Country`,
    /* Priority: Business Phone -> Phone -> Work Phone (skip NULL/empty/too short) */
    CASE
      WHEN LENGTH(bp_raw) >= 10 THEN bp_raw
      WHEN LENGTH(p_raw)  >= 10 THEN p_raw
      WHEN LENGTH(wp_raw) >= 10 THEN wp_raw
      ELSE NULL
    END AS phone_raw,
    personal_email, business_email
  FROM src
),
formatted AS (
  SELECT
    BaseBrand, `Franchisee Name`, `Address`, `City`, `State`, `ZIP`, `Country`,
    /* Format phone as (NNN) NNN-NNNN if present */
    CASE
      WHEN phone_raw IS NULL THEN NULL
      ELSE CONCAT('(',SUBSTR(phone_raw,-10,3),') ',SUBSTR(phone_raw,-7,3),'-',SUBSTR(phone_raw,-4,4))
    END AS `Phone`,
    /* Email choice with BLB-domain preference and boosthhc.com override; corrected bluemoonestatesales.com */
    CASE
      WHEN personal_email REGEXP '@boosthhc\\.com$' THEN personal_email
      WHEN business_email REGEXP '@boosthhc\\.com$' THEN business_email
      WHEN personal_email REGEXP '@(comforcare\\.com|bestlifebrands\\.com|carepatrol\\.com|bluemoonestatesales\\.com|nextdayaccess\\.com|atyoursidehomecare\\.com)$' THEN personal_email
      WHEN business_email REGEXP '@(comforcare\\.com|bestlifebrands\\.com|carepatrol\\.com|bluemoonestatesales\\.com|nextdayaccess\\.com|atyoursidehomecare\\.com)$' THEN business_email
      WHEN NULLIF(TRIM(personal_email), '') IS NOT NULL THEN personal_email
      ELSE NULLIF(TRIM(business_email), '')
    END AS `Email`
  FROM phones
)
SELECT DISTINCT
  /* Override brand if chosen email is @atyoursidehomecare.com */
  CASE
    WHEN `Email` LIKE '%@atyoursidehomecare.com' THEN 'At Your Side'
    ELSE BaseBrand
  END AS `Brand`,
  `Franchisee Name`,
  `Address`,
  `City`,
  `State`,
  `ZIP`,
  `Country`,
  `Phone`,
  `Email`
FROM formatted;

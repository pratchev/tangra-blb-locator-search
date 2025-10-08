SELECT DISTINCT 
    -- Brand determination logic: prioritize based on email domain hierarchy
    (CASE 
        WHEN (LOWER(SUBSTRING_INDEX(
            (CASE 
                WHEN ((CASE LOWER(SUBSTRING_INDEX(`t`.`personal_email`, '@', -1))
                    WHEN 'boosthhc.com' THEN 1
                    WHEN 'comforcare.com' THEN 2
                    WHEN 'bestlifebrands.com' THEN 3
                    WHEN 'carepatrol.com' THEN 4
                    WHEN 'bluemoonestatesales.com' THEN 5
                    WHEN 'nextdayaccess.com' THEN 6
                    WHEN 'atyoursidehomecare.com' THEN 7
                    ELSE 99 
                END) < (CASE LOWER(SUBSTRING_INDEX(`t`.`business_email`, '@', -1))
                    WHEN 'boosthhc.com' THEN 1
                    WHEN 'comforcare.com' THEN 2
                    WHEN 'bestlifebrands.com' THEN 3
                    WHEN 'carepatrol.com' THEN 4
                    WHEN 'bluemoonestatesales.com' THEN 5
                    WHEN 'nextdayaccess.com' THEN 6
                    WHEN 'atyoursidehomecare.com' THEN 7
                    ELSE 99 
                END)) THEN `t`.`personal_email`
                WHEN ((CASE LOWER(SUBSTRING_INDEX(`t`.`business_email`, '@', -1))
                    WHEN 'boosthhc.com' THEN 1
                    WHEN 'comforcare.com' THEN 2
                    WHEN 'bestlifebrands.com' THEN 3
                    WHEN 'carepatrol.com' THEN 4
                    WHEN 'bluemoonestatesales.com' THEN 5
                    WHEN 'nextdayaccess.com' THEN 6
                    WHEN 'atyoursidehomecare.com' THEN 7
                    ELSE 99 
                END) < (CASE LOWER(SUBSTRING_INDEX(`t`.`personal_email`, '@', -1))
                    WHEN 'boosthhc.com' THEN 1
                    WHEN 'comforcare.com' THEN 2
                    WHEN 'bestlifebrands.com' THEN 3
                    WHEN 'carepatrol.com' THEN 4
                    WHEN 'bluemoonestatesales.com' THEN 5
                    WHEN 'nextdayaccess.com' THEN 6
                    WHEN 'atyoursidehomecare.com' THEN 7
                    ELSE 99 
                END)) THEN `t`.`business_email`
                ELSE COALESCE(`t`.`personal_email`, `t`.`business_email`)
            END), 
            '@', -1
        )) = 'atyoursidehomecare.com') THEN 'At Your Side'
        ELSE `t`.`brand`
    END) AS `Brand`,
    
    -- Basic contact information
    `t`.`franchisee_name` AS `Franchisee Name`,
    TRIM(REGEXP_REPLACE(COALESCE(`t`.`address`, ''), '\\r?\\n+', ' ')) AS `Address`,
    `t`.`city` AS `City`,
    
    /* Clean up bad spellings in state column */
    CASE
      WHEN UPPER(TRIM(t.`State`)) = 'AK' THEN 'Alaska'
      WHEN UPPER(TRIM(t.`State`)) = 'BRISTISH COLUMBIA' THEN 'British Columbia'
      WHEN UPPER(TRIM(t.`State`)) = 'CA' THEN 'California'
      WHEN UPPER(TRIM(t.`State`)) = 'CALIFONIA' THEN 'California'
      WHEN UPPER(TRIM(t.`State`)) = 'FL' THEN 'Florida'
      WHEN UPPER(TRIM(t.`State`)) = 'ID' THEN 'Idaho'
      WHEN UPPER(TRIM(t.`State`)) = 'IL' THEN 'Illinois'
      WHEN UPPER(TRIM(t.`State`)) = 'IN' THEN 'Indiana'
      WHEN UPPER(TRIM(t.`State`)) = 'MD' THEN 'Maryland'
      WHEN UPPER(TRIM(t.`State`)) = 'MO' THEN 'Missouri'
      WHEN UPPER(TRIM(t.`State`)) = 'ON' THEN 'Ontario'
      WHEN UPPER(TRIM(t.`State`)) = 'PA' THEN 'Pennsylvania'
      WHEN UPPER(TRIM(t.`State`)) = 'SD' THEN 'South Dakota'
      WHEN UPPER(TRIM(t.`State`)) = 'TX' THEN 'Texas'
      ELSE t.`State`
    END AS `State`,
    `t`.`zip` AS `ZIP`,
    
    -- Country standardization
    (CASE 
        WHEN (UPPER(TRIM(`t`.`country`)) IN ('US', 'USA', 'UNITED STATES')) THEN 'US'
        ELSE `t`.`country`
    END) AS `Country`,
    
    -- Phone number formatting (US format)
    (CASE 
        WHEN (LENGTH(REGEXP_REPLACE(
            COALESCE(
                NULLIF(TRIM(`t`.`business_phone`), ''),
                NULLIF(TRIM(`t`.`phone`), ''),
                NULLIF(TRIM(`t`.`work_phone`), '')
            ), '[^0-9]', ''
        )) = 10) THEN CONCAT(
            '(', SUBSTR(REGEXP_REPLACE(
                COALESCE(
                    NULLIF(TRIM(`t`.`business_phone`), ''),
                    NULLIF(TRIM(`t`.`phone`), ''),
                    NULLIF(TRIM(`t`.`work_phone`), '')
                ), '[^0-9]', ''
            ), 1, 3),
            ') ', SUBSTR(REGEXP_REPLACE(
                COALESCE(
                    NULLIF(TRIM(`t`.`business_phone`), ''),
                    NULLIF(TRIM(`t`.`phone`), ''),
                    NULLIF(TRIM(`t`.`work_phone`), '')
                ), '[^0-9]', ''
            ), 4, 3),
            '-', SUBSTR(REGEXP_REPLACE(
                COALESCE(
                    NULLIF(TRIM(`t`.`business_phone`), ''),
                    NULLIF(TRIM(`t`.`phone`), ''),
                    NULLIF(TRIM(`t`.`work_phone`), '')
                ), '[^0-9]', ''
            ), 7, 4)
        )
        ELSE NULL
    END) AS `Phone`,
    
    -- Email selection based on domain priority
    (CASE 
        WHEN ((CASE LOWER(SUBSTRING_INDEX(`t`.`personal_email`, '@', -1))
            WHEN 'boosthhc.com' THEN 1
            WHEN 'comforcare.com' THEN 2
            WHEN 'bestlifebrands.com' THEN 3
            WHEN 'carepatrol.com' THEN 4
            WHEN 'bluemoonestatesales.com' THEN 5
            WHEN 'nextdayaccess.com' THEN 6
            WHEN 'atyoursidehomecare.com' THEN 7
            ELSE 99 
        END) < (CASE LOWER(SUBSTRING_INDEX(`t`.`business_email`, '@', -1))
            WHEN 'boosthhc.com' THEN 1
            WHEN 'comforcare.com' THEN 2
            WHEN 'bestlifebrands.com' THEN 3
            WHEN 'carepatrol.com' THEN 4
            WHEN 'bluemoonestatesales.com' THEN 5
            WHEN 'nextdayaccess.com' THEN 6
            WHEN 'atyoursidehomecare.com' THEN 7
            ELSE 99 
        END)) THEN `t`.`personal_email`
        WHEN ((CASE LOWER(SUBSTRING_INDEX(`t`.`business_email`, '@', -1))
            WHEN 'boosthhc.com' THEN 1
            WHEN 'comforcare.com' THEN 2
            WHEN 'bestlifebrands.com' THEN 3
            WHEN 'carepatrol.com' THEN 4
            WHEN 'bluemoonestatesales.com' THEN 5
            WHEN 'nextdayaccess.com' THEN 6
            WHEN 'atyoursidehomecare.com' THEN 7
            ELSE 99 
        END) < (CASE LOWER(SUBSTRING_INDEX(`t`.`personal_email`, '@', -1))
            WHEN 'boosthhc.com' THEN 1
            WHEN 'comforcare.com' THEN 2
            WHEN 'bestlifebrands.com' THEN 3
            WHEN 'carepatrol.com' THEN 4
            WHEN 'bluemoonestatesales.com' THEN 5
            WHEN 'nextdayaccess.com' THEN 6
            WHEN 'atyoursidehomecare.com' THEN 7
            ELSE 99 
        END)) THEN `t`.`business_email`
        ELSE COALESCE(`t`.`personal_email`, `t`.`business_email`)
    END) AS `Email`

FROM `blb_locator`.`wp_blb_salesforce_territories` `t`;
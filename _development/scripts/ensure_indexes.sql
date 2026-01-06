-- scripts/ensure_indexes.sql
-- Idempotent helper to ensure recommended indexes exist for faster user-scoped bbox queries.
-- Run this as a DBA or from mysql client connected to the application's database.

-- 1) Ensure locations has an index on (latitude, longitude)
SELECT COUNT(*) INTO @cnt_loc_idx FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND INDEX_NAME = 'idx_locations_lat_lon';

SET @sql_loc = IF(@cnt_loc_idx = 0,
  'ALTER TABLE locations ADD INDEX idx_locations_lat_lon (latitude, longitude)',
  'SELECT "idx_locations_lat_lon already exists"');

PREPARE stmt_loc FROM @sql_loc;
EXECUTE stmt_loc;
DEALLOCATE PREPARE stmt_loc;

-- 2) Ensure favorites has composite index (user_id, location_id)
SELECT COUNT(*) INTO @cnt_fav_idx FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'favorites' AND INDEX_NAME = 'idx_favorites_user_loc';

SET @sql_fav = IF(@cnt_fav_idx = 0,
  'ALTER TABLE favorites ADD INDEX idx_favorites_user_loc (user_id, location_id)',
  'SELECT "idx_favorites_user_loc already exists"');

PREPARE stmt_fav FROM @sql_fav;
EXECUTE stmt_fav;
DEALLOCATE PREPARE stmt_fav;

-- Analyze tables so optimizer picks up fresh stats
ANALYZE TABLE locations;
ANALYZE TABLE favorites;

SELECT 'done' AS status;

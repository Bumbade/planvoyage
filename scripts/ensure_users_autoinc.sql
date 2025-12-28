-- Migration: ensure users.id is AUTO_INCREMENT
-- Run with: mysql -u <user> -p <database> < scripts/ensure_users_autoinc.sql
-- WARNING: inspect and backup your database before running.

ALTER TABLE `users`
    MODIFY `id` INT NOT NULL AUTO_INCREMENT;

-- If `id` is not primary key, you may need to add PRIMARY KEY:
-- ALTER TABLE `users` ADD PRIMARY KEY (`id`);

-- If the column type is different (BIGINT etc.) adjust the ALTER statement accordingly.
-- This script attempts to set AUTO_INCREMENT; if it fails, inspect table schema and constraints.

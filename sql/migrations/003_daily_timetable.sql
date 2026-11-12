CREATE TABLE IF NOT EXISTS trip_schedule (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, route_id SMALLINT NOT NULL, departure_time TIME NOT NULL,
 vehicle_id BIGINT NOT NULL, driver_id BIGINT NOT NULL, conductor_id BIGINT NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE, created_by BIGINT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_trip_schedule_departure_time (departure_time),
 KEY ix_trip_schedule_route (route_id), KEY ix_trip_schedule_vehicle (vehicle_id),
 KEY ix_trip_schedule_driver (driver_id), KEY ix_trip_schedule_conductor (conductor_id),
 CONSTRAINT fk_trip_schedule_route FOREIGN KEY (route_id) REFERENCES route(id),
 CONSTRAINT fk_trip_schedule_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicle(id),
 CONSTRAINT fk_trip_schedule_driver FOREIGN KEY (driver_id) REFERENCES crew_member(id),
 CONSTRAINT fk_trip_schedule_conductor FOREIGN KEY (conductor_id) REFERENCES crew_member(id),
 CONSTRAINT fk_trip_schedule_created_by FOREIGN KEY (created_by) REFERENCES app_user(id)
) ENGINE=InnoDB;

SET @has_schedule_id = (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip' AND COLUMN_NAME = 'schedule_id'
);
SET @sql = IF(@has_schedule_id = 0,
 'ALTER TABLE trip ADD COLUMN schedule_id BIGINT NULL AFTER queue_id',
 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_stage_fee = (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip' AND COLUMN_NAME = 'stage_fee'
);
SET @sql = IF(@has_stage_fee = 0,
 'ALTER TABLE trip ADD COLUMN stage_fee DECIMAL(8,2) NULL AFTER arrival_time',
 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schedule_unique = (
 SELECT COUNT(*) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip' AND INDEX_NAME = 'uq_trip_schedule_departure'
);
SET @sql = IF(@has_schedule_unique = 0,
 'ALTER TABLE trip ADD UNIQUE KEY uq_trip_schedule_departure (schedule_id, scheduled_departure)',
 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schedule_fk = (
 SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'trip' AND CONSTRAINT_NAME = 'fk_trip_schedule'
);
SET @sql = IF(@has_schedule_fk = 0,
 'ALTER TABLE trip ADD CONSTRAINT fk_trip_schedule FOREIGN KEY (schedule_id) REFERENCES trip_schedule(id)',
 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

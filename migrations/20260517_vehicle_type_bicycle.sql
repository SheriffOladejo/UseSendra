-- Add bicycle as a delivery vehicle type (riders + customer requests)

ALTER TABLE `drivers`
  MODIFY COLUMN `vehicle_type` ENUM('bike','keke','car','van','motorcycle','bicycle') DEFAULT NULL;

ALTER TABLE `delivery_requests`
  MODIFY COLUMN `vehicle_type` ENUM('motorcycle','keke','bicycle') DEFAULT NULL;

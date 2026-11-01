ALTER TABLE flight_feeders
  ADD COLUMN aircraft_registration VARCHAR(15) NULL AFTER feeder_code;

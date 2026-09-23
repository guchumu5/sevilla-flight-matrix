-- Llegadas físicas a Sevilla (SVQ), 24-27 de septiembre de 2026.
-- Fuente: Aena Infovuelos. Captura: 23/09/2026 16:27 Europe/Madrid.
-- Códigos compartidos agrupados por fecha, hora programada y origen.
-- Salas/cintas: primera asignación preliminar visible en la captura.

START TRANSACTION;

-- Admite dos operaciones con igual número/origen/fecha y distinta hora.
ALTER TABLE flights
  DROP INDEX uq_physical_flight,
  ADD UNIQUE KEY uq_physical_flight (flight_date, physical_flight, origin_iata, scheduled_arrival);

INSERT INTO flights
(flight_date, physical_flight, origin_iata, origin_name, scheduled_arrival, traffic_class, border_control, is_canary)
VALUES
('2026-09-24', 'RYR5530', 'TSF', 'TREVISO/S.ANGELO (MIL)', '2026-09-24 00:10:00', 'schengen', 0, 0),
('2026-09-24', 'TAP1106', 'LIS', 'LISBOA', '2026-09-24 00:25:00', 'schengen', 0, 0),
('2026-09-24', 'RYR9669', 'TLS', 'TOULOUSE', '2026-09-24 00:30:00', 'schengen', 0, 0),
('2026-09-24', 'VLG3948', 'PMI', 'PALMA DE MALLORCA', '2026-09-24 08:05:00', 'domestico', 0, 0),
('2026-09-24', 'TVF4854', 'MPL', 'MONTPELLIER', '2026-09-24 08:10:00', 'schengen', 0, 0),
('2026-09-24', 'VLG6369', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-24 08:10:00', 'domestico', 0, 0),
('2026-09-24', 'IBE2390', 'VLC', 'VALENCIA', '2026-09-24 08:10:00', 'domestico', 0, 0),
('2026-09-24', 'VLG2512', 'BIO', 'BILBAO', '2026-09-24 08:25:00', 'domestico', 0, 0),
('2026-09-24', 'IBE2300', 'LEI', 'ALMERÍA', '2026-09-24 08:30:00', 'domestico', 0, 0),
('2026-09-24', 'IBS1751', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-24 08:35:00', 'domestico', 0, 0),
('2026-09-24', 'WMT6309', 'MXP', 'MILAN /MALPENSA', '2026-09-24 08:35:00', 'schengen', 0, 0),
('2026-09-24', 'WMT6039', 'FCO', 'ROMA /FIUMICINO', '2026-09-24 08:40:00', 'schengen', 0, 0),
('2026-09-24', 'RYR1193', 'TLS', 'TOULOUSE', '2026-09-24 08:45:00', 'schengen', 0, 0),
('2026-09-24', 'WMT6777', 'VCE', 'VENECIA /MARCO POLO', '2026-09-24 08:50:00', 'schengen', 0, 0),
('2026-09-24', 'VLG2210', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 08:55:00', 'domestico', 0, 0),
('2026-09-24', 'VOE2686', 'LIL', 'LILLE /LESQUIN', '2026-09-24 09:00:00', 'schengen', 0, 0),
('2026-09-24', 'EDW220', 'ZRH', 'ZURICH', '2026-09-24 09:00:00', 'schengen', 0, 0),
('2026-09-24', 'WMT3195', 'OTP', 'BUCAREST', '2026-09-24 09:25:00', 'schengen', 0, 0),
('2026-09-24', 'TAP1102', 'LIS', 'LISBOA', '2026-09-24 09:30:00', 'schengen', 0, 0),
('2026-09-24', 'RYR2205', 'VLC', 'VALENCIA', '2026-09-24 09:30:00', 'domestico', 0, 0),
('2026-09-24', 'EJU3789', 'MXP', 'MILAN /MALPENSA', '2026-09-24 09:35:00', 'schengen', 0, 0),
('2026-09-24', 'EWG9568', 'DUS', 'DUSSELDORF', '2026-09-24 09:50:00', 'schengen', 0, 0),
('2026-09-24', 'WUK5369', 'LTN', 'LONDRES /LUTON', '2026-09-24 09:50:00', 'no_schengen', 1, 0),
('2026-09-24', 'TRA6851', 'RTM', 'ROTTERDAM', '2026-09-24 09:50:00', 'schengen', 0, 0),
('2026-09-24', 'VLG3045', 'LPA', 'GRAN CANARIA', '2026-09-24 10:00:00', 'domestico', 0, 1),
('2026-09-24', 'TRA5085', 'EIN', 'EINDHOVEN', '2026-09-24 10:10:00', 'schengen', 0, 0),
('2026-09-24', 'RYR076', 'BGY', 'MILAN/BERGAMO', '2026-09-24 10:25:00', 'schengen', 0, 0),
('2026-09-24', 'NGN7950', 'BTS', 'BRATISLAVA', '2026-09-24 10:25:00', 'schengen', 0, 0),
('2026-09-24', 'RYR9894', 'BUD', 'BUDAPEST', '2026-09-24 10:25:00', 'schengen', 0, 0),
('2026-09-24', 'AEA5051', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-24 10:30:00', 'domestico', 0, 0),
('2026-09-24', 'LAV1447', 'PRG', 'PRAGA', '2026-09-24 10:30:00', 'schengen', 0, 0),
('2026-09-24', 'VLG2212', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 11:00:00', 'domestico', 0, 0),
('2026-09-24', 'BAW2648', 'LGW', 'LONDRES /GATWICK', '2026-09-24 11:00:00', 'no_schengen', 1, 0),
('2026-09-24', 'RYR9775', 'WRO', 'WROCLAW', '2026-09-24 11:05:00', 'schengen', 0, 0),
('2026-09-24', 'RYR352', 'PMI', 'PALMA DE MALLORCA', '2026-09-24 11:10:00', 'domestico', 0, 0),
('2026-09-24', 'RYR5449', 'NTE', 'NANTES-ATLANTIQUE', '2026-09-24 11:15:00', 'schengen', 0, 0),
('2026-09-24', 'IBE2019', 'MLN', 'MELILLA', '2026-09-24 11:20:00', 'domestico', 0, 0),
('2026-09-24', 'DLH1140', 'FRA', 'FRANKFURT', '2026-09-24 11:30:00', 'schengen', 0, 0),
('2026-09-24', 'EZY8005', 'LGW', 'LONDRES /GATWICK', '2026-09-24 11:30:00', 'no_schengen', 1, 0),
('2026-09-24', 'IBB5752', 'LPA', 'GRAN CANARIA', '2026-09-24 11:40:00', 'domestico', 0, 1),
('2026-09-24', 'VLG2224', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 11:45:00', 'domestico', 0, 0),
('2026-09-24', 'VLG3965', 'VLC', 'VALENCIA', '2026-09-24 11:50:00', 'domestico', 0, 0),
('2026-09-24', 'LHX1822', 'MUC', 'MUNICH', '2026-09-24 12:05:00', 'schengen', 0, 0),
('2026-09-24', 'VLG8221', 'ORY', 'PARIS /ORLY', '2026-09-24 12:25:00', 'schengen', 0, 0),
('2026-09-24', 'VLG3942', 'PMI', 'PALMA DE MALLORCA', '2026-09-24 12:35:00', 'domestico', 0, 0),
('2026-09-24', 'TVF4600', 'ORY', 'PARIS /ORLY', '2026-09-24 12:40:00', 'schengen', 0, 0),
('2026-09-24', 'IBS1755', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-24 12:45:00', 'domestico', 0, 0),
('2026-09-24', 'RYR1165', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 12:50:00', 'domestico', 0, 0),
('2026-09-24', 'VLG3051', 'LPA', 'GRAN CANARIA', '2026-09-24 12:50:00', 'domestico', 0, 1),
('2026-09-24', 'EZY3391', 'LPL', 'LIVERPOOL', '2026-09-24 12:50:00', 'no_schengen', 1, 0),
('2026-09-24', 'RYR8476', 'BLQ', 'BOLONIA', '2026-09-24 13:00:00', 'schengen', 0, 0),
('2026-09-24', 'VLG6014', 'LGW', 'LONDRES /GATWICK', '2026-09-24 13:20:00', 'no_schengen', 1, 0),
('2026-09-24', 'TVF4814', 'NTE', 'NANTES-ATLANTIQUE', '2026-09-24 13:20:00', 'schengen', 0, 0),
('2026-09-24', 'RYR5269', 'EIN', 'EINDHOVEN', '2026-09-24 13:25:00', 'schengen', 0, 0),
('2026-09-24', 'RYR2200', 'VCE', 'VENECIA /MARCO POLO', '2026-09-24 13:35:00', 'schengen', 0, 0),
('2026-09-24', 'VLG6367', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-24 14:35:00', 'domestico', 0, 0),
('2026-09-24', 'PGT1109', 'SAW', 'ISTANBUL/SABIHA GOKCEN', '2026-09-24 14:40:00', 'no_schengen', 1, 0),
('2026-09-24', 'VLG2226', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 14:45:00', 'domestico', 0, 0),
('2026-09-24', 'TAP1104', 'LIS', 'LISBOA', '2026-09-24 15:00:00', 'schengen', 0, 0),
('2026-09-24', 'TVF4606', 'ORY', 'PARIS /ORLY', '2026-09-24 15:15:00', 'schengen', 0, 0),
('2026-09-24', 'TVF4852', 'MRS', 'MARSELLA', '2026-09-24 15:35:00', 'schengen', 0, 0),
('2026-09-24', 'VOE3320', 'EAS', 'SAN SEBASTIÁN', '2026-09-24 16:00:00', 'domestico', 0, 0),
('2026-09-24', 'EJU1301', 'GVA', 'GINEBRA', '2026-09-24 16:05:00', 'schengen', 0, 0),
('2026-09-24', 'RYR5496', 'RBA', 'RABAT /SALE', '2026-09-24 16:05:00', 'no_schengen', 1, 0),
('2026-09-24', 'RYR8445', 'FCO', 'ROMA /FIUMICINO', '2026-09-24 16:10:00', 'schengen', 0, 0),
('2026-09-24', 'RYR5231', 'IBZ', 'IBIZA', '2026-09-24 16:10:00', 'domestico', 0, 0),
('2026-09-24', 'RYR5467', 'OPO', 'OPORTO', '2026-09-24 16:10:00', 'schengen', 0, 0),
('2026-09-24', 'EJU4389', 'LYS', 'LYON', '2026-09-24 16:25:00', 'schengen', 0, 0),
('2026-09-24', 'AFR1546', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-24 16:30:00', 'schengen', 0, 0),
('2026-09-24', 'VLG2218', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 16:45:00', 'domestico', 0, 0),
('2026-09-24', 'VOE3831', 'OVD', 'ASTURIAS', '2026-09-24 16:50:00', 'domestico', 0, 0),
('2026-09-24', 'VLG3257', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-24 16:50:00', 'domestico', 0, 1),
('2026-09-24', 'RYR3664', 'LIS', 'LISBOA', '2026-09-24 17:05:00', 'schengen', 0, 0),
('2026-09-24', 'VLG3165', 'FUE', 'FUERTEVENTURA', '2026-09-24 17:15:00', 'domestico', 0, 1),
('2026-09-24', 'VLG2508', 'BIO', 'BILBAO', '2026-09-24 17:25:00', 'domestico', 0, 0),
('2026-09-24', 'VOE3528', 'BIO', 'BILBAO', '2026-09-24 17:25:00', 'domestico', 0, 0),
('2026-09-24', 'VLG2222', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 17:50:00', 'domestico', 0, 0),
('2026-09-24', 'IBS1759', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-24 18:15:00', 'domestico', 0, 0),
('2026-09-24', 'VLG3253', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-24 18:50:00', 'domestico', 0, 1),
('2026-09-24', 'VLG8223', 'ORY', 'PARIS /ORLY', '2026-09-24 18:55:00', 'schengen', 0, 0),
('2026-09-24', 'VLG6023', 'LHR', 'LONDRES / HEATHROW', '2026-09-24 19:00:00', 'no_schengen', 1, 0),
('2026-09-24', 'RYR1294', 'ACE', 'LANZAROTE CÉSAR MANRIQUE', '2026-09-24 19:05:00', 'domestico', 0, 1),
('2026-09-24', 'IBB5756', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-24 19:15:00', 'domestico', 0, 1),
('2026-09-24', 'RYR6711', 'CAG', 'CAGLIARI', '2026-09-24 19:20:00', 'schengen', 0, 0),
('2026-09-24', 'TRA6727', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-24 19:30:00', 'schengen', 0, 0),
('2026-09-24', 'TVF4608', 'ORY', 'PARIS /ORLY', '2026-09-24 19:35:00', 'schengen', 0, 0),
('2026-09-24', 'TRA9077', 'BRU', 'BRUSELAS', '2026-09-24 19:50:00', 'schengen', 0, 0),
('2026-09-24', 'VLG2510', 'BIO', 'BILBAO', '2026-09-24 19:55:00', 'domestico', 0, 0),
('2026-09-24', 'RYR1304', 'PMI', 'PALMA DE MALLORCA', '2026-09-24 20:20:00', 'domestico', 0, 0),
('2026-09-24', 'AEA5053', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-24 20:30:00', 'domestico', 0, 0),
('2026-09-24', 'WMT6023', 'FCO', 'ROMA /FIUMICINO', '2026-09-24 20:45:00', 'schengen', 0, 0),
('2026-09-24', 'IBE2302', 'LEI', 'ALMERÍA', '2026-09-24 20:45:00', 'domestico', 0, 0),
('2026-09-24', 'EZY8007', 'LGW', 'LONDRES /GATWICK', '2026-09-24 20:45:00', 'no_schengen', 1, 0),
('2026-09-24', 'TVF4830', 'LYS', 'LYON', '2026-09-24 20:50:00', 'schengen', 0, 0),
('2026-09-24', 'RYR5374', 'STN', 'LONDRES /STANSTED', '2026-09-24 21:00:00', 'no_schengen', 1, 0),
('2026-09-24', 'IBE1075', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-24 21:05:00', 'domestico', 0, 0),
('2026-09-24', 'VLG3946', 'PMI', 'PALMA DE MALLORCA', '2026-09-24 21:25:00', 'domestico', 0, 0),
('2026-09-24', 'RYR5064', 'ALC', 'ALICANTE-ELCHE MIGUEL HDEZ.', '2026-09-24 21:45:00', 'domestico', 0, 0),
('2026-09-24', 'IBE2392', 'VLC', 'VALENCIA', '2026-09-24 21:55:00', 'domestico', 0, 0),
('2026-09-24', 'RUK4370', 'MAN', 'MANCHESTER', '2026-09-24 22:15:00', 'no_schengen', 1, 0),
('2026-09-24', 'VLG2220', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 22:35:00', 'domestico', 0, 0),
('2026-09-24', 'RYR2402', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 22:50:00', 'domestico', 0, 0),
('2026-09-24', 'RYR3628', 'LIS', 'LISBOA', '2026-09-24 23:00:00', 'schengen', 0, 0),
('2026-09-24', 'RYR4009', 'RAK', 'MARRAKECH', '2026-09-24 23:20:00', 'no_schengen', 1, 0),
('2026-09-24', 'AFR1222', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-24 23:25:00', 'schengen', 0, 0),
('2026-09-24', 'TVF4770', 'ORY', 'PARIS /ORLY', '2026-09-24 23:35:00', 'schengen', 0, 0),
('2026-09-24', 'VLG8827', 'ORY', 'PARIS /ORLY', '2026-09-24 23:40:00', 'schengen', 0, 0),
('2026-09-24', 'VLG2214', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-24 23:45:00', 'domestico', 0, 0),
('2026-09-24', 'VLG3255', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-24 23:45:00', 'domestico', 0, 1),
('2026-09-24', 'RYR1140', 'CGN', 'KOELN/BONN', '2026-09-24 23:50:00', 'schengen', 0, 0),
('2026-09-25', 'RYR4193', 'MRS', 'MARSELLA', '2026-09-25 00:20:00', 'schengen', 0, 0),
('2026-09-25', 'TAP1106', 'LIS', 'LISBOA', '2026-09-25 00:25:00', 'schengen', 0, 0),
('2026-09-25', 'RYR9679', 'DUB', 'DUBLIN', '2026-09-25 00:30:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR1211', 'BRI', 'BARI /PALESE', '2026-09-25 00:45:00', 'schengen', 0, 0),
('2026-09-25', 'VOE3861', 'OVD', 'ASTURIAS', '2026-09-25 08:05:00', 'domestico', 0, 0),
('2026-09-25', 'VLG2512', 'BIO', 'BILBAO', '2026-09-25 08:15:00', 'domestico', 0, 0),
('2026-09-25', 'RYR5467', 'OPO', 'OPORTO', '2026-09-25 08:20:00', 'schengen', 0, 0),
('2026-09-25', 'VLG3940', 'PMI', 'PALMA DE MALLORCA', '2026-09-25 08:25:00', 'domestico', 0, 0),
('2026-09-25', 'IBE2300', 'LEI', 'ALMERÍA', '2026-09-25 08:30:00', 'domestico', 0, 0),
('2026-09-25', 'IBS1751', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-25 08:35:00', 'domestico', 0, 0),
('2026-09-25', 'TVF4600', 'ORY', 'PARIS /ORLY', '2026-09-25 08:40:00', 'schengen', 0, 0),
('2026-09-25', 'VLG2210', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 08:55:00', 'domestico', 0, 0),
('2026-09-25', 'EJU1009', 'BSL', 'BASEL /MULHOUSE', '2026-09-25 09:15:00', 'schengen', 0, 0),
('2026-09-25', 'RYR9341', 'PSA', 'PISA / GALILEO GALILEI', '2026-09-25 09:20:00', 'schengen', 0, 0),
('2026-09-25', 'TAP1102', 'LIS', 'LISBOA', '2026-09-25 09:25:00', 'schengen', 0, 0),
('2026-09-25', 'EJU5015', 'BER', 'BERLIN BRANDENBURG', '2026-09-25 09:30:00', 'schengen', 0, 0),
('2026-09-25', 'TRA6727', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-25 09:35:00', 'schengen', 0, 0),
('2026-09-25', 'VOE1464', 'FLR', 'FLORENCIA / PERETOLA', '2026-09-25 09:35:00', 'schengen', 0, 0),
('2026-09-25', 'RYR076', 'BGY', 'MILAN/BERGAMO', '2026-09-25 09:50:00', 'schengen', 0, 0),
('2026-09-25', 'RYR5231', 'IBZ', 'IBIZA', '2026-09-25 09:55:00', 'domestico', 0, 0),
('2026-09-25', 'EZY8005', 'LGW', 'LONDRES /GATWICK', '2026-09-25 10:00:00', 'no_schengen', 1, 0),
('2026-09-25', 'EJU7809', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-25 10:05:00', 'schengen', 0, 0),
('2026-09-25', 'TRA9075', 'BRU', 'BRUSELAS', '2026-09-25 10:15:00', 'schengen', 0, 0),
('2026-09-25', 'EJU3789', 'MXP', 'MILAN /MALPENSA', '2026-09-25 10:20:00', 'schengen', 0, 0),
('2026-09-25', 'RYR352', 'PMI', 'PALMA DE MALLORCA', '2026-09-25 10:20:00', 'domestico', 0, 0),
('2026-09-25', 'RYR6760', 'EDI', 'EDIMBURGO', '2026-09-25 10:30:00', 'no_schengen', 1, 0),
('2026-09-25', 'AEA5051', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-25 10:30:00', 'domestico', 0, 0),
('2026-09-25', 'AIA243', 'ZRH', 'ZURICH', '2026-09-25 10:30:00', 'schengen', 0, 0),
('2026-09-25', 'AEH245', 'ZRH', 'ZURICH', '2026-09-25 10:40:00', 'schengen', 0, 0),
('2026-09-25', 'VLG2212', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 10:55:00', 'domestico', 0, 0),
('2026-09-25', 'VLG3045', 'LPA', 'GRAN CANARIA', '2026-09-25 11:00:00', 'domestico', 0, 1),
('2026-09-25', 'DLH1140', 'FRA', 'FRANKFURT', '2026-09-25 11:15:00', 'schengen', 0, 0),
('2026-09-25', 'IBE2019', 'MLN', 'MELILLA', '2026-09-25 11:20:00', 'domestico', 0, 0),
('2026-09-25', 'RYR9378', 'BHX', 'BIRMINGHAM / INTERNACIONAL', '2026-09-25 11:30:00', 'no_schengen', 1, 0),
('2026-09-25', 'VLG3165', 'FUE', 'FUERTEVENTURA', '2026-09-25 11:30:00', 'domestico', 0, 1),
('2026-09-25', 'IBB5752', 'LPA', 'GRAN CANARIA', '2026-09-25 11:40:00', 'domestico', 0, 1),
('2026-09-25', 'VLG2224', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 11:45:00', 'domestico', 0, 0),
('2026-09-25', 'RYR3106', 'MRS', 'MARSELLA', '2026-09-25 11:55:00', 'schengen', 0, 0),
('2026-09-25', 'RYR2395', 'VLC', 'VALENCIA', '2026-09-25 11:55:00', 'domestico', 0, 0),
('2026-09-25', 'VLG3251', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-25 12:00:00', 'domestico', 0, 1),
('2026-09-25', 'LHX1822', 'MUC', 'MUNICH', '2026-09-25 12:05:00', 'schengen', 0, 0),
('2026-09-25', 'VLG2510', 'BIO', 'BILBAO', '2026-09-25 12:20:00', 'domestico', 0, 0),
('2026-09-25', 'VLG8221', 'ORY', 'PARIS /ORLY', '2026-09-25 12:25:00', 'schengen', 0, 0),
('2026-09-25', 'VOE3268', 'SDR', 'SANTANDER-SEVE BALLESTEROS', '2026-09-25 12:30:00', 'domestico', 0, 0),
('2026-09-25', 'RYR5437', 'LUX', 'LUXEMBURGO', '2026-09-25 12:35:00', 'schengen', 0, 0),
('2026-09-25', 'TVF4604', 'ORY', 'PARIS /ORLY', '2026-09-25 12:40:00', 'schengen', 0, 0),
('2026-09-25', 'IBS1755', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-25 12:45:00', 'domestico', 0, 0),
('2026-09-25', 'VLG6365', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-25 12:45:00', 'domestico', 0, 0),
('2026-09-25', 'RAM924', 'CMN', 'CASABLANCA /MOHAMED V', '2026-09-25 13:15:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR5530', 'TSF', 'TREVISO/S.ANGELO (MIL)', '2026-09-25 13:15:00', 'schengen', 0, 0),
('2026-09-25', 'VLG6014', 'LGW', 'LONDRES /GATWICK', '2026-09-25 13:20:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR1165', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 14:25:00', 'domestico', 0, 0),
('2026-09-25', 'TVF4830', 'LYS', 'LYON', '2026-09-25 14:35:00', 'schengen', 0, 0),
('2026-09-25', 'RYR4815', 'ALC', 'ALICANTE-ELCHE MIGUEL HDEZ.', '2026-09-25 14:40:00', 'domestico', 0, 0),
('2026-09-25', 'PGT1109', 'SAW', 'ISTANBUL/SABIHA GOKCEN', '2026-09-25 14:40:00', 'no_schengen', 1, 0),
('2026-09-25', 'VLG2226', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 14:45:00', 'domestico', 0, 0),
('2026-09-25', 'TAP1104', 'LIS', 'LISBOA', '2026-09-25 15:15:00', 'schengen', 0, 0),
('2026-09-25', 'EJU1301', 'GVA', 'GINEBRA', '2026-09-25 15:20:00', 'schengen', 0, 0),
('2026-09-25', 'RYR5496', 'RBA', 'RABAT /SALE', '2026-09-25 15:25:00', 'no_schengen', 1, 0),
('2026-09-25', 'VLG3965', 'VLC', 'VALENCIA', '2026-09-25 15:30:00', 'domestico', 0, 0),
('2026-09-25', 'WMT6309', 'MXP', 'MILAN /MALPENSA', '2026-09-25 15:35:00', 'schengen', 0, 0),
('2026-09-25', 'EIN756', 'DUB', 'DUBLIN', '2026-09-25 16:05:00', 'no_schengen', 1, 0),
('2026-09-25', 'IBE2257', 'MLN', 'MELILLA', '2026-09-25 16:05:00', 'domestico', 0, 0),
('2026-09-25', 'AFR1546', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-25 16:30:00', 'schengen', 0, 0),
('2026-09-25', 'VLG2218', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 16:45:00', 'domestico', 0, 0),
('2026-09-25', 'VLG3257', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-25 16:45:00', 'domestico', 0, 1),
('2026-09-25', 'VLG7341', 'ESU', 'ESSAOUIRA/MOGADOR', '2026-09-25 16:50:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR8445', 'FCO', 'ROMA /FIUMICINO', '2026-09-25 16:55:00', 'schengen', 0, 0),
('2026-09-25', 'THY1299', 'IST', 'ESTAMBUL', '2026-09-25 17:35:00', 'no_schengen', 1, 0),
('2026-09-25', 'VLG3253', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-25 17:50:00', 'domestico', 0, 1),
('2026-09-25', 'VLG2222', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 17:55:00', 'domestico', 0, 0),
('2026-09-25', 'TRA5085', 'EIN', 'EINDHOVEN', '2026-09-25 18:05:00', 'schengen', 0, 0),
('2026-09-25', 'RYR2036', 'VIT', 'VITORIA', '2026-09-25 18:15:00', 'domestico', 0, 0),
('2026-09-25', 'RYR5374', 'STN', 'LONDRES /STANSTED', '2026-09-25 18:25:00', 'no_schengen', 1, 0),
('2026-09-25', 'WZZ1351', 'WAW', 'VARSOVIA', '2026-09-25 18:30:00', 'schengen', 0, 0),
('2026-09-25', 'EZY8007', 'LGW', 'LONDRES /GATWICK', '2026-09-25 18:45:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR1214', 'FKB', 'BADEN BADEN-KARLSRUHE (FKB)', '2026-09-25 18:55:00', 'schengen', 0, 0),
('2026-09-25', 'VLG6023', 'LHR', 'LONDRES / HEATHROW', '2026-09-25 19:00:00', 'no_schengen', 1, 0),
('2026-09-25', 'VLG3047', 'LPA', 'GRAN CANARIA', '2026-09-25 19:15:00', 'domestico', 0, 1),
('2026-09-25', 'IBB5756', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-25 19:15:00', 'domestico', 0, 1),
('2026-09-25', 'TRA6733', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-25 19:20:00', 'schengen', 0, 0),
('2026-09-25', 'EDW220', 'ZRH', 'ZURICH', '2026-09-25 19:20:00', 'schengen', 0, 0),
('2026-09-25', 'TVF4884', 'BOD', 'BURDEOS', '2026-09-25 19:35:00', 'schengen', 0, 0),
('2026-09-25', 'RYR3628', 'LIS', 'LISBOA', '2026-09-25 19:40:00', 'schengen', 0, 0),
('2026-09-25', 'RYR1294', 'ACE', 'LANZAROTE CÉSAR MANRIQUE', '2026-09-25 19:50:00', 'domestico', 0, 1),
('2026-09-25', 'EZY8051', 'LGW', 'LONDRES /GATWICK', '2026-09-25 20:00:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR1961', 'TFS', 'TENERIFE SUR', '2026-09-25 20:05:00', 'domestico', 0, 1),
('2026-09-25', 'AEA5053', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-25 20:25:00', 'domestico', 0, 0),
('2026-09-25', 'BAW2650', 'LGW', 'LONDRES /GATWICK', '2026-09-25 20:30:00', 'no_schengen', 1, 0),
('2026-09-25', 'RYR1304', 'PMI', 'PALMA DE MALLORCA', '2026-09-25 20:30:00', 'domestico', 0, 0),
('2026-09-25', 'VLG2508', 'BIO', 'BILBAO', '2026-09-25 20:35:00', 'domestico', 0, 0),
('2026-09-25', 'IBE2302', 'LEI', 'ALMERÍA', '2026-09-25 20:45:00', 'domestico', 0, 0),
('2026-09-25', 'RYR2703', 'DUB', 'DUBLIN', '2026-09-25 21:00:00', 'no_schengen', 1, 0),
('2026-09-25', 'VLG2220', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-25 21:05:00', 'domestico', 0, 0),
('2026-09-25', 'IBE1075', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-25 21:05:00', 'domestico', 0, 0),
('2026-09-25', 'VOE3528', 'BIO', 'BILBAO', '2026-09-25 21:15:00', 'domestico', 0, 0),
('2026-09-25', 'VLG3946', 'PMI', 'PALMA DE MALLORCA', '2026-09-25 21:25:00', 'domestico', 0, 0),
('2026-09-25', 'VLG6369', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-25 21:25:00', 'domestico', 0, 0),
('2026-09-25', 'WMT6023', 'FCO', 'ROMA /FIUMICINO', '2026-09-25 21:30:00', 'schengen', 0, 0),
('2026-09-25', 'VOE3831', 'OVD', 'ASTURIAS', '2026-09-25 21:55:00', 'domestico', 0, 0),
('2026-09-25', 'VOE1858', 'OLB', 'OLBIA /COSTA SMERALDA', '2026-09-25 22:25:00', 'schengen', 0, 0),
('2026-09-25', 'VLG6367', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-25 23:05:00', 'domestico', 0, 0),
('2026-09-25', 'AFR1222', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-25 23:25:00', 'schengen', 0, 0),
('2026-09-25', 'TVF4770', 'ORY', 'PARIS /ORLY', '2026-09-25 23:35:00', 'schengen', 0, 0),
('2026-09-25', 'RYR2205', 'VLC', 'VALENCIA', '2026-09-25 23:40:00', 'domestico', 0, 0),
('2026-09-25', 'VLG3051', 'LPA', 'GRAN CANARIA', '2026-09-25 23:45:00', 'domestico', 0, 1),
('2026-09-25', 'VLG3255', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-25 23:45:00', 'domestico', 0, 1),
('2026-09-25', 'VLG8829', 'ORY', 'PARIS /ORLY', '2026-09-25 23:50:00', 'schengen', 0, 0),
('2026-09-25', 'AEE714', 'ATH', 'ATENAS', '2026-09-25 23:55:00', 'schengen', 0, 0),
('2026-09-25', 'VLG6016', 'LGW', 'LONDRES /GATWICK', '2026-09-25 23:55:00', 'no_schengen', 1, 0),
('2026-09-26', 'VLG2214', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 00:00:00', 'domestico', 0, 0),
('2026-09-26', 'RYR5662', 'TRN', 'TURIN', '2026-09-26 00:10:00', 'schengen', 0, 0),
('2026-09-26', 'RYR2406', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 00:20:00', 'domestico', 0, 0),
('2026-09-26', 'TAP1106', 'LIS', 'LISBOA', '2026-09-26 00:25:00', 'schengen', 0, 0),
('2026-09-26', 'RYR1205', 'MXP', 'MILAN /MALPENSA', '2026-09-26 00:50:00', 'schengen', 0, 0),
('2026-09-26', 'VLG3940', 'PMI', 'PALMA DE MALLORCA', '2026-09-26 08:10:00', 'domestico', 0, 0),
('2026-09-26', 'RYR1205', 'MXP', 'MILAN /MALPENSA', '2026-09-26 08:25:00', 'schengen', 0, 0),
('2026-09-26', 'IBS1751', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-26 08:35:00', 'domestico', 0, 0),
('2026-09-26', 'WMT6039', 'FCO', 'ROMA /FIUMICINO', '2026-09-26 08:40:00', 'schengen', 0, 0),
('2026-09-26', 'VLG2210', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 08:55:00', 'domestico', 0, 0),
('2026-09-26', 'WMT6309', 'MXP', 'MILAN /MALPENSA', '2026-09-26 09:00:00', 'schengen', 0, 0),
('2026-09-26', 'TRA6727', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-26 09:05:00', 'schengen', 0, 0),
('2026-09-26', 'VLG6369', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-26 09:05:00', 'domestico', 0, 0),
('2026-09-26', 'RYR5467', 'OPO', 'OPORTO', '2026-09-26 09:20:00', 'schengen', 0, 0),
('2026-09-26', 'TAP1102', 'LIS', 'LISBOA', '2026-09-26 09:30:00', 'schengen', 0, 0),
('2026-09-26', 'RYR352', 'PMI', 'PALMA DE MALLORCA', '2026-09-26 09:30:00', 'domestico', 0, 0),
('2026-09-26', 'RYR5374', 'STN', 'LONDRES /STANSTED', '2026-09-26 09:45:00', 'no_schengen', 1, 0),
('2026-09-26', 'WUK5369', 'LTN', 'LONDRES /LUTON', '2026-09-26 09:50:00', 'no_schengen', 1, 0),
('2026-09-26', 'EZY8005', 'LGW', 'LONDRES /GATWICK', '2026-09-26 09:55:00', 'no_schengen', 1, 0),
('2026-09-26', 'RYR1294', 'ACE', 'LANZAROTE CÉSAR MANRIQUE', '2026-09-26 10:10:00', 'domestico', 0, 1),
('2026-09-26', 'WMT3195', 'OTP', 'BUCAREST', '2026-09-26 10:10:00', 'schengen', 0, 0),
('2026-09-26', 'AEA5051', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-26 10:30:00', 'domestico', 0, 0),
('2026-09-26', 'TVF4600', 'ORY', 'PARIS /ORLY', '2026-09-26 10:30:00', 'schengen', 0, 0),
('2026-09-26', 'THY1297', 'IST', 'ESTAMBUL', '2026-09-26 10:40:00', 'no_schengen', 1, 0),
('2026-09-26', 'WMT6777', 'VCE', 'VENECIA /MARCO POLO', '2026-09-26 10:40:00', 'schengen', 0, 0),
('2026-09-26', 'VLG2212', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 10:55:00', 'domestico', 0, 0),
('2026-09-26', 'VLG2512', 'BIO', 'BILBAO', '2026-09-26 11:00:00', 'domestico', 0, 0),
('2026-09-26', 'RYR5620', 'LTN', 'LONDRES /LUTON', '2026-09-26 11:05:00', 'no_schengen', 1, 0),
('2026-09-26', 'DLH1140', 'FRA', 'FRANKFURT', '2026-09-26 11:25:00', 'schengen', 0, 0),
('2026-09-26', 'TVF4604', 'ORY', 'PARIS /ORLY', '2026-09-26 11:30:00', 'schengen', 0, 0),
('2026-09-26', 'VLG2431', 'MAH', 'MENORCA', '2026-09-26 11:40:00', 'domestico', 0, 0),
('2026-09-26', 'LHX1822', 'MUC', 'MUNICH', '2026-09-26 12:05:00', 'schengen', 0, 0),
('2026-09-26', 'RYR076', 'BGY', 'MILAN/BERGAMO', '2026-09-26 12:15:00', 'schengen', 0, 0),
('2026-09-26', 'RYR1165', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 12:20:00', 'domestico', 0, 0),
('2026-09-26', 'RYR8445', 'FCO', 'ROMA /FIUMICINO', '2026-09-26 12:20:00', 'schengen', 0, 0),
('2026-09-26', 'RYR5395', 'FUE', 'FUERTEVENTURA', '2026-09-26 12:25:00', 'domestico', 0, 1),
('2026-09-26', 'VLG8221', 'ORY', 'PARIS /ORLY', '2026-09-26 12:30:00', 'schengen', 0, 0),
('2026-09-26', 'IBE1071', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-26 12:45:00', 'domestico', 0, 0),
('2026-09-26', 'VLG3942', 'PMI', 'PALMA DE MALLORCA', '2026-09-26 12:45:00', 'domestico', 0, 0),
('2026-09-26', 'RAM924', 'CMN', 'CASABLANCA /MOHAMED V', '2026-09-26 13:15:00', 'no_schengen', 1, 0),
('2026-09-26', 'RYR6444', 'CRL', 'BRUSELAS /CHARLEROI', '2026-09-26 13:20:00', 'schengen', 0, 0),
('2026-09-26', 'VLG6014', 'LGW', 'LONDRES /GATWICK', '2026-09-26 13:20:00', 'no_schengen', 1, 0),
('2026-09-26', 'RYR8655', 'PRG', 'PRAGA', '2026-09-26 13:35:00', 'schengen', 0, 0),
('2026-09-26', 'PGT1109', 'SAW', 'ISTANBUL/SABIHA GOKCEN', '2026-09-26 14:40:00', 'no_schengen', 1, 0),
('2026-09-26', 'VLG2226', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 14:45:00', 'domestico', 0, 0),
('2026-09-26', 'VLG6367', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-26 14:45:00', 'domestico', 0, 0),
('2026-09-26', 'VOE3831', 'OVD', 'ASTURIAS', '2026-09-26 15:25:00', 'domestico', 0, 0),
('2026-09-26', 'RYR3628', 'LIS', 'LISBOA', '2026-09-26 15:45:00', 'schengen', 0, 0),
('2026-09-26', 'AFR1546', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-26 16:30:00', 'schengen', 0, 0),
('2026-09-26', 'IBS1759', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-26 16:35:00', 'domestico', 0, 0),
('2026-09-26', 'RYR5510', 'OPO', 'OPORTO', '2026-09-26 16:35:00', 'schengen', 0, 0),
('2026-09-26', 'VLG2218', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 16:45:00', 'domestico', 0, 0),
('2026-09-26', 'VLG3257', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-26 16:45:00', 'domestico', 0, 1),
('2026-09-26', 'VLG8829', 'ORY', 'PARIS /ORLY', '2026-09-26 17:10:00', 'schengen', 0, 0),
('2026-09-26', 'VLG2508', 'BIO', 'BILBAO', '2026-09-26 17:25:00', 'domestico', 0, 0),
('2026-09-26', 'VLG3161', 'ACE', 'LANZAROTE CÉSAR MANRIQUE', '2026-09-26 18:05:00', 'domestico', 0, 1),
('2026-09-26', 'RYR4348', 'BLQ', 'BOLONIA', '2026-09-26 18:55:00', 'schengen', 0, 0),
('2026-09-26', 'VLG6023', 'LHR', 'LONDRES / HEATHROW', '2026-09-26 19:00:00', 'no_schengen', 1, 0),
('2026-09-26', 'TVF4608', 'ORY', 'PARIS /ORLY', '2026-09-26 19:00:00', 'schengen', 0, 0),
('2026-09-26', 'VLG3965', 'VLC', 'VALENCIA', '2026-09-26 19:10:00', 'domestico', 0, 0),
('2026-09-26', 'RYR1304', 'PMI', 'PALMA DE MALLORCA', '2026-09-26 19:40:00', 'domestico', 0, 0),
('2026-09-26', 'RYR5349', 'CTA', 'CATANIA /FONTANAROSSA', '2026-09-26 19:45:00', 'schengen', 0, 0),
('2026-09-26', 'TRA9077', 'BRU', 'BRUSELAS', '2026-09-26 20:00:00', 'schengen', 0, 0),
('2026-09-26', 'VLG3259', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-26 20:05:00', 'domestico', 0, 1),
('2026-09-26', 'RYR2427', 'TRS', 'TRIESTE', '2026-09-26 20:10:00', 'schengen', 0, 0),
('2026-09-26', 'VLG2510', 'BIO', 'BILBAO', '2026-09-26 20:25:00', 'domestico', 0, 0),
('2026-09-26', 'AEA5053', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-26 20:30:00', 'domestico', 0, 0),
('2026-09-26', 'WMT6023', 'FCO', 'ROMA /FIUMICINO', '2026-09-26 20:45:00', 'schengen', 0, 0),
('2026-09-26', 'EZY8051', 'LGW', 'LONDRES /GATWICK', '2026-09-26 20:50:00', 'no_schengen', 1, 0),
('2026-09-26', 'RYR4370', 'MAN', 'MANCHESTER', '2026-09-26 20:55:00', 'no_schengen', 1, 0),
('2026-09-26', 'RYR5530', 'TSF', 'TREVISO/S.ANGELO (MIL)', '2026-09-26 21:00:00', 'schengen', 0, 0),
('2026-09-26', 'IBE0611', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-26 21:05:00', 'domestico', 0, 0),
('2026-09-26', 'EZY2899', 'BRS', 'BRISTOL', '2026-09-26 21:10:00', 'no_schengen', 1, 0),
('2026-09-26', 'RYR4815', 'ALC', 'ALICANTE-ELCHE MIGUEL HDEZ.', '2026-09-26 21:45:00', 'domestico', 0, 0),
('2026-09-26', 'VLG6365', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-26 22:05:00', 'domestico', 0, 0),
('2026-09-26', 'VLG2228', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-26 22:15:00', 'domestico', 0, 0),
('2026-09-26', 'RYR7743', 'WAW', 'VARSOVIA', '2026-09-26 22:50:00', 'schengen', 0, 0),
('2026-09-26', 'AFR1222', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-26 23:25:00', 'schengen', 0, 0),
('2026-09-26', 'VLG3165', 'FUE', 'FUERTEVENTURA', '2026-09-26 23:25:00', 'domestico', 0, 1),
('2026-09-26', 'RYR9505', 'MAH', 'MENORCA', '2026-09-26 23:25:00', 'domestico', 0, 0),
('2026-09-26', 'RYR4009', 'RAK', 'MARRAKECH', '2026-09-26 23:30:00', 'no_schengen', 1, 0),
('2026-09-26', 'TVF4770', 'ORY', 'PARIS /ORLY', '2026-09-26 23:35:00', 'schengen', 0, 0),
('2026-09-26', 'VLG3255', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-26 23:45:00', 'domestico', 0, 1),
('2026-09-27', 'VLG3047', 'LPA', 'GRAN CANARIA', '2026-09-27 00:00:00', 'domestico', 0, 1),
('2026-09-27', 'TAP1106', 'LIS', 'LISBOA', '2026-09-27 00:25:00', 'schengen', 0, 0),
('2026-09-27', 'RYR2207', 'VLC', 'VALENCIA', '2026-09-27 00:30:00', 'domestico', 0, 0),
('2026-09-27', 'RYR2205', 'VLC', 'VALENCIA', '2026-09-27 07:05:00', 'domestico', 0, 0),
('2026-09-27', 'TRA6731', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-27 08:35:00', 'schengen', 0, 0),
('2026-09-27', 'EJU1301', 'GVA', 'GINEBRA', '2026-09-27 08:35:00', 'schengen', 0, 0),
('2026-09-27', 'IBS1751', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-27 08:35:00', 'domestico', 0, 0),
('2026-09-27', 'WMT6039', 'FCO', 'ROMA /FIUMICINO', '2026-09-27 08:40:00', 'schengen', 0, 0),
('2026-09-27', 'VLG2210', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 08:55:00', 'domestico', 0, 0),
('2026-09-27', 'RYR352', 'PMI', 'PALMA DE MALLORCA', '2026-09-27 09:00:00', 'domestico', 0, 0),
('2026-09-27', 'RYR9894', 'BUD', 'BUDAPEST', '2026-09-27 09:20:00', 'schengen', 0, 0),
('2026-09-27', 'RYR5467', 'OPO', 'OPORTO', '2026-09-27 09:20:00', 'schengen', 0, 0),
('2026-09-27', 'VOE2686', 'LIL', 'LILLE /LESQUIN', '2026-09-27 09:30:00', 'schengen', 0, 0),
('2026-09-27', 'RYR2036', 'VIT', 'VITORIA', '2026-09-27 09:35:00', 'domestico', 0, 0),
('2026-09-27', 'TVF4854', 'MPL', 'MONTPELLIER', '2026-09-27 09:45:00', 'schengen', 0, 0),
('2026-09-27', 'RYR9775', 'WRO', 'WROCLAW', '2026-09-27 09:55:00', 'schengen', 0, 0),
('2026-09-27', 'EZY8005', 'LGW', 'LONDRES /GATWICK', '2026-09-27 10:00:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR3628', 'LIS', 'LISBOA', '2026-09-27 10:00:00', 'schengen', 0, 0),
('2026-09-27', 'RYR2703', 'DUB', 'DUBLIN', '2026-09-27 10:10:00', 'no_schengen', 1, 0),
('2026-09-27', 'VLG3049', 'LPA', 'GRAN CANARIA', '2026-09-27 10:20:00', 'domestico', 0, 1),
('2026-09-27', 'AEA5051', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-27 10:30:00', 'domestico', 0, 0),
('2026-09-27', 'TRA6853', 'RTM', 'ROTTERDAM', '2026-09-27 10:30:00', 'schengen', 0, 0),
('2026-09-27', 'RYR1313', 'KRK', 'CRACOVIA', '2026-09-27 10:45:00', 'schengen', 0, 0),
('2026-09-27', 'VLG2212', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 10:50:00', 'domestico', 0, 0),
('2026-09-27', 'VLG2512', 'BIO', 'BILBAO', '2026-09-27 11:00:00', 'domestico', 0, 0),
('2026-09-27', 'DLH1140', 'FRA', 'FRANKFURT', '2026-09-27 11:30:00', 'schengen', 0, 0),
('2026-09-27', 'EZY3391', 'LPL', 'LIVERPOOL', '2026-09-27 11:35:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR8445', 'FCO', 'ROMA /FIUMICINO', '2026-09-27 11:40:00', 'schengen', 0, 0),
('2026-09-27', 'IBB5752', 'LPA', 'GRAN CANARIA', '2026-09-27 11:40:00', 'domestico', 0, 1),
('2026-09-27', 'VLG2224', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 11:50:00', 'domestico', 0, 0),
('2026-09-27', 'RYR6711', 'CAG', 'CAGLIARI', '2026-09-27 11:50:00', 'schengen', 0, 0),
('2026-09-27', 'RYR1247', 'BVA', 'PARIS /BEAUVAIS-TILLE', '2026-09-27 12:05:00', 'schengen', 0, 0),
('2026-09-27', 'LHX1822', 'MUC', 'MUNICH', '2026-09-27 12:05:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3942', 'PMI', 'PALMA DE MALLORCA', '2026-09-27 12:20:00', 'domestico', 0, 0),
('2026-09-27', 'VLG8221', 'ORY', 'PARIS /ORLY', '2026-09-27 12:25:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3251', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-27 12:25:00', 'domestico', 0, 1),
('2026-09-27', 'IBS1755', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-27 12:45:00', 'domestico', 0, 0),
('2026-09-27', 'RYR1165', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 12:55:00', 'domestico', 0, 0),
('2026-09-27', 'VLG6370', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-27 13:05:00', 'domestico', 0, 0),
('2026-09-27', 'RAM924', 'CMN', 'CASABLANCA /MOHAMED V', '2026-09-27 13:15:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR6760', 'EDI', 'EDIMBURGO', '2026-09-27 13:20:00', 'no_schengen', 1, 0),
('2026-09-27', 'VLG6014', 'LGW', 'LONDRES /GATWICK', '2026-09-27 13:25:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR6686', 'TPS', 'TRAPANI /BIRGI (MIL)', '2026-09-27 13:30:00', 'schengen', 0, 0),
('2026-09-27', 'TVF4604', 'ORY', 'PARIS /ORLY', '2026-09-27 13:35:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3253', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-27 14:00:00', 'domestico', 0, 1),
('2026-09-27', 'PGT1109', 'SAW', 'ISTANBUL/SABIHA GOKCEN', '2026-09-27 14:40:00', 'no_schengen', 1, 0),
('2026-09-27', 'VLG2226', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 14:45:00', 'domestico', 0, 0),
('2026-09-27', 'VLG3965', 'VLC', 'VALENCIA', '2026-09-27 14:50:00', 'domestico', 0, 0),
('2026-09-27', 'RYR5374', 'STN', 'LONDRES /STANSTED', '2026-09-27 14:55:00', 'no_schengen', 1, 0),
('2026-09-27', 'TAP1104', 'LIS', 'LISBOA', '2026-09-27 15:00:00', 'schengen', 0, 0),
('2026-09-27', 'WMT6309', 'MXP', 'MILAN /MALPENSA', '2026-09-27 15:15:00', 'schengen', 0, 0),
('2026-09-27', 'NMA8026', 'BJZ', 'BADAJOZ', '2026-09-27 15:35:00', 'domestico', 0, 0),
('2026-09-27', 'TVF4852', 'MRS', 'MARSELLA', '2026-09-27 15:35:00', 'schengen', 0, 0),
('2026-09-27', 'RYR5496', 'RBA', 'RABAT /SALE', '2026-09-27 15:50:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR4815', 'ALC', 'ALICANTE-ELCHE MIGUEL HDEZ.', '2026-09-27 15:55:00', 'domestico', 0, 0),
('2026-09-27', 'IBE2257', 'MLN', 'MELILLA', '2026-09-27 16:05:00', 'domestico', 0, 0),
('2026-09-27', 'RYR2200', 'VCE', 'VENECIA /MARCO POLO', '2026-09-27 16:10:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3165', 'FUE', 'FUERTEVENTURA', '2026-09-27 16:15:00', 'domestico', 0, 1),
('2026-09-27', 'VLG3045', 'LPA', 'GRAN CANARIA', '2026-09-27 16:20:00', 'domestico', 0, 1),
('2026-09-27', 'RYR5231', 'IBZ', 'IBIZA', '2026-09-27 16:25:00', 'domestico', 0, 0),
('2026-09-27', 'AFR1546', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-27 16:30:00', 'schengen', 0, 0),
('2026-09-27', 'EJU4389', 'LYS', 'LYON', '2026-09-27 16:30:00', 'schengen', 0, 0),
('2026-09-27', 'RYR1408', 'MLA', 'MALTA', '2026-09-27 16:35:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3257', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-27 16:50:00', 'domestico', 0, 1),
('2026-09-27', 'VLG3944', 'PMI', 'PALMA DE MALLORCA', '2026-09-27 17:00:00', 'domestico', 0, 0),
('2026-09-27', 'VOE3831', 'OVD', 'ASTURIAS', '2026-09-27 17:25:00', 'domestico', 0, 0),
('2026-09-27', 'VLG2508', 'BIO', 'BILBAO', '2026-09-27 17:30:00', 'domestico', 0, 0),
('2026-09-27', 'THY1299', 'IST', 'ESTAMBUL', '2026-09-27 17:35:00', 'no_schengen', 1, 0),
('2026-09-27', 'TRA6733', 'AMS', 'AMSTERDAM /SCHIPHOL', '2026-09-27 17:55:00', 'schengen', 0, 0),
('2026-09-27', 'VLG2222', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 17:55:00', 'domestico', 0, 0),
('2026-09-27', 'RYR1193', 'TLS', 'TOULOUSE', '2026-09-27 18:05:00', 'schengen', 0, 0),
('2026-09-27', 'EWG9568', 'DUS', 'DUSSELDORF', '2026-09-27 18:20:00', 'schengen', 0, 0),
('2026-09-27', 'BAW2650', 'LGW', 'LONDRES /GATWICK', '2026-09-27 18:35:00', 'no_schengen', 1, 0),
('2026-09-27', 'VLG8829', 'ORY', 'PARIS /ORLY', '2026-09-27 18:35:00', 'schengen', 0, 0),
('2026-09-27', 'RYR1205', 'MXP', 'MILAN /MALPENSA', '2026-09-27 18:40:00', 'schengen', 0, 0),
('2026-09-27', 'VLG6023', 'LHR', 'LONDRES / HEATHROW', '2026-09-27 19:00:00', 'no_schengen', 1, 0),
('2026-09-27', 'CTN1325', 'PRG', 'PRAGA', '2026-09-27 19:05:00', 'schengen', 0, 0),
('2026-09-27', 'VLG8827', 'ORY', 'PARIS /ORLY', '2026-09-27 19:15:00', 'schengen', 0, 0),
('2026-09-27', 'IBB5756', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-27 19:15:00', 'domestico', 0, 1),
('2026-09-27', 'EIN756', 'DUB', 'DUBLIN', '2026-09-27 19:30:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR1339', 'NAP', 'NAPOLES', '2026-09-27 19:30:00', 'schengen', 0, 0),
('2026-09-27', 'TVF4606', 'ORY', 'PARIS /ORLY', '2026-09-27 19:30:00', 'schengen', 0, 0),
('2026-09-27', 'TVF4814', 'NTE', 'NANTES-ATLANTIQUE', '2026-09-27 19:40:00', 'schengen', 0, 0),
('2026-09-27', 'VOE3528', 'BIO', 'BILBAO', '2026-09-27 20:15:00', 'domestico', 0, 0),
('2026-09-27', 'RYR1294', 'ACE', 'LANZAROTE CÉSAR MANRIQUE', '2026-09-27 20:20:00', 'domestico', 0, 1),
('2026-09-27', 'VLG6369', 'SCQ', 'SANTIAGO-ROSALÍA DE CASTRO', '2026-09-27 20:25:00', 'domestico', 0, 0),
('2026-09-27', 'AEA5053', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-27 20:30:00', 'domestico', 0, 0),
('2026-09-27', 'RYR8361', 'STN', 'LONDRES /STANSTED', '2026-09-27 20:30:00', 'no_schengen', 1, 0),
('2026-09-27', 'WMT6023', 'FCO', 'ROMA /FIUMICINO', '2026-09-27 20:45:00', 'schengen', 0, 0),
('2026-09-27', 'IBE2302', 'LEI', 'ALMERÍA', '2026-09-27 20:45:00', 'domestico', 0, 0),
('2026-09-27', 'EZY8051', 'LGW', 'LONDRES /GATWICK', '2026-09-27 20:45:00', 'no_schengen', 1, 0),
('2026-09-27', 'RYR3106', 'MRS', 'MARSELLA', '2026-09-27 20:55:00', 'schengen', 0, 0),
('2026-09-27', 'RYR1304', 'PMI', 'PALMA DE MALLORCA', '2026-09-27 20:55:00', 'domestico', 0, 0),
('2026-09-27', 'IBE1075', 'MAD', 'MADRID-BARAJAS ADOLFO SUÁREZ', '2026-09-27 21:05:00', 'domestico', 0, 0),
('2026-09-27', 'EJU3789', 'MXP', 'MILAN /MALPENSA', '2026-09-27 21:05:00', 'schengen', 0, 0),
('2026-09-27', 'TRA5085', 'EIN', 'EINDHOVEN', '2026-09-27 21:25:00', 'schengen', 0, 0),
('2026-09-27', 'VLG2220', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 22:05:00', 'domestico', 0, 0),
('2026-09-27', 'RYR2402', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 22:50:00', 'domestico', 0, 0),
('2026-09-27', 'RYR3261', 'ORK', 'CORK', '2026-09-27 23:05:00', 'no_schengen', 1, 0),
('2026-09-27', 'VLG2214', 'BCN', 'BARCELONA-EL PRAT JOSEP TARRADELLAS', '2026-09-27 23:25:00', 'domestico', 0, 0),
('2026-09-27', 'AFR1222', 'CDG', 'PARIS /CHARLES DE GAULLE', '2026-09-27 23:25:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3255', 'TFN', 'TENERIFE NORTE-C. LA LAGUNA', '2026-09-27 23:25:00', 'domestico', 0, 1),
('2026-09-27', 'TVF4770', 'ORY', 'PARIS /ORLY', '2026-09-27 23:35:00', 'schengen', 0, 0),
('2026-09-27', 'VLG3053', 'LPA', 'GRAN CANARIA', '2026-09-27 23:45:00', 'domestico', 0, 1),
('2026-09-27', 'VLG6016', 'LGW', 'LONDRES /GATWICK', '2026-09-27 23:55:00', 'no_schengen', 1, 0)
ON DUPLICATE KEY UPDATE
  origin_name=VALUES(origin_name),
  scheduled_arrival=VALUES(scheduled_arrival),
  traffic_class=VALUES(traffic_class),
  border_control=VALUES(border_control),
  is_canary=VALUES(is_canary);

INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5624', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3948'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-24 08:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5940', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG6369'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-24 08:10:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5790', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE2390'
  AND origin_iata='VLC' AND scheduled_arrival='2026-09-24 08:10:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5879', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2512'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-24 08:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1751', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'FIN4797', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7165', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8696', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6070', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5494', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6909', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'JAL6975', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3744', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5209', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5052', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5282', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3045'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-24 10:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5054', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 11:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5211', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 11:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3633', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 11:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5067', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 11:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5223', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 11:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3578', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 11:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5856', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3965'
  AND origin_iata='VLC' AND scheduled_arrival='2026-09-24 11:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5736', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG8221'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-24 12:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5841', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3942'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-24 12:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5490', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN1619', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1755', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'CPA1855', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7166', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6090', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8697', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6580', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5365', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3051'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-24 12:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8128', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-24 13:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5921', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-24 13:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5938', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG6367'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-24 14:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3628', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5225', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5831', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5068', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5060', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5217', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5827', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5769', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3257'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-24 16:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5854', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3165'
  AND origin_iata='FUE' AND scheduled_arrival='2026-09-24 17:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5875', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2508'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-24 17:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5820', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 17:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5221', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 17:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5064', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 17:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1759', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBS1759'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 18:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5765', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3253'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-24 18:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5084', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG8223'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-24 18:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8141', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-24 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5051', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-24 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'DAL7490', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='TRA6727'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-24 19:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'KLM2685', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='TRA6727'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-24 19:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5877', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2510'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-24 19:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'ITY2438', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='AEA5053'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 20:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7164', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'PGT7875', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6134', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5648', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN1752', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-24 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5847', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3946'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-24 21:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5792', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='IBE2392'
  AND origin_iata='VLC' AND scheduled_arrival='2026-09-24 21:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5062', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 22:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5219', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 22:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3624', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 22:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5780', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG8827'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-24 23:40:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5056', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3634', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5213', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-24 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5767', NULL FROM flights
WHERE flight_date='2026-09-24' AND physical_flight='VLG3255'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-24 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5879', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2512'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-25 08:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5839', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3940'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-25 08:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7165', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'JAL6975', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8696', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1751', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6909', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'FIN4797', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5494', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6070', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5052', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3744', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5209', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'DAL7490', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='TRA6727'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-25 09:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'KLM2685', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='TRA6727'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-25 09:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AEH243', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='AIA243'
  AND origin_iata='ZRH' AND scheduled_arrival='2026-09-25 10:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5211', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 10:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5054', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 10:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3633', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 10:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5282', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3045'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-25 11:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5854', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3165'
  AND origin_iata='FUE' AND scheduled_arrival='2026-09-25 11:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3578', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 11:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5067', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 11:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5223', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 11:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5763', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3251'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-25 12:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5877', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2510'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-25 12:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5736', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG8221'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-25 12:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8697', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5490', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN1619', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6090', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'CPA1855', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6580', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1755', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7166', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5916', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6365'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-25 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8128', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-25 13:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5921', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-25 13:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5225', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5068', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3628', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5856', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3965'
  AND origin_iata='VLC' AND scheduled_arrival='2026-09-25 15:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5060', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5217', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5769', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3257'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-25 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5765', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3253'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-25 17:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3680', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5064', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5221', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8141', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-25 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5051', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-25 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5338', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3047'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-25 19:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'DAL7486', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='TRA6733'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-25 19:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5875', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2508'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-25 20:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5219', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5062', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3624', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5828', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6134', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'CPA1916', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'PGT7875', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5648', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN1752', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7164', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-25 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5847', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3946'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-25 21:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5940', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6369'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-25 21:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5938', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6367'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-25 23:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5365', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3051'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-25 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5767', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG3255'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-25 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5786', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG8829'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-25 23:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5923', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6016'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-25 23:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8130', NULL FROM flights
WHERE flight_date='2026-09-25' AND physical_flight='VLG6016'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-25 23:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5213', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 00:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5056', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 00:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3634', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 00:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5817', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 00:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5839', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3940'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-26 08:10:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8696', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6070', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6909', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5494', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1751', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'JAL6975', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'FIN4797', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7165', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3744', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5209', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5052', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'KLM2685', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='TRA6727'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-26 09:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5940', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6369'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-26 09:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5211', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 10:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3633', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 10:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5054', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 10:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5879', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2512'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-26 11:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5736', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG8221'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-26 12:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8697', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBE1071'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5229', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBE1071'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7163', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBE1071'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5841', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3942'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-26 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8128', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-26 13:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5921', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-26 13:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5068', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3628', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5831', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5225', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5938', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6367'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-26 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1759', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='IBS1759'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-26 16:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3657', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5060', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5827', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5217', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2218'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5769', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3257'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-26 16:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5786', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG8829'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-26 17:10:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5875', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2508'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-26 17:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5030', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3161'
  AND origin_iata='ACE' AND scheduled_arrival='2026-09-26 18:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8141', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-26 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5051', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-26 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5856', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3965'
  AND origin_iata='VLC' AND scheduled_arrival='2026-09-26 19:10:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5772', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3259'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-26 20:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5877', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2510'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-26 20:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5916', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG6365'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-26 22:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5828', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2228'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 22:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3542', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2228'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 22:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5227', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2228'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 22:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5961', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG2228'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-26 22:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5854', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3165'
  AND origin_iata='FUE' AND scheduled_arrival='2026-09-26 23:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5767', NULL FROM flights
WHERE flight_date='2026-09-26' AND physical_flight='VLG3255'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-26 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5338', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3047'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-27 00:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'KLM2544', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='TRA6731'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5494', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8696', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'FIN4797', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6909', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'JAL6975', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1751', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6070', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7165', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1751'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 08:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5052', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5209', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3744', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2210'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 08:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5354', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3049'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-27 10:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5211', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 10:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5054', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 10:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3633', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2212'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 10:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5879', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2512'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-27 11:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5067', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 11:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3536', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 11:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5223', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2224'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 11:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5841', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3942'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-27 12:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5736', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG8221'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-27 12:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5763', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3251'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-27 12:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN1619', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7166', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR6580', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AAL8697', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5490', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'CPA1855', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6090', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE1755', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBS1755'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 12:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5486', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6370'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-27 13:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8128', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-27 13:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5921', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6014'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-27 13:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5765', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3253'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-27 14:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5225', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5831', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3628', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5068', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2226'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 14:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5856', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3965'
  AND origin_iata='VLC' AND scheduled_arrival='2026-09-27 14:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5854', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3165'
  AND origin_iata='FUE' AND scheduled_arrival='2026-09-27 16:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5282', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3045'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-27 16:20:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5769', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3257'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-27 16:50:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5845', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3944'
  AND origin_iata='PMI' AND scheduled_arrival='2026-09-27 17:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5875', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2508'
  AND origin_iata='BIO' AND scheduled_arrival='2026-09-27 17:30:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'DAL7486', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='TRA6733'
  AND origin_iata='AMS' AND scheduled_arrival='2026-09-27 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5820', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3680', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5221', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5064', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2222'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 17:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5786', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG8829'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-27 18:35:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8141', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-27 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5051', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6023'
  AND origin_iata='LHR' AND scheduled_arrival='2026-09-27 19:00:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5780', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG8827'
  AND origin_iata='ORY' AND scheduled_arrival='2026-09-27 19:15:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5940', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6369'
  AND origin_iata='SCQ' AND scheduled_arrival='2026-09-27 20:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'CPA1916', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'PGT7875', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'VLG5648', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN1752', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'AVA6134', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW7164', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='IBE1075'
  AND origin_iata='MAD' AND scheduled_arrival='2026-09-27 21:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3624', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 22:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5219', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 22:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LAN5755', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 22:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5062', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2220'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 22:05:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'QTR3634', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 23:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'LVL5213', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 23:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5056', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG2214'
  AND origin_iata='BCN' AND scheduled_arrival='2026-09-27 23:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5767', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3255'
  AND origin_iata='TFN' AND scheduled_arrival='2026-09-27 23:25:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5436', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG3053'
  AND origin_iata='LPA' AND scheduled_arrival='2026-09-27 23:45:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5923', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6016'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-27 23:55:00';
INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'BAW8130', NULL FROM flights
WHERE flight_date='2026-09-27' AND physical_flight='VLG6016'
  AND origin_iata='LGW' AND scheduled_arrival='2026-09-27 23:55:00';

-- Primera observación: solo vuelos con sala/cinta ya publicada.
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR5530'
  AND f.origin_iata='TSF' AND f.scheduled_arrival='2026-09-24 00:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TAP1106'
  AND f.origin_iata='LIS' AND f.scheduled_arrival='2026-09-24 00:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR9669'
  AND f.origin_iata='TLS' AND f.scheduled_arrival='2026-09-24 00:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG3948'
  AND f.origin_iata='PMI' AND f.scheduled_arrival='2026-09-24 08:05:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TVF4854'
  AND f.origin_iata='MPL' AND f.scheduled_arrival='2026-09-24 08:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG6369'
  AND f.origin_iata='SCQ' AND f.scheduled_arrival='2026-09-24 08:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='IBE2390'
  AND f.origin_iata='VLC' AND f.scheduled_arrival='2026-09-24 08:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG2512'
  AND f.origin_iata='BIO' AND f.scheduled_arrival='2026-09-24 08:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='IBE2300'
  AND f.origin_iata='LEI' AND f.scheduled_arrival='2026-09-24 08:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='IBS1751'
  AND f.origin_iata='MAD' AND f.scheduled_arrival='2026-09-24 08:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='WMT6309'
  AND f.origin_iata='MXP' AND f.scheduled_arrival='2026-09-24 08:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='WMT6039'
  AND f.origin_iata='FCO' AND f.scheduled_arrival='2026-09-24 08:40:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR1193'
  AND f.origin_iata='TLS' AND f.scheduled_arrival='2026-09-24 08:45:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='WMT6777'
  AND f.origin_iata='VCE' AND f.scheduled_arrival='2026-09-24 08:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG2210'
  AND f.origin_iata='BCN' AND f.scheduled_arrival='2026-09-24 08:55:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VOE2686'
  AND f.origin_iata='LIL' AND f.scheduled_arrival='2026-09-24 09:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='EDW220'
  AND f.origin_iata='ZRH' AND f.scheduled_arrival='2026-09-24 09:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='WMT3195'
  AND f.origin_iata='OTP' AND f.scheduled_arrival='2026-09-24 09:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TAP1102'
  AND f.origin_iata='LIS' AND f.scheduled_arrival='2026-09-24 09:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR2205'
  AND f.origin_iata='VLC' AND f.scheduled_arrival='2026-09-24 09:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='EJU3789'
  AND f.origin_iata='MXP' AND f.scheduled_arrival='2026-09-24 09:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='EWG9568'
  AND f.origin_iata='DUS' AND f.scheduled_arrival='2026-09-24 09:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='WUK5369'
  AND f.origin_iata='LTN' AND f.scheduled_arrival='2026-09-24 09:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TRA6851'
  AND f.origin_iata='RTM' AND f.scheduled_arrival='2026-09-24 09:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG3045'
  AND f.origin_iata='LPA' AND f.scheduled_arrival='2026-09-24 10:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TRA5085'
  AND f.origin_iata='EIN' AND f.scheduled_arrival='2026-09-24 10:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR076'
  AND f.origin_iata='BGY' AND f.scheduled_arrival='2026-09-24 10:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='NGN7950'
  AND f.origin_iata='BTS' AND f.scheduled_arrival='2026-09-24 10:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR9894'
  AND f.origin_iata='BUD' AND f.scheduled_arrival='2026-09-24 10:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='AEA5051'
  AND f.origin_iata='MAD' AND f.scheduled_arrival='2026-09-24 10:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='LAV1447'
  AND f.origin_iata='PRG' AND f.scheduled_arrival='2026-09-24 10:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG2212'
  AND f.origin_iata='BCN' AND f.scheduled_arrival='2026-09-24 11:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='BAW2648'
  AND f.origin_iata='LGW' AND f.scheduled_arrival='2026-09-24 11:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR9775'
  AND f.origin_iata='WRO' AND f.scheduled_arrival='2026-09-24 11:05:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR352'
  AND f.origin_iata='PMI' AND f.scheduled_arrival='2026-09-24 11:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR5449'
  AND f.origin_iata='NTE' AND f.scheduled_arrival='2026-09-24 11:15:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='IBE2019'
  AND f.origin_iata='MLN' AND f.scheduled_arrival='2026-09-24 11:20:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='DLH1140'
  AND f.origin_iata='FRA' AND f.scheduled_arrival='2026-09-24 11:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='EZY8005'
  AND f.origin_iata='LGW' AND f.scheduled_arrival='2026-09-24 11:30:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='IBB5752'
  AND f.origin_iata='LPA' AND f.scheduled_arrival='2026-09-24 11:40:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG2224'
  AND f.origin_iata='BCN' AND f.scheduled_arrival='2026-09-24 11:45:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG3965'
  AND f.origin_iata='VLC' AND f.scheduled_arrival='2026-09-24 11:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='LHX1822'
  AND f.origin_iata='MUC' AND f.scheduled_arrival='2026-09-24 12:05:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG8221'
  AND f.origin_iata='ORY' AND f.scheduled_arrival='2026-09-24 12:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG3942'
  AND f.origin_iata='PMI' AND f.scheduled_arrival='2026-09-24 12:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TVF4600'
  AND f.origin_iata='ORY' AND f.scheduled_arrival='2026-09-24 12:40:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='IBS1755'
  AND f.origin_iata='MAD' AND f.scheduled_arrival='2026-09-24 12:45:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR1165'
  AND f.origin_iata='BCN' AND f.scheduled_arrival='2026-09-24 12:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG3051'
  AND f.origin_iata='LPA' AND f.scheduled_arrival='2026-09-24 12:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='EZY3391'
  AND f.origin_iata='LPL' AND f.scheduled_arrival='2026-09-24 12:50:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR8476'
  AND f.origin_iata='BLQ' AND f.scheduled_arrival='2026-09-24 13:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG6014'
  AND f.origin_iata='LGW' AND f.scheduled_arrival='2026-09-24 13:20:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TVF4814'
  AND f.origin_iata='NTE' AND f.scheduled_arrival='2026-09-24 13:20:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR5269'
  AND f.origin_iata='EIN' AND f.scheduled_arrival='2026-09-24 13:25:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR2200'
  AND f.origin_iata='VCE' AND f.scheduled_arrival='2026-09-24 13:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG6367'
  AND f.origin_iata='SCQ' AND f.scheduled_arrival='2026-09-24 14:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='PGT1109'
  AND f.origin_iata='SAW' AND f.scheduled_arrival='2026-09-24 14:40:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '1', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VLG2226'
  AND f.origin_iata='BCN' AND f.scheduled_arrival='2026-09-24 14:45:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TAP1104'
  AND f.origin_iata='LIS' AND f.scheduled_arrival='2026-09-24 15:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '3', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TVF4606'
  AND f.origin_iata='ORY' AND f.scheduled_arrival='2026-09-24 15:15:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='TVF4852'
  AND f.origin_iata='MRS' AND f.scheduled_arrival='2026-09-24 15:35:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '2', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='VOE3320'
  AND f.origin_iata='EAS' AND f.scheduled_arrival='2026-09-24 16:00:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '8', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='EJU1301'
  AND f.origin_iata='GVA' AND f.scheduled_arrival='2026-09-24 16:05:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'B', '7', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR5496'
  AND f.origin_iata='RBA' AND f.scheduled_arrival='2026-09-24 16:05:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '6', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR8445'
  AND f.origin_iata='FCO' AND f.scheduled_arrival='2026-09-24 16:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '4', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR5231'
  AND f.origin_iata='IBZ' AND f.scheduled_arrival='2026-09-24 16:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');
INSERT INTO observations
(flight_id, source, observed_at, status, hall, belt, baggage_state, occupancy_level, raw_data)
SELECT f.id, 'aena', '2026-09-23 16:27:00', 'Programado', 'A', '5', 'pendiente', 'no_verificable', JSON_OBJECT('import', 'Aena Infovuelos', 'preliminary', TRUE)
FROM flights f
WHERE f.flight_date='2026-09-24' AND f.physical_flight='RYR5467'
  AND f.origin_iata='OPO' AND f.scheduled_arrival='2026-09-24 16:10:00'
  AND NOT EXISTS (SELECT 1 FROM observations o WHERE o.flight_id=f.id AND o.source='aena' AND o.observed_at='2026-09-23 16:27:00');

COMMIT;

-- Resumen: 408 vuelos físicos; 67 con sala/cinta preliminar.

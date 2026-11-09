-- ==============================================================================
-- DOCTOR CONNECT - SEED DATA
-- Default password for all seed users: password123
-- (Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi is 'password',
--  $2y$10$e0MYzXyjpJS7Pd0RVvHwHeFjKN.Id70SJcatNV34/y8Pq7Z1Mh.7W for password123)
-- ==============================================================================

USE `doctor_connect`;

-- 1. CLINIC SETTINGS
INSERT INTO `clinic_settings` (`setting_key`, `setting_value`) VALUES
('clinic_name', 'DoctorConnect Telehealth Clinic'),
('clinic_phone', '+1 (800) 555-0199'),
('clinic_email', 'care@doctorconnect.com'),
('clinic_address', '742 Evergreen Medical Suites, Health City, HC 94102'),
('clinic_currency', '$'),
('default_slot_duration', '15'),
('cancel_window_hours', '2'),
('jitsi_domain', 'meet.jit.si')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 2. SEED USERS
-- Password for all is: password123
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password_hash`, `role`) VALUES
(1, 'Admin / Receptionist', 'admin@doctorconnect.com', '+15550000001', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'admin'),
(2, 'Dr. Marcus Vance', 'dr.vance@doctorconnect.com', '+15550000002', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'doctor'),
(3, 'Dr. Sarah Jenkins', 'dr.sarah@doctorconnect.com', '+15550000003', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'doctor'),
(4, 'Dr. Aris Thorne', 'dr.thorne@doctorconnect.com', '+15550000004', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'doctor'),
(5, 'John Doe', 'john.doe@example.com', '+15550000005', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'patient'),
(6, 'Emily Watson', 'emily.w@example.com', '+15550000006', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'patient'),
(7, 'Michael Brown', 'mbrown@example.com', '+15550000007', '$2y$10$ep5BskF2Q915K21zZ5P/a.j2dE5eWq6Vw8E3uYF/vI0F2gZkLgZ6O', 'patient')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. SEED DOCTORS
INSERT INTO `doctors` (`id`, `user_id`, `specialization`, `consultation_fee`, `avg_consultation_mins`, `custom_meeting_link`, `bio`, `is_active`) VALUES
(1, 2, 'General Physician & Internal Medicine', 45.00, 15, NULL, 'Board certified internist with 12+ years experience in primary and preventative care.', 1),
(2, 3, 'Cardiologist', 80.00, 20, NULL, 'Specialist in clinical cardiology, cardiovascular prevention, and hypertension management.', 1),
(3, 4, 'Dermatologist', 65.00, 15, NULL, 'Expert in adult & pediatric skin conditions, acne management, and telehealth consultations.', 1)
ON DUPLICATE KEY UPDATE `specialization` = VALUES(`specialization`);

-- 4. SEED DOCTOR SCHEDULES (0=Sun ... 6=Sat)
-- Dr. Vance: Mon(1) - Fri(5), 09:00 - 13:00, 15 min slots
INSERT INTO `doctor_schedules` (`doctor_id`, `day_of_week`, `start_time`, `end_time`, `slot_duration`, `is_active`) VALUES
(1, 1, '09:00:00', '13:00:00', 15, 1),
(1, 2, '09:00:00', '13:00:00', 15, 1),
(1, 3, '09:00:00', '13:00:00', 15, 1),
(1, 4, '09:00:00', '13:00:00', 15, 1),
(1, 5, '09:00:00', '13:00:00', 15, 1),
-- Dr. Sarah: Mon(1), Wed(3), Fri(5), 10:00 - 14:00, 20 min slots
(2, 1, '10:00:00', '14:00:00', 20, 1),
(2, 3, '10:00:00', '14:00:00', 20, 1),
(2, 5, '10:00:00', '14:00:00', 20, 1),
-- Dr. Thorne: Tue(2), Thu(4), Sat(6), 14:00 - 18:00, 15 min slots
(3, 2, '14:00:00', '18:00:00', 15, 1),
(3, 4, '14:00:00', '18:00:00', 15, 1),
(3, 6, '14:00:00', '18:00:00', 15, 1)
ON DUPLICATE KEY UPDATE `slot_duration` = VALUES(`slot_duration`);

-- 5. SAMPLE APPOINTMENTS FOR TODAY (CURRENT DATE)
-- Setting up an active live queue scenario for Dr. Marcus Vance (Doctor 1)
-- Token #1: completed
-- Token #2: serving (currently in consultation)
-- Token #3: waiting (NEXT in line!)
-- Token #4: waiting
INSERT INTO `appointments` (`id`, `patient_id`, `doctor_id`, `appointment_date`, `token_number`, `status`, `meeting_link`, `estimated_time`, `reason_for_visit`) VALUES
(1, 7, 1, CURRENT_DATE(), 1, 'completed', 'https://meet.jit.si/doctor-connect-1', CONCAT(CURRENT_DATE(), ' 09:00:00'), 'Follow up on blood pressure meds'),
(2, 6, 1, CURRENT_DATE(), 2, 'serving', 'https://meet.jit.si/doctor-connect-2', CONCAT(CURRENT_DATE(), ' 09:15:00'), 'Seasonal allergy and mild cough'),
(3, 5, 1, CURRENT_DATE(), 3, 'waiting', 'https://meet.jit.si/doctor-connect-3', CONCAT(CURRENT_DATE(), ' 09:30:00'), 'Fever and throat pain consultation')
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

-- 6. AUDIT LOGS FOR TODAY'S QUEUE
INSERT INTO `queue_logs` (`appointment_id`, `action`, `acted_by`) VALUES
(1, 'called', 2),
(1, 'completed', 2),
(2, 'called', 2),
(2, 'serving', 2);

-- 7. PRESCRIPTION FOR COMPLETED APPOINTMENT #1
INSERT INTO `prescriptions` (`appointment_id`, `doctor_id`, `patient_id`, `diagnosis`, `symptoms`, `medicines`, `advice`, `follow_up_date`) VALUES
(1, 1, 7, 'Stage 1 Hypertension (Controlled)', 'Occasional morning headaches, elevated BP readings', 
'[{"name":"Amlodipine 5mg","dosage":"1 tablet","frequency":"Once daily in morning","duration":"30 days"},{"name":"Hydrochlorothiazide 12.5mg","dosage":"1 tablet","frequency":"Once daily","duration":"30 days"}]', 
'Maintain low sodium diet (<2g/day). Daily 30 min brisk walk. Log daily morning BP.', 
DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))
ON DUPLICATE KEY UPDATE `diagnosis` = VALUES(`diagnosis`);

-- 8. INITIAL NOTIFICATIONS FOR PATIENT 5 (JOHN DOE)
INSERT INTO `notifications` (`user_id`, `appointment_id`, `message`, `type`, `is_read`) VALUES
(5, 3, 'Your token #3 has been confirmed with Dr. Marcus Vance for today. Estimated time: 09:30 AM.', 'token_booked', 0),
(5, 3, 'You are NEXT in queue! Dr. Marcus Vance is currently on Token #2. Please get ready to join the video call.', 'token_next', 0);

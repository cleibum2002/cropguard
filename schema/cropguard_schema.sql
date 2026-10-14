-- CropGuard database structure (tables and columns only - no data).
-- Import into an empty MySQL/MariaDB database, e.g. one named crop_damage_system.
-- Then create the first Admin account as shown in README.md.
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `admin_notification` (
  `Notification_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Target_Role` varchar(20) NOT NULL DEFAULT 'Admin',
  `Message` text NOT NULL,
  `Related_Report_ID` int(11) DEFAULT NULL,
  `Redirect_URL` varchar(255) DEFAULT NULL,
  `Is_Read` tinyint(1) DEFAULT 0,
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Notification_ID`),
  KEY `fk_notif_report` (`Related_Report_ID`),
  CONSTRAINT `fk_notif_report` FOREIGN KEY (`Related_Report_ID`) REFERENCES `damage_report` (`Damage_Report_ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `aid_distribution` (
  `Distribution_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Damage_Report_ID` int(11) NOT NULL,
  `Farmer_ID` int(11) NOT NULL,
  `Category` varchar(100) NOT NULL,
  `Item_ID` int(11) DEFAULT NULL,
  `Quantity` decimal(10,2) NOT NULL,
  `Unit` varchar(50) NOT NULL,
  `Remarks` text DEFAULT NULL,
  `Status` enum('Pending Distribution','Claimed') DEFAULT 'Pending Distribution',
  `Distributed_By` int(11) NOT NULL,
  `Date_Distributed` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Distribution_ID`),
  KEY `fk_aid_report` (`Damage_Report_ID`),
  KEY `fk_aid_item` (`Item_ID`),
  CONSTRAINT `fk_aid_item` FOREIGN KEY (`Item_ID`) REFERENCES `masterfile_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_aid_report` FOREIGN KEY (`Damage_Report_ID`) REFERENCES `damage_report` (`Damage_Report_ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `crop` (
  `Crop_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Crop_Name` varchar(50) DEFAULT NULL,
  `Type_ID` int(11) DEFAULT NULL,
  `Planting_Date` date DEFAULT NULL,
  `Expected_Harvest` date DEFAULT NULL,
  `Area_Hectares` decimal(10,2) DEFAULT NULL,
  `Farmer_ID` int(11) DEFAULT NULL,
  `Location_ID` int(11) DEFAULT NULL,
  PRIMARY KEY (`Crop_ID`),
  KEY `Type_ID` (`Type_ID`),
  KEY `Farmer_ID` (`Farmer_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `crop_type` (
  `Type_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Crop_Name` varchar(100) DEFAULT NULL,
  `Commodity_Type` varchar(50) DEFAULT NULL,
  `Growth_Duration` varchar(50) DEFAULT NULL,
  `Standard_Production_Cost` decimal(12,2) DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`Type_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `damage_report` (
  `Damage_Report_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Client_Token` varchar(40) DEFAULT NULL,
  `Farmer_ID` int(11) DEFAULT NULL,
  `Location_ID` int(11) DEFAULT NULL,
  `Crop_ID` int(11) DEFAULT NULL,
  `Affected_Area` decimal(10,2) DEFAULT NULL,
  `Inspected_Area` decimal(10,2) DEFAULT NULL,
  `Report_Date` datetime DEFAULT current_timestamp(),
  `Last_Updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `Damage_Type` varchar(50) DEFAULT NULL,
  `Damage_Severity` enum('Minimal','Light','Moderate','Severe','Total Loss','Low','High') DEFAULT NULL,
  `Farmer_Reported_Severity` enum('Minimal','Light','Moderate','Severe','Total Loss','Low','High') DEFAULT NULL,
  `Status` enum('Pending','Validated','Inspected','Approved','Rejected','Archived') DEFAULT 'Pending',
  `Validated_By` int(11) DEFAULT NULL,
  `Approved_By` int(11) DEFAULT NULL,
  `Technician_ID` int(11) DEFAULT NULL,
  `Visit_Date` date DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Rejection_Reason` varchar(300) DEFAULT NULL,
  `Is_Inspected` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`Damage_Report_ID`),
  UNIQUE KEY `uniq_damage_report_client_token` (`Client_Token`),
  KEY `Farmer_ID` (`Farmer_ID`),
  KEY `Location_ID` (`Location_ID`),
  KEY `Technician_ID` (`Technician_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `damage_report_images` (
  `Image_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Damage_Report_ID` int(11) NOT NULL,
  `Uploaded_By_Role` enum('Farmer','Technician') NOT NULL DEFAULT 'Farmer',
  `Image_Path` varchar(255) NOT NULL,
  `Uploaded_At` timestamp NOT NULL DEFAULT current_timestamp(),
  `Geo_Lat` decimal(10,8) DEFAULT NULL,
  `Geo_Lng` decimal(11,8) DEFAULT NULL,
  PRIMARY KEY (`Image_ID`),
  KEY `fk_images_report` (`Damage_Report_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `farm_location` (
  `Location_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Farmer_ID` int(11) DEFAULT NULL,
  `Type_ID` int(11) DEFAULT NULL,
  `Barangay` text DEFAULT NULL,
  `Area_Size` decimal(10,2) DEFAULT NULL,
  `Latitude` decimal(10,8) DEFAULT NULL,
  `Longitude` decimal(11,8) DEFAULT NULL,
  `Land_Type` varchar(50) DEFAULT NULL,
  `Cropping_Period` varchar(50) DEFAULT NULL,
  `Is_Archived` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`Location_ID`),
  KEY `Farmer_ID` (`Farmer_ID`),
  KEY `Type_ID` (`Type_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `masterfile_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `current_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(50) DEFAULT 'Bags',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `messages` (
  `Message_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Sender_ID` int(11) NOT NULL,
  `Receiver_ID` int(11) NOT NULL,
  `Message` text NOT NULL,
  `Is_Read` tinyint(4) DEFAULT 0,
  `Created_At` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`Message_ID`),
  KEY `Sender_ID` (`Sender_ID`),
  KEY `Receiver_ID` (`Receiver_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `notification` (
  `Notification_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Farmer_ID` int(11) NOT NULL,
  `Message` text NOT NULL,
  `Redirect_URL` varchar(255) DEFAULT NULL,
  `Is_Read` tinyint(1) DEFAULT 0,
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Notification_ID`),
  KEY `Farmer_ID` (`Farmer_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `registration_requests` (
  `Request_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Last_Name` varchar(50) NOT NULL,
  `First_Name` varchar(50) NOT NULL,
  `Middle_Name` varchar(50) DEFAULT NULL,
  `Suffix` varchar(10) DEFAULT NULL,
  `Birth_Date` date NOT NULL,
  `Gender` varchar(20) NOT NULL,
  `Civil_Status` varchar(20) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `Contact_Number` varchar(20) NOT NULL,
  `RSBA_No` varchar(50) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Email_Verified` tinyint(1) NOT NULL DEFAULT 0,
  `Verification_Token` varchar(64) DEFAULT NULL,
  `Verification_Token_Expires` datetime DEFAULT NULL,
  `Created_At` datetime NOT NULL DEFAULT current_timestamp(),
  `Verified_At` datetime DEFAULT NULL,
  PRIMARY KEY (`Request_ID`),
  UNIQUE KEY `uq_request_email` (`Email`),
  UNIQUE KEY `uq_request_rsbsa` (`RSBA_No`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `seed_requests` (
  `Request_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Farmer_ID` int(11) NOT NULL,
  `Requested_Seed` varchar(100) NOT NULL,
  `Description` text DEFAULT NULL,
  `Status` enum('Pending','Approved by Admin','Ready for Pickup','Claimed','Rejected') DEFAULT 'Pending',
  `Request_Date` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`Request_ID`),
  KEY `Farmer_ID` (`Farmer_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `system_logs` (
  `Log_ID` int(11) NOT NULL AUTO_INCREMENT,
  `User_ID` int(11) DEFAULT NULL,
  `User_Role` varchar(50) DEFAULT NULL,
  `Action` varchar(100) DEFAULT NULL,
  `Description` text DEFAULT NULL,
  `Module` varchar(50) DEFAULT NULL,
  `IP_Address` varchar(45) DEFAULT NULL,
  `Created_At` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`Log_ID`),
  KEY `User_ID` (`User_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `users` (
  `User_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Role` enum('Admin','MAO','Technician','Farmer') NOT NULL,
  `Fullname` varchar(100) NOT NULL,
  `Last_Name` varchar(50) DEFAULT NULL,
  `First_Name` varchar(50) DEFAULT NULL,
  `Middle_Name` varchar(50) DEFAULT NULL,
  `Suffix` varchar(10) DEFAULT NULL,
  `Birth_Date` date DEFAULT NULL,
  `Gender` varchar(20) DEFAULT NULL,
  `Civil_Status` varchar(20) DEFAULT NULL,
  `Email` varchar(100) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Contact_Number` varchar(20) DEFAULT NULL,
  `RSBA_No` varchar(50) DEFAULT NULL,
  `Assigned_Area` varchar(100) DEFAULT NULL,
  `STATUS` enum('Active','Inactive') DEFAULT 'Active',
  `Email_Verified` tinyint(1) NOT NULL DEFAULT 0,
  `Verification_Token` varchar(64) DEFAULT NULL,
  `Verification_Token_Expires` datetime DEFAULT NULL,
  `Pending_Email` varchar(255) DEFAULT NULL,
  `Phone_Verified` tinyint(1) NOT NULL DEFAULT 0,
  `Failed_Login_Attempts` int(11) NOT NULL DEFAULT 0,
  `Lockout_Until` datetime DEFAULT NULL,
  PRIMARY KEY (`User_ID`),
  UNIQUE KEY `Email` (`Email`),
  UNIQUE KEY `Email_2` (`Email`),
  UNIQUE KEY `RSBA_No` (`RSBA_No`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;

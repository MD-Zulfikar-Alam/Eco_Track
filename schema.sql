
-- Create database first if needed:
-- CREATE DATABASE ecotrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE ecotrack;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  dob DATE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(128) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  CONSTRAINT fk_pw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS footprints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  recorded_on DATE NOT NULL,
  electricity_kg DECIMAL(12,4) NOT NULL DEFAULT 0,
  transport_kg DECIMAL(12,4) NOT NULL DEFAULT 0,
  meat_kg DECIMAL(12,4) NOT NULL DEFAULT 0,
  flights_kg DECIMAL(12,4) NOT NULL DEFAULT 0,
  total_kg DECIMAL(12,4) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, recorded_on),
  CONSTRAINT fk_fp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- Quiz questions
CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_text TEXT NOT NULL,
  option_a VARCHAR(255) NOT NULL,
  option_b VARCHAR(255) NOT NULL,
  option_c VARCHAR(255) NOT NULL,
  option_d VARCHAR(255) NOT NULL,
  correct_option ENUM('A','B','C','D') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Quiz seed (15 default questions)
INSERT INTO quiz_questions (question_text, option_a, option_b, option_c, option_d, correct_option, is_active) VALUES
('Which gas is primarily responsible for global warming?','Oxygen','Methane','Carbon dioxide','Nitrogen','C',1),
('What is a common renewable energy source?','Coal','Wind','Diesel','Petrol','B',1),
('Which practice reduces plastic waste?','Using single-use bags','Using reusable bags','Burning plastic','Throwing in rivers','B',1),
('Deforestation mainly increases which gas in the atmosphere?','Carbon dioxide','Helium','Neon','Hydrogen','A',1),
('Which of these is an example of public transport?','Car','Motorbike','Bus','Scooter','C',1),
('Which appliance typically uses the most household electricity?','LED bulb','Refrigerator','Phone charger','Toaster','B',1),
('Planting trees helps by:','Increasing CO₂','Reducing CO₂','Creating more plastic','Heating cities','B',1),
('Which diet change tends to lower your footprint?','More red meat','More plant-based meals','More air-freighted fruit','More dairy','B',1),
('What does kWh measure?','Fuel volume','Electrical energy','Water pressure','Air speed','B',1),
('Which transport emits the most CO₂ per km per person (typical)?','Cycling','Walking','Commercial flight','Train','C',1),
('Best way to dispose of e-waste?','Landfill','Burning','Certified e-waste recycling','Throw in regular trash','C',1),
('A smart thermostat saves energy by:','Heating when windows open','Maintaining constant high temp','Optimizing heating/cooling schedules','Always on','C',1),
('Which label indicates high energy efficiency?','Energy Star','High Watt','Ultra Power','Super Heat','A',1),
('Carbon footprint is measured mostly in:','Liters','Kilograms of CO₂ equivalent','Watts','Lumens','B',1),
('Which action saves water at home?','Fixing leaks','Longer showers','Running half-empty dishwasher','Watering at noon','A',1);


-- Quiz attempts (stores results)
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  score INT NOT NULL,
  total INT NOT NULL,
  percentage DECIMAL(5,2) NOT NULL,
  question_ids TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at),
  CONSTRAINT fk_quiz_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- Gamified Challenges
CREATE TABLE IF NOT EXISTS challenges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  description TEXT,
  xp INT NOT NULL DEFAULT 50,
  frequency ENUM('once','daily','weekly','monthly') NOT NULL DEFAULT 'once',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_challenges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  challenge_id INT NOT NULL,
  status ENUM('available','in_progress','completed') NOT NULL DEFAULT 'available',
  times_completed INT NOT NULL DEFAULT 0,
  last_completed_at DATETIME NULL,
  streak INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_chal (user_id, challenge_id),
  INDEX idx_user (user_id),
  CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uc_ch FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_progress (
  user_id INT PRIMARY KEY,
  xp INT NOT NULL DEFAULT 0,
  level INT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a few default challenges
INSERT INTO challenges (title, description, xp, frequency, is_active) VALUES
('Meatless Monday', 'Go a full day without eating red meat.', 80, 'weekly', 1),
('Commute Green', 'Use public transport, bike, or walk for your commute.', 60, 'daily', 1),
('Unplug Hour', 'Turn off unused appliances and lights for one hour.', 40, 'daily', 1),
('Waste Audit', 'Sort your trash and recycle correctly for one week.', 120, 'monthly', 1),
('Airline Alternatives', 'Avoid short-haul flights this month when possible.', 150, 'monthly', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title);


-- Per-completion logs for challenges
CREATE TABLE IF NOT EXISTS challenge_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  challenge_id INT NOT NULL,
  xp_awarded INT NOT NULL,
  completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, completed_at),
  INDEX (challenge_id, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Extra challenge seeds
INSERT INTO challenges (title, description, xp, frequency, is_active) VALUES
('Plastic-Free Day', 'Avoid single-use plastic for a whole day (carry your bottle, bag, and box).', 70, 'daily', 1),
('Car-Free Day', 'Skip the car for one day. Walk, bike, or take the bus/train.', 90, 'weekly', 1),
('Cold Wash', 'Do your laundry on cold to save energy.', 50, 'weekly', 1),
('5-Min Shower', 'Cap your shower at 5 minutes today.', 40, 'daily', 1),
('BYO Cup', 'Use your own mug/cup for takeaway drinks.', 30, 'daily', 1),
('Thermostat Tweak', 'Turn your heating/cooling down by 1°C this week.', 80, 'weekly', 1),
('Neighborhood Clean-up', 'Pick up litter in your area or join a clean-up.', 120, 'monthly', 1);


-- Eco Tips
CREATE TABLE IF NOT EXISTS eco_tips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  tip_text TEXT NOT NULL,
  category VARCHAR(60) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  author_user_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (is_active, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a few tips
INSERT INTO eco_tips (title, tip_text, category, is_active) VALUES
  ('Unplug & save', 'Unplug chargers and devices when not in use to cut phantom load.', 'energy', 1),
  ('Meatless meal', 'Swap one meat-based meal for a plant-based option today.', 'food', 1),
  ('Short shower', 'Aim for a 5-minute shower to save water and energy.', 'water', 1),
  ('Cold wash', 'Wash clothes on cold—modern detergents work great at low temps.', 'energy', 1),
  ('Bring your bottle', 'Carry a reusable water bottle to avoid single-use plastic.', 'waste', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title);


-- Community: threads & replies
CREATE TABLE IF NOT EXISTS community_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  parent_id INT NULL,
  content TEXT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_parent (parent_id),
  INDEX idx_user (user_id),
  CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES community_posts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Environmental Map Markers
CREATE TABLE IF NOT EXISTS map_markers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL, -- null for global markers
  title VARCHAR(140) NOT NULL,
  description TEXT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  is_global TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id, is_global),
  INDEX (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Map markers: tree planting metadata
ALTER TABLE map_markers ADD COLUMN IF NOT EXISTS is_tree TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE map_markers ADD COLUMN IF NOT EXISTS tree_count INT NOT NULL DEFAULT 0;


-- Blog: posts and comments
CREATE TABLE IF NOT EXISTS blog_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  body MEDIUMTEXT NOT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blog_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  blog_id INT NOT NULL,
  user_id INT NOT NULL,
  content TEXT NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (blog_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

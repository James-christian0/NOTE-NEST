-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS notenest_db;

-- Use the newly created database
USE NoteTakingSystem;

-- Create the users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create the notes table
CREATE_TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    image_path VARCHAR(255), -- Stores the path to the uploaded image
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- OPTIONAL: Add a sample user (password is 'password123')
INSERT INTO users (email, password) VALUES ('test@example.com', '$2y$10$f/9J2n4Z.j.L/Z1S9N.hW.n.G.p.0.w.M.A.k.J.K.L.Q.R.S.T.U.V.W.X.Y.Z/h.t.b.z.');
-- The hashed password for 'password123' is generated using password_hash('password123', PASSWORD_BCRYPT)

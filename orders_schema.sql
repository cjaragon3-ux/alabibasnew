-- Run once in phpMyAdmin / MySQL (same database your auth_api.php uses)
CREATE TABLE IF NOT EXISTS orders (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  order_number  VARCHAR(20)  NOT NULL UNIQUE,
  user_email    VARCHAR(190) NOT NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  payment       VARCHAR(40)  NOT NULL DEFAULT '',
  note          VARCHAR(500) NOT NULL DEFAULT '',
  total         DECIMAL(10,2) NOT NULL DEFAULT 0,
  status        ENUM('placed','processing','shipped','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'placed',
  tracking_no   VARCHAR(60)  NOT NULL DEFAULT '',
  courier       VARCHAR(60)  NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  name     VARCHAR(200) NOT NULL,
  size     VARCHAR(20)  NOT NULL DEFAULT '',
  qty      INT NOT NULL,
  price    DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_events (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  status   VARCHAR(30) NOT NULL,
  message  VARCHAR(255) NOT NULL DEFAULT '',
  at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

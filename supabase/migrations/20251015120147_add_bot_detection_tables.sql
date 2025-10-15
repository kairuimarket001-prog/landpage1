/*
  # 添加机器人检测相关表

  ## 新建表
  1. users - 用户主表
  2. user_profiles - 用户资料
  3. user_fingerprints - 浏览器指纹
  4. bot_detection_scores - 评分(0-100)
  5. traffic_sources - 流量来源
  6. ip_whitelist - IP白名单
  7. ip_blacklist - IP黑名单
  8. fingerprint_whitelist - 指纹白名单

  ## 注意
  user_behaviors和customer_service_assignments已存在，将在后续迁移中修改
*/

CREATE TABLE IF NOT EXISTS users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  session_id text,
  fingerprint_hash text,
  first_visit_at timestamptz DEFAULT now(),
  last_visit_at timestamptz DEFAULT now(),
  visit_count integer DEFAULT 1,
  user_type text DEFAULT 'suspicious',
  is_whitelisted boolean DEFAULT false,
  is_blacklisted boolean DEFAULT false,
  manual_override boolean DEFAULT false,
  notes text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_users_fingerprint ON users(fingerprint_hash);
CREATE INDEX IF NOT EXISTS idx_users_session ON users(session_id);
CREATE INDEX IF NOT EXISTS idx_users_type ON users(user_type);
CREATE INDEX IF NOT EXISTS idx_users_last_visit ON users(last_visit_at);

CREATE TABLE IF NOT EXISTS user_profiles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid REFERENCES users(id) ON DELETE CASCADE,
  ip_address text,
  ip_country text,
  ip_city text,
  ip_isp text,
  device_type text,
  os text,
  browser text,
  browser_version text,
  screen_resolution text,
  timezone text,
  language text,
  user_agent text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_user_profiles_user_id ON user_profiles(user_id);
CREATE INDEX IF NOT EXISTS idx_user_profiles_ip ON user_profiles(ip_address);

CREATE TABLE IF NOT EXISTS user_fingerprints (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid REFERENCES users(id) ON DELETE CASCADE,
  canvas_fingerprint text,
  webgl_fingerprint text,
  audio_fingerprint text,
  fonts jsonb DEFAULT '[]'::jsonb,
  plugins jsonb DEFAULT '[]'::jsonb,
  touch_support boolean DEFAULT false,
  hardware_concurrency integer,
  device_memory integer,
  color_depth integer,
  fingerprint_hash text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_user_fingerprints_user_id ON user_fingerprints(user_id);
CREATE INDEX IF NOT EXISTS idx_user_fingerprints_hash ON user_fingerprints(fingerprint_hash);

CREATE TABLE IF NOT EXISTS bot_detection_scores (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid REFERENCES users(id) ON DELETE CASCADE,
  total_score integer DEFAULT 0,
  ip_score integer DEFAULT 0,
  user_agent_score integer DEFAULT 0,
  request_pattern_score integer DEFAULT 0,
  fingerprint_score integer DEFAULT 0,
  behavior_score integer DEFAULT 0,
  source_score integer DEFAULT 0,
  confidence numeric(3,2) DEFAULT 0,
  detection_details jsonb DEFAULT '{}'::jsonb,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_bot_scores_user_id ON bot_detection_scores(user_id);
CREATE INDEX IF NOT EXISTS idx_bot_scores_total ON bot_detection_scores(total_score);
CREATE INDEX IF NOT EXISTS idx_bot_scores_created ON bot_detection_scores(created_at);

CREATE TABLE IF NOT EXISTS traffic_sources (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid REFERENCES users(id) ON DELETE CASCADE,
  referer text,
  utm_source text,
  utm_medium text,
  utm_campaign text,
  utm_term text,
  utm_content text,
  search_engine text,
  search_keyword text,
  is_direct boolean DEFAULT false,
  is_organic boolean DEFAULT false,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_traffic_sources_user_id ON traffic_sources(user_id);
CREATE INDEX IF NOT EXISTS idx_traffic_sources_utm_source ON traffic_sources(utm_source);

CREATE TABLE IF NOT EXISTS ip_whitelist (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ip_address text UNIQUE NOT NULL,
  reason text,
  added_by text,
  expires_at timestamptz,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ip_whitelist_ip ON ip_whitelist(ip_address);
CREATE INDEX IF NOT EXISTS idx_ip_whitelist_expires ON ip_whitelist(expires_at);

CREATE TABLE IF NOT EXISTS ip_blacklist (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ip_address text UNIQUE NOT NULL,
  reason text,
  added_by text,
  expires_at timestamptz,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ip_blacklist_ip ON ip_blacklist(ip_address);
CREATE INDEX IF NOT EXISTS idx_ip_blacklist_expires ON ip_blacklist(expires_at);

CREATE TABLE IF NOT EXISTS fingerprint_whitelist (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  fingerprint_hash text UNIQUE NOT NULL,
  reason text,
  added_by text,
  expires_at timestamptz,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_fingerprint_whitelist_hash ON fingerprint_whitelist(fingerprint_hash);
CREATE INDEX IF NOT EXISTS idx_fingerprint_whitelist_expires ON fingerprint_whitelist(expires_at);

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_fingerprints ENABLE ROW LEVEL SECURITY;
ALTER TABLE bot_detection_scores ENABLE ROW LEVEL SECURITY;
ALTER TABLE traffic_sources ENABLE ROW LEVEL SECURITY;
ALTER TABLE ip_whitelist ENABLE ROW LEVEL SECURITY;
ALTER TABLE ip_blacklist ENABLE ROW LEVEL SECURITY;
ALTER TABLE fingerprint_whitelist ENABLE ROW LEVEL SECURITY;
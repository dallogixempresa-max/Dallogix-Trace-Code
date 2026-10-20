ALTER TABLE limites_login
  ADD KEY idx_limites_login_window (window_started_at),
  ADD KEY idx_limites_login_blocked (blocked_until);

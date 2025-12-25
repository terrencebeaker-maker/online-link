-- ============================================================
-- MULTI-STATION SUPPORT SCHEMA FOR ENERGY APP
-- Compatible with existing schema - No conflicts
-- Supports 50+ gas stations with centralized management
-- 
-- IMPORTANT: shifts table (Day Shift, Night Shift) remains GLOBAL
--            and is shared across ALL stations
-- ============================================================

-- 1. STATIONS TABLE - Core station information
-- This is a NEW table that doesn't conflict with existing tables
CREATE TABLE IF NOT EXISTS public.stations (
    station_id SERIAL PRIMARY KEY,
    station_code VARCHAR(20) UNIQUE NOT NULL,           -- e.g., "STN001", "NRB-001"
    station_name VARCHAR(100) NOT NULL,                  -- e.g., "Mombasa Road Station"
    station_type VARCHAR(50) DEFAULT 'petrol_station',   -- 'petrol_station', 'charging_station', 'gas_station'
    
    -- Location Details
    physical_address VARCHAR(255),
    city VARCHAR(100),
    county VARCHAR(50),
    region VARCHAR(50),                                   -- e.g., "Central", "Coast", "Western"
    gps_latitude DECIMAL(10, 8),
    gps_longitude DECIMAL(11, 8),
    
    -- M-Pesa Configuration (Each station can have unique till)
    mpesa_till_number VARCHAR(20),
    mpesa_shortcode VARCHAR(20),
    mpesa_passkey VARCHAR(255),
    mpesa_consumer_key VARCHAR(100),
    mpesa_consumer_secret VARCHAR(100),
    
    -- Contact Information
    station_phone VARCHAR(20),
    station_email VARCHAR(100),
    manager_name VARCHAR(100),
    manager_phone VARCHAR(20),
    
    -- Operational Settings
    operating_hours_start TIME DEFAULT '06:00:00',
    operating_hours_end TIME DEFAULT '22:00:00',
    is_24_hours BOOLEAN DEFAULT FALSE,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_online BOOLEAN DEFAULT TRUE,
    last_sync_at TIMESTAMP WITH TIME ZONE,
    
    -- Audit
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_by UUID REFERENCES public.users(id)
);

-- ============================================================
-- NOTE: The existing 'shifts' table (Day Shift, Night Shift) 
-- remains UNCHANGED and GLOBAL across all stations!
-- 
-- Existing shifts table structure (DO NOT MODIFY):
-- CREATE TABLE public.shifts (
--   shift_id SERIAL PRIMARY KEY,
--   shift_name VARCHAR NOT NULL,
--   start_time VARCHAR,
--   end_time VARCHAR,
--   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );
--
-- The shifts are used by pump_shifts which WILL have station_id
-- to track which station each pump shift belongs to.
-- ============================================================

-- 2. USER-STATION ASSIGNMENTS (Many-to-Many with roles)
-- Links existing users (admins) to stations with role-based access
CREATE TABLE IF NOT EXISTS public.user_stations (
    id SERIAL PRIMARY KEY,
    
    -- Reference to existing users table (UUID)
    user_id UUID NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    
    -- Reference to new stations table
    station_id INTEGER NOT NULL REFERENCES public.stations(station_id) ON DELETE CASCADE,
    
    -- Role at this specific station
    station_role VARCHAR(30) DEFAULT 'attendant',        -- 'super_admin', 'station_admin', 'manager', 'supervisor', 'attendant'
    
    -- Permissions
    can_view_reports BOOLEAN DEFAULT FALSE,
    can_manage_pumps BOOLEAN DEFAULT FALSE,
    can_manage_users BOOLEAN DEFAULT FALSE,
    can_manage_shifts BOOLEAN DEFAULT FALSE,
    can_process_refunds BOOLEAN DEFAULT FALSE,
    
    -- Assignment details
    is_primary_station BOOLEAN DEFAULT FALSE,
    assigned_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    assigned_by UUID REFERENCES public.users(id),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    UNIQUE(user_id, station_id)
);

-- 3. ATTENDANT-STATION ASSIGNMENTS (For attendants using users_new table)
-- Links existing users_new (attendants) to stations
CREATE TABLE IF NOT EXISTS public.attendant_stations (
    id SERIAL PRIMARY KEY,
    
    -- Reference to existing users_new table (INTEGER)
    attendant_id INTEGER NOT NULL REFERENCES public.users_new(user_id) ON DELETE CASCADE,
    
    -- Reference to new stations table
    station_id INTEGER NOT NULL REFERENCES public.stations(station_id) ON DELETE CASCADE,
    
    -- Role at this specific station
    station_role VARCHAR(30) DEFAULT 'attendant',
    
    -- Permissions
    can_view_own_sales BOOLEAN DEFAULT TRUE,
    can_process_sales BOOLEAN DEFAULT TRUE,
    can_manage_shift BOOLEAN DEFAULT FALSE,
    
    -- Assignment details
    is_primary_station BOOLEAN DEFAULT FALSE,
    assigned_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    UNIQUE(attendant_id, station_id)
);

-- 4. Add station_id to existing tables (ALTER statements)
-- These safely add station_id column if it doesn't exist

-- Add to pumps table (pumps belong to a station)
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'pumps' 
                   AND column_name = 'station_id') THEN
        ALTER TABLE public.pumps ADD COLUMN station_id INTEGER REFERENCES public.stations(station_id);
        CREATE INDEX IF NOT EXISTS idx_pumps_station ON public.pumps(station_id);
    END IF;
END $$;

-- Add to pump_shifts table (pump shifts belong to a station)
-- NOTE: This uses the GLOBAL shifts table (Day/Night) but tracks which station
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'pump_shifts' 
                   AND column_name = 'station_id') THEN
        ALTER TABLE public.pump_shifts ADD COLUMN station_id INTEGER REFERENCES public.stations(station_id);
        CREATE INDEX IF NOT EXISTS idx_pump_shifts_station ON public.pump_shifts(station_id);
    END IF;
END $$;

-- Add to sales table (sales belong to a station)
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'sales' 
                   AND column_name = 'station_id') THEN
        ALTER TABLE public.sales ADD COLUMN station_id INTEGER REFERENCES public.stations(station_id);
        CREATE INDEX IF NOT EXISTS idx_sales_station ON public.sales(station_id);
    END IF;
END $$;

-- Add to mpesa_transactions table (transactions belong to a station)
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'mpesa_transactions' 
                   AND column_name = 'station_id') THEN
        ALTER TABLE public.mpesa_transactions ADD COLUMN station_id INTEGER REFERENCES public.stations(station_id);
        CREATE INDEX IF NOT EXISTS idx_mpesa_transactions_station ON public.mpesa_transactions(station_id);
    END IF;
END $$;

-- Add fcm_token to mpesa_transactions for push notifications
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'mpesa_transactions' 
                   AND column_name = 'fcm_token') THEN
        ALTER TABLE public.mpesa_transactions ADD COLUMN fcm_token TEXT;
    END IF;
END $$;

-- Add to inventory table (inventory belongs to a station)
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'inventory' 
                   AND column_name = 'station_id') THEN
        ALTER TABLE public.inventory ADD COLUMN station_id INTEGER REFERENCES public.stations(station_id);
        CREATE INDEX IF NOT EXISTS idx_inventory_station ON public.inventory(station_id);
    END IF;
END $$;

-- Add to fuel_prices table (fuel prices can vary per station)
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'fuel_prices' 
                   AND column_name = 'station_id') THEN
        ALTER TABLE public.fuel_prices ADD COLUMN station_id INTEGER REFERENCES public.stations(station_id);
        CREATE INDEX IF NOT EXISTS idx_fuel_prices_station ON public.fuel_prices(station_id);
    END IF;
END $$;

-- 5. STATION DAILY SUMMARIES (Pre-aggregated for fast dashboard loading)
CREATE TABLE IF NOT EXISTS public.station_daily_summaries (
    id SERIAL PRIMARY KEY,
    station_id INTEGER NOT NULL REFERENCES public.stations(station_id) ON DELETE CASCADE,
    summary_date DATE NOT NULL,
    
    -- Sales Metrics
    total_sales NUMERIC(12, 2) DEFAULT 0,
    total_transactions INTEGER DEFAULT 0,
    mpesa_sales NUMERIC(12, 2) DEFAULT 0,
    mpesa_transactions INTEGER DEFAULT 0,
    cash_sales NUMERIC(12, 2) DEFAULT 0,
    cash_transactions INTEGER DEFAULT 0,
    
    -- Fuel Metrics (liters)
    petrol_volume NUMERIC(10, 2) DEFAULT 0,
    diesel_volume NUMERIC(10, 2) DEFAULT 0,
    premium_volume NUMERIC(10, 2) DEFAULT 0,
    
    -- Shift Metrics (uses global Day/Night shifts)
    day_shift_sales NUMERIC(12, 2) DEFAULT 0,
    night_shift_sales NUMERIC(12, 2) DEFAULT 0,
    day_shift_transactions INTEGER DEFAULT 0,
    night_shift_transactions INTEGER DEFAULT 0,
    
    -- Operational Metrics
    active_pumps INTEGER DEFAULT 0,
    active_attendants INTEGER DEFAULT 0,
    shifts_opened INTEGER DEFAULT 0,
    shifts_closed INTEGER DEFAULT 0,
    
    -- Timestamps
    calculated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    UNIQUE(station_id, summary_date)
);

-- 6. FCM TOKENS TABLE (For Push Notifications)
CREATE TABLE IF NOT EXISTS public.fcm_tokens (
    id SERIAL PRIMARY KEY,
    
    -- Can link to either users table (admin) or users_new (attendant)
    user_id UUID REFERENCES public.users(id),
    attendant_id INTEGER REFERENCES public.users_new(user_id),
    
    station_id INTEGER REFERENCES public.stations(station_id),
    
    -- FCM Details
    fcm_token TEXT NOT NULL,
    device_id VARCHAR(100),
    device_type VARCHAR(20) DEFAULT 'android',
    device_model VARCHAR(100),
    app_version VARCHAR(20),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    last_used_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Ensure either user_id or attendant_id is set
    CONSTRAINT fcm_tokens_user_check CHECK (user_id IS NOT NULL OR attendant_id IS NOT NULL)
);

-- Create unique index that handles NULL values correctly
CREATE UNIQUE INDEX IF NOT EXISTS idx_fcm_tokens_user_device 
ON public.fcm_tokens(COALESCE(user_id::text, ''), COALESCE(attendant_id::text, ''), device_id);

-- 7. NOTIFICATION LOGS (Track sent notifications)
CREATE TABLE IF NOT EXISTS public.notification_logs (
    id SERIAL PRIMARY KEY,
    
    user_id UUID REFERENCES public.users(id),
    attendant_id INTEGER REFERENCES public.users_new(user_id),
    station_id INTEGER REFERENCES public.stations(station_id),
    
    -- Notification Details
    notification_type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    data JSONB,
    
    -- Reference
    reference_type VARCHAR(50),
    reference_id VARCHAR(100),
    
    -- Status
    sent_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    delivered_at TIMESTAMP WITH TIME ZONE,
    read_at TIMESTAMP WITH TIME ZONE,
    fcm_message_id VARCHAR(200),
    fcm_error TEXT
);

-- 8. ACCESS TOKEN CACHE (For M-Pesa speed optimization)
CREATE TABLE IF NOT EXISTS public.mpesa_token_cache (
    id SERIAL PRIMARY KEY,
    station_id INTEGER REFERENCES public.stations(station_id),
    
    -- Token Details
    access_token TEXT NOT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- Metadata
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Create unique index for station_id (handling NULL for global token)
CREATE UNIQUE INDEX IF NOT EXISTS idx_mpesa_token_cache_station 
ON public.mpesa_token_cache(COALESCE(station_id, 0));

-- 9. Additional indexes for performance
CREATE INDEX IF NOT EXISTS idx_stations_active ON public.stations(is_active);
CREATE INDEX IF NOT EXISTS idx_stations_code ON public.stations(station_code);
CREATE INDEX IF NOT EXISTS idx_user_stations_user ON public.user_stations(user_id);
CREATE INDEX IF NOT EXISTS idx_user_stations_station ON public.user_stations(station_id);
CREATE INDEX IF NOT EXISTS idx_attendant_stations_attendant ON public.attendant_stations(attendant_id);
CREATE INDEX IF NOT EXISTS idx_attendant_stations_station ON public.attendant_stations(station_id);
CREATE INDEX IF NOT EXISTS idx_fcm_tokens_user ON public.fcm_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_fcm_tokens_attendant ON public.fcm_tokens(attendant_id);
CREATE INDEX IF NOT EXISTS idx_notification_logs_user ON public.notification_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_station_summaries_date ON public.station_daily_summaries(summary_date);

-- 10. INSERT DEFAULT STATION (for migration of existing data)
INSERT INTO public.stations (station_id, station_code, station_name, city, county, is_active)
VALUES (1, 'DEFAULT-001', 'Main Station', 'Nairobi', 'Nairobi', TRUE)
ON CONFLICT (station_id) DO NOTHING;

-- Update sequence to start from 2 for new stations
SELECT setval('stations_station_id_seq', (SELECT COALESCE(MAX(station_id), 1) FROM public.stations));

-- 11. Update existing records to belong to default station (only if station_id is NULL)
UPDATE public.pumps SET station_id = 1 WHERE station_id IS NULL;
UPDATE public.pump_shifts SET station_id = 1 WHERE station_id IS NULL;
UPDATE public.sales SET station_id = 1 WHERE station_id IS NULL;
UPDATE public.mpesa_transactions SET station_id = 1 WHERE station_id IS NULL;
UPDATE public.inventory SET station_id = 1 WHERE station_id IS NULL;
UPDATE public.fuel_prices SET station_id = 1 WHERE station_id IS NULL;

-- 12. HELPER FUNCTIONS

-- Drop existing functions first to avoid return type mismatch errors
DROP FUNCTION IF EXISTS public.get_user_accessible_stations(UUID);
DROP FUNCTION IF EXISTS public.get_attendant_accessible_stations(INTEGER);
DROP FUNCTION IF EXISTS public.get_station_shift_summary(INTEGER, DATE);
DROP FUNCTION IF EXISTS public.get_multi_station_sales_summary(INTEGER[], DATE, DATE);
DROP FUNCTION IF EXISTS public.get_all_stations_today_summary();

-- Function to get all stations accessible by a user (from users table - UUID)
CREATE OR REPLACE FUNCTION public.get_user_accessible_stations(p_user_id UUID)
RETURNS TABLE (
    station_id INTEGER,
    station_code VARCHAR,
    station_name VARCHAR,
    station_role VARCHAR,
    is_primary BOOLEAN
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        s.station_id,
        s.station_code,
        s.station_name,
        us.station_role,
        us.is_primary_station
    FROM public.stations s
    INNER JOIN public.user_stations us ON s.station_id = us.station_id
    WHERE us.user_id = p_user_id 
      AND us.is_active = TRUE 
      AND s.is_active = TRUE
    ORDER BY us.is_primary_station DESC, s.station_name ASC;
END;
$$ LANGUAGE plpgsql;

-- Function to get all stations accessible by an attendant (from users_new table - INTEGER)
CREATE OR REPLACE FUNCTION public.get_attendant_accessible_stations(p_attendant_id INTEGER)
RETURNS TABLE (
    station_id INTEGER,
    station_code VARCHAR,
    station_name VARCHAR,
    station_role VARCHAR,
    is_primary BOOLEAN
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        s.station_id,
        s.station_code,
        s.station_name,
        ast.station_role,
        ast.is_primary_station
    FROM public.stations s
    INNER JOIN public.attendant_stations ast ON s.station_id = ast.station_id
    WHERE ast.attendant_id = p_attendant_id 
      AND ast.is_active = TRUE 
      AND s.is_active = TRUE
    ORDER BY ast.is_primary_station DESC, s.station_name ASC;
END;
$$ LANGUAGE plpgsql;

-- Function to get sales summary by shift type (Day/Night) for a station
CREATE OR REPLACE FUNCTION public.get_station_shift_summary(
    p_station_id INTEGER,
    p_date DATE
)
RETURNS TABLE (
    shift_id INTEGER,
    shift_name VARCHAR,
    total_sales NUMERIC,
    transaction_count BIGINT,
    mpesa_sales NUMERIC
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        sh.shift_id,
        sh.shift_name,
        COALESCE(SUM(s.amount), 0)::NUMERIC as total_sales,
        COUNT(s.sale_id) as transaction_count,
        COALESCE(SUM(CASE WHEN s.mpesa_receipt_number IS NOT NULL THEN s.amount ELSE 0 END), 0)::NUMERIC as mpesa_sales
    FROM public.shifts sh
    LEFT JOIN public.pump_shifts ps ON sh.shift_id = ps.shift_id 
        AND ps.station_id = p_station_id
        AND ps.opening_time::DATE = p_date
    LEFT JOIN public.sales s ON ps.pump_shift_id = s.pump_shift_id
    GROUP BY sh.shift_id, sh.shift_name
    ORDER BY sh.shift_id;
END;
$$ LANGUAGE plpgsql;

-- Function to aggregate sales across multiple stations
CREATE OR REPLACE FUNCTION public.get_multi_station_sales_summary(
    p_station_ids INTEGER[],
    p_start_date DATE,
    p_end_date DATE
)
RETURNS TABLE (
    station_id INTEGER,
    station_name VARCHAR,
    total_sales NUMERIC,
    transaction_count BIGINT,
    mpesa_sales NUMERIC,
    mpesa_count BIGINT,
    day_shift_sales NUMERIC,
    night_shift_sales NUMERIC
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        st.station_id,
        st.station_name,
        COALESCE(SUM(sa.amount), 0)::NUMERIC as total_sales,
        COUNT(sa.sale_id) as transaction_count,
        COALESCE(SUM(CASE WHEN sa.mpesa_receipt_number IS NOT NULL THEN sa.amount ELSE 0 END), 0)::NUMERIC as mpesa_sales,
        COUNT(CASE WHEN sa.mpesa_receipt_number IS NOT NULL THEN 1 END) as mpesa_count,
        COALESCE(SUM(CASE WHEN sh.shift_name ILIKE '%day%' THEN sa.amount ELSE 0 END), 0)::NUMERIC as day_shift_sales,
        COALESCE(SUM(CASE WHEN sh.shift_name ILIKE '%night%' THEN sa.amount ELSE 0 END), 0)::NUMERIC as night_shift_sales
    FROM public.stations st
    LEFT JOIN public.sales sa ON st.station_id = sa.station_id 
        AND sa.created_at::DATE BETWEEN p_start_date AND p_end_date
    LEFT JOIN public.pump_shifts ps ON sa.pump_shift_id = ps.pump_shift_id
    LEFT JOIN public.shifts sh ON ps.shift_id = sh.shift_id
    WHERE st.station_id = ANY(p_station_ids)
    GROUP BY st.station_id, st.station_name
    ORDER BY total_sales DESC;
END;
$$ LANGUAGE plpgsql;

-- Function to get today's sales for all stations (for dashboard)
CREATE OR REPLACE FUNCTION public.get_all_stations_today_summary()
RETURNS TABLE (
    station_id INTEGER,
    station_code VARCHAR,
    station_name VARCHAR,
    city VARCHAR,
    pump_count BIGINT,
    active_day_shifts BIGINT,
    active_night_shifts BIGINT,
    today_sales NUMERIC,
    today_transactions BIGINT,
    mpesa_sales NUMERIC,
    is_online BOOLEAN
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        s.station_id,
        s.station_code,
        s.station_name,
        s.city,
        (SELECT COUNT(*) FROM public.pumps p WHERE p.station_id = s.station_id AND p.is_active = TRUE) as pump_count,
        (SELECT COUNT(*) FROM public.pump_shifts ps 
         INNER JOIN public.shifts sh ON ps.shift_id = sh.shift_id
         WHERE ps.station_id = s.station_id AND ps.is_closed = FALSE AND sh.shift_name ILIKE '%day%') as active_day_shifts,
        (SELECT COUNT(*) FROM public.pump_shifts ps 
         INNER JOIN public.shifts sh ON ps.shift_id = sh.shift_id
         WHERE ps.station_id = s.station_id AND ps.is_closed = FALSE AND sh.shift_name ILIKE '%night%') as active_night_shifts,
        COALESCE((SELECT SUM(amount) FROM public.sales sa WHERE sa.station_id = s.station_id AND sa.created_at::DATE = CURRENT_DATE), 0)::NUMERIC as today_sales,
        (SELECT COUNT(*) FROM public.sales sa WHERE sa.station_id = s.station_id AND sa.created_at::DATE = CURRENT_DATE) as today_transactions,
        COALESCE((SELECT SUM(amount) FROM public.sales sa WHERE sa.station_id = s.station_id AND sa.created_at::DATE = CURRENT_DATE AND sa.mpesa_receipt_number IS NOT NULL), 0)::NUMERIC as mpesa_sales,
        s.is_online
    FROM public.stations s
    WHERE s.is_active = TRUE
    ORDER BY today_sales DESC;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- COMMENTS
-- ============================================================
COMMENT ON TABLE public.stations IS 'Multi-station support - stores information about each gas station location';
COMMENT ON TABLE public.user_stations IS 'Many-to-many relationship between users (UUID) and stations with role-based access';
COMMENT ON TABLE public.attendant_stations IS 'Many-to-many relationship between attendants (users_new) and stations';
COMMENT ON TABLE public.fcm_tokens IS 'Firebase Cloud Messaging tokens for push notifications';
COMMENT ON TABLE public.mpesa_token_cache IS 'Cache for M-Pesa access tokens to speed up STK Push requests';

-- ============================================================
-- IMPORTANT NOTES:
-- 1. The 'shifts' table (Day Shift, Night Shift) remains GLOBAL 
--    and is NOT modified. All stations share the same shift types.
-- 2. Each pump_shift record now has a station_id to track which
--    station the actual shift instance belongs to.
-- 3. Both 'users' (UUID - admins) and 'users_new' (INTEGER - attendants)
--    are supported for station assignments.
-- ============================================================

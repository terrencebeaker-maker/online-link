-- ============================================================
-- LUBRICANTS AND REFILLS TABLES
-- For managing lubricant products and fuel refill/delivery tracking
-- ============================================================

-- 1. LUBRICANTS TABLE - Product catalog for lubricants
CREATE TABLE IF NOT EXISTS public.lubricants (
    lubricant_id SERIAL PRIMARY KEY,
    lubricant_code VARCHAR(20) NOT NULL,           -- Auto: LB01, LB02
    lubricant_name VARCHAR(100) NOT NULL,          -- e.g., "Shell Helix 5W-30"
    brand VARCHAR(50),                              -- e.g., "Shell", "Castrol", "Total"
    category VARCHAR(50) DEFAULT 'engine_oil',      -- 'engine_oil', 'gear_oil', 'brake_fluid', 'coolant', 'grease'
    size_ml INTEGER DEFAULT 1000,                   -- Size in milliliters (1000ml = 1L)
    unit VARCHAR(20) DEFAULT 'liters',              -- 'liters', 'ml', 'pack'
    buying_price NUMERIC(10, 2) DEFAULT 0,
    selling_price NUMERIC(10, 2) DEFAULT 0,
    stock_quantity INTEGER DEFAULT 0,
    reorder_level INTEGER DEFAULT 5,
    color_code VARCHAR(20) DEFAULT '#3B82F6',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. LUBRICANT SALES TABLE - Track lubricant sales
CREATE TABLE IF NOT EXISTS public.lubricant_sales (
    sale_id SERIAL PRIMARY KEY,
    station_id INTEGER REFERENCES public.stations(station_id),
    lubricant_id INTEGER REFERENCES public.lubricants(lubricant_id),
    attendant_id INTEGER REFERENCES public.users_new(user_id),
    quantity INTEGER NOT NULL DEFAULT 1,
    unit_price NUMERIC(10, 2) NOT NULL,
    total_amount NUMERIC(10, 2) NOT NULL,
    payment_method VARCHAR(20) DEFAULT 'cash',       -- 'cash', 'mpesa'
    mpesa_receipt VARCHAR(50),
    sale_time TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 3. FUEL REFILLS TABLE - Track fuel deliveries to stations
CREATE TABLE IF NOT EXISTS public.fuel_refills (
    refill_id SERIAL PRIMARY KEY,
    refill_code VARCHAR(30) NOT NULL,               -- Auto: RF-00001
    station_id INTEGER REFERENCES public.stations(station_id),
    fuel_type_id INTEGER REFERENCES public.fuel_types(fuel_type_id),
    pump_id INTEGER REFERENCES public.pumps(pump_id),
    supplier_name VARCHAR(100),
    delivery_note_no VARCHAR(50),
    quantity_liters NUMERIC(12, 2) NOT NULL,
    cost_per_liter NUMERIC(10, 2),
    total_cost NUMERIC(12, 2),
    meter_reading_before NUMERIC(12, 2),
    meter_reading_after NUMERIC(12, 2),
    received_by INTEGER REFERENCES public.users_new(user_id),
    delivery_date TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 4. INSERT DEFAULT LUBRICANTS
INSERT INTO public.lubricants (lubricant_code, lubricant_name, brand, category, size_ml, selling_price, stock_quantity, color_code)
VALUES 
    ('LB01', 'Shell Helix HX7 5W-40', 'Shell', 'engine_oil', 4000, 3500.00, 20, '#F59E0B'),
    ('LB02', 'Castrol EDGE 5W-30', 'Castrol', 'engine_oil', 4000, 4200.00, 15, '#10B981'),
    ('LB03', 'Total Quartz 9000 5W-40', 'Total', 'engine_oil', 4000, 3200.00, 25, '#EF4444'),
    ('LB04', 'Mobil 1 Synthetic 0W-40', 'Mobil', 'engine_oil', 4000, 5000.00, 10, '#3B82F6'),
    ('LB05', 'Shell Spirax S4 ATX', 'Shell', 'gear_oil', 1000, 1200.00, 30, '#8B5CF6'),
    ('LB06', 'DOT 4 Brake Fluid', 'Generic', 'brake_fluid', 500, 450.00, 40, '#EC4899'),
    ('LB07', 'Coolant Green 50%', 'Generic', 'coolant', 4000, 800.00, 35, '#06B6D4')
ON CONFLICT DO NOTHING;

-- 5. INDEXES for performance
CREATE INDEX IF NOT EXISTS idx_lubricant_sales_station ON public.lubricant_sales(station_id);
CREATE INDEX IF NOT EXISTS idx_lubricant_sales_date ON public.lubricant_sales(sale_time);
CREATE INDEX IF NOT EXISTS idx_fuel_refills_station ON public.fuel_refills(station_id);
CREATE INDEX IF NOT EXISTS idx_fuel_refills_date ON public.fuel_refills(delivery_date);

-- 6. COMMENTS
COMMENT ON TABLE public.lubricants IS 'Catalog of lubricant products (engine oil, gear oil, brake fluid, etc.)';
COMMENT ON TABLE public.lubricant_sales IS 'Track individual lubricant sales transactions';
COMMENT ON TABLE public.fuel_refills IS 'Track fuel deliveries/refills to stations';
